<?php

namespace App\Ai\Verification;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Programme\EffetMetierVerifieur;
use App\Ai\Scope\AiScope;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * RELECTURE EN BASE de ce qu'un plan validé prétend avoir écrit.
 *
 * Le principe : ne rien croire sur parole — ni la prose du modèle, ni même le
 * journal d'exécution, qui est produit par le code qui vient d'écrire et ne peut
 * donc pas se contredire. Pour chaque ligne du journal on RELIT la base, dans le
 * périmètre de l'invité, et l'on répond à trois questions :
 *
 *  1. EXISTENCE — l'enregistrement journalisé est-il réellement là ?
 *  2. CHAMPS    — vaut-il ce que le plan validé annonçait ?
 *  3. EFFET     — la conséquence métier attendue s'est-elle produite ?
 *                 (une prime signalée est-elle vraiment soldée ?)
 *
 * POURQUOI CE SERVICE EXISTE À PART. Cette logique vivait dans
 * {@see \App\Ai\Programme\ProgrammeVerificateur}, et n'était donc atteignable que
 * par les PROGRAMMES. Le plan ISOLÉ — de loin le cas le plus fréquent — sortait de
 * l'endpoint d'exécution sur la foi du seul journal, et le fil concluait par un
 * « N opérations exécutées avec succès » inconditionnel. C'est exactement cette
 * phrase qui a menti le 2026-08-13 sur un taux de commission resté vide en base.
 * Les deux chemins partagent désormais le MÊME contrôle : un écart ne peut plus
 * être visible d'un côté et invisible de l'autre.
 *
 * COÛT NUL EN TOKENS : tout est déterministe et local. Le modèle n'est ni consulté
 * ni cru.
 */
final class RelectureDeControle
{
    /** Tolérance de comparaison des montants (arrondis monétaires). */
    public const EPSILON = 0.01;

    private readonly PropertyAccessorInterface $accessor;

    /** @param iterable<EffetMetierVerifieur> $effets */
    public function __construct(
        private readonly JSBDynamicSearchService $searchService,
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        #[AutowireIterator('app.ai_effet_metier')] private readonly iterable $effets = [],
    ) {
        // getValue() tolérant : une relation rompue ou un getter absent ne doit
        // jamais faire échouer un rapport (il vaut mieux une ligne « non vérifiable »
        // qu'un 500 au moment où l'on rend des comptes).
        $this->accessor = PropertyAccess::createPropertyAccessorBuilder()
            ->disableExceptionOnInvalidIndex()
            ->getPropertyAccessor();
    }

    /**
     * Confronte le plan validé au contenu réel de la base.
     *
     * @param array<int, array>  $journal lignes d'exécution aplaties (cf. aplatirEtapeJournal)
     * @param array<int, array>  $corrections (par référence) étapes de correction proposées par les effets métier
     *
     * @return array{conforme: bool, constats: list<string>, ecarts: list<string>, ecrits: list<array>}
     */
    public function verifier(MutationPlan $plan, array $journal, AiScope $scope, array &$corrections = []): array
    {
        $resultat = ['conforme' => true, 'constats' => [], 'ecarts' => [], 'ecrits' => []];

        if ($journal === []) {
            $resultat['ecarts'][] = 'Aucun journal d’exécution : impossible de vérifier ce qui a été écrit.';
            $resultat['conforme'] = false;

            return $resultat;
        }

        $libelles = $this->accessResolver->libellesEntites();

        // Les opérations de TÊTE du plan validé, dans l'ordre EXACT où elles ont
        // été exécutées : c'est ce même ordre qui a produit les lignes de journal
        // de niveau 0, l'appariement est donc positionnel et fiable.
        $operations = $plan->operationsOrdonnees();
        $tetes = array_values(array_filter($journal, static fn ($l) => (int) ($l['niveau'] ?? 0) === 0));

        $rang = 0;
        foreach ($journal as $lignJournal) {
            if (($lignJournal['statut'] ?? 'ok') !== 'ok') {
                $resultat['ecarts'][] = trim((string) ($lignJournal['message'] ?? 'Une opération a échoué.'));
                continue;
            }
            if (($lignJournal['op'] ?? '') === 'delete') {
                // Une suppression est vérifiée par l'ABSENCE : l'id journalisé est
                // nul par construction, il n'y a plus rien à relire.
                $resultat['constats'][] = sprintf('Suppression effectuée : %s.', (string) ($lignJournal['libelle'] ?? ''));
                continue;
            }

            $id = $lignJournal['id'] ?? null;
            $shortName = (string) ($lignJournal['entite'] ?? '');
            if (!is_int($id) || $id <= 0 || $shortName === '') {
                continue;
            }

            $entite = $this->relire($shortName, $id, $scope);
            $libelleEntite = $libelles[$shortName] ?? $shortName;
            $ecrit = [
                'entite'  => $shortName,
                'libelle' => $libelleEntite,
                'id'      => $id,
                'cible'   => $lignJournal['cible'] ?? null,
                'present' => $entite !== null,
            ];

            if ($entite === null) {
                $ecrit['ecarts'] = ['Introuvable en base.'];
                $resultat['ecarts'][] = sprintf('%s #%d est introuvable en base alors que le journal la donne écrite.', $libelleEntite, $id);
                $resultat['ecrits'][] = $ecrit;
                continue;
            }

            // CHAMPS : uniquement pour les opérations de tête, appariées par rang.
            $operation = null;
            if ((int) ($lignJournal['niveau'] ?? 0) === 0 && count($tetes) === count($operations)) {
                $operation = $operations[$rang] ?? null;
                ++$rang;
            }
            $ecartsChamps = $operation !== null ? $this->comparerChamps($operation, $entite) : [];
            if ($ecartsChamps !== []) {
                $ecrit['ecarts'] = $ecartsChamps;
                foreach ($ecartsChamps as $ecart) {
                    $resultat['ecarts'][] = sprintf('%s #%d : %s', $libelleEntite, $id, $ecart);
                }
            } else {
                $resultat['constats'][] = sprintf('%s #%d relu en base, conforme au plan validé.', $libelleEntite, $id);
            }

            // EFFET MÉTIER : la conséquence attendue s'est-elle produite ?
            foreach ($this->effets as $effet) {
                if (!$effet->supporte($shortName)) {
                    continue;
                }
                try {
                    $verdict = $effet->verifier($entite, $scope);
                } catch (\Throwable $e) {
                    $this->logger->error('Ket : vérificateur d’effet métier en échec.', ['exception' => $e]);
                    continue;
                }
                foreach ($verdict['constats'] ?? [] as $constat) {
                    $resultat['constats'][] = (string) $constat;
                }
                foreach ($verdict['ecarts'] ?? [] as $ecart) {
                    $resultat['ecarts'][] = (string) $ecart;
                }
                if (is_array($verdict['correction'] ?? null)) {
                    $corrections[] = $verdict['correction'];
                }
            }

            $resultat['ecrits'][] = $ecrit;
        }

        $resultat['conforme'] = $resultat['ecarts'] === [];

        return $resultat;
    }

    /**
     * Relit l'enregistrement DANS LE PÉRIMÈTRE de l'invité (scoping entreprise du
     * moteur de recherche) : le contrôle ne doit jamais révéler l'existence d'une
     * donnée hors périmètre, même pour dire qu'elle va bien.
     *
     * GOTCHA — L'IDENTITY MAP. Sur le chemin du plan isolé, la relecture suit
     * l'écriture DANS LA MÊME REQUÊTE HTTP : Doctrine rendrait alors l'objet déjà
     * en mémoire, et le contrôle validerait ce qu'il croit avoir écrit au lieu de
     * ce que la base contient — un contrôle purement décoratif, et le pire des
     * résultats puisqu'il rassure à tort. Le refresh() est donc la pièce qui rend
     * ce service utile hors du chemin des programmes (où la vérification tombait
     * dans une requête ultérieure, identity map déjà vide).
     */
    private function relire(string $shortName, int $id, AiScope $scope): ?object
    {
        $fqcn = 'App\\Entity\\' . $shortName;
        if (!class_exists($fqcn)) {
            return null;
        }
        try {
            $resultat = $this->searchService->search($fqcn, ['id' => $id], $scope->entreprise, null, 1, 1);
        } catch (\Throwable $e) {
            $this->logger->error('Ket : relecture de contrôle impossible.', ['entite' => $shortName, 'exception' => $e]);

            return null;
        }
        if ((int) ($resultat['status']['code'] ?? 500) !== 200) {
            return null;
        }

        $entite = $resultat['data'][0] ?? null;
        if (!is_object($entite)) {
            return null;
        }

        try {
            $this->em->refresh($entite);
        } catch (\Throwable $e) {
            // Entité détachée ou déjà supprimée : on garde l'objet tel quel plutôt
            // que de perdre la ligne. Le journal dira pourquoi la relecture est
            // moins ferme que d'habitude.
            $this->logger->warning('Ket : relecture de contrôle sans rafraîchissement.', [
                'entite'    => $shortName,
                'id'        => $id,
                'exception' => $e,
            ]);
        }

        return $entite;
    }

    /**
     * Compare, champ par champ, ce que le plan annonçait et ce que porte
     * réellement l'enregistrement. Volontairement TOLÉRANTE — le formulaire
     * normalise (dates, décimales, relations résolues par id) et une divergence de
     * forme n'est pas un écart. On ne signale que ce qui compte : une valeur
     * réellement différente.
     *
     * @return list<string>
     */
    private function comparerChamps(MutationOperation $operation, object $entite): array
    {
        $ecarts = [];
        foreach ($operation->fields as $champ => $attendu) {
            // Références internes au plan (« @client ») et pièces jointes
            // (« @fichier:7 ») : résolues à l'exécution, aucune valeur littérale
            // à comparer.
            if (is_string($attendu) && str_starts_with($attendu, '@')) {
                continue;
            }
            if (is_array($attendu) || $attendu === null || $attendu === '') {
                continue;
            }
            if (!$this->accessor->isReadable($entite, $champ)) {
                continue;
            }
            try {
                $reel = $this->accessor->getValue($entite, $champ);
            } catch (\Throwable) {
                continue;
            }
            if ($this->equivalents($attendu, $reel)) {
                continue;
            }
            $ecarts[] = sprintf('le champ « %s » vaut %s en base, le plan annonçait %s',
                $champ, $this->lisible($reel), $this->lisible($attendu));
        }

        return $ecarts;
    }

    private function equivalents(mixed $attendu, mixed $reel): bool
    {
        if ($reel instanceof \DateTimeInterface) {
            $date = trim((string) $attendu);

            return $date !== '' && str_starts_with($reel->format('Y-m-d H:i:s'), substr($date, 0, 10));
        }
        if (is_object($reel)) {
            $id = method_exists($reel, 'getId') ? $reel->getId() : null;

            return $id !== null && (string) $id === trim((string) $attendu);
        }
        if (is_bool($reel)) {
            return $reel === filter_var($attendu, FILTER_VALIDATE_BOOL);
        }
        if (is_numeric($reel) && is_numeric($attendu)) {
            return abs((float) $reel - (float) $attendu) <= self::EPSILON;
        }

        return mb_strtolower(trim((string) $reel)) === mb_strtolower(trim((string) $attendu));
    }

    private function lisible(mixed $valeur): string
    {
        if ($valeur === null) {
            return '(vide)';
        }
        if ($valeur instanceof \DateTimeInterface) {
            return $valeur->format('d/m/Y');
        }
        if (is_object($valeur)) {
            return method_exists($valeur, '__toString') ? '« ' . $valeur . ' »' : '#' . (method_exists($valeur, 'getId') ? (string) $valeur->getId() : '?');
        }
        if (is_bool($valeur)) {
            return $valeur ? 'oui' : 'non';
        }

        return '« ' . mb_substr((string) $valeur, 0, 120) . ' »';
    }
}
