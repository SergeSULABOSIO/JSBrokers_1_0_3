<?php

namespace App\Ai\Programme;

use App\Ai\Mutation\MutationOperation;
use App\Ai\Mutation\MutationPlan;
use App\Ai\Scope\AiScope;
use App\Entity\AssistantProgramme;
use App\Entity\AssistantProgrammeEtape;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * ÉTAT DES LIEUX VÉRIFIÉ EN BASE d'un programme terminé — le rapport final.
 *
 * Le principe : ne rien croire sur parole, ni la prose du modèle, ni même le
 * journal d'exécution. Pour chaque étape exécutée on RELIT la base, ligne par
 * ligne, dans le périmètre de l'invité :
 *
 *  1. EXISTENCE  — l'enregistrement journalisé est-il réellement là ?
 *  2. CHAMPS     — vaut-il ce que le plan validé annonçait ?
 *  3. EFFET      — la conséquence métier attendue s'est-elle produite ?
 *                  (une prime signalée est-elle vraiment soldée ?)
 *  4. OMISSIONS  — quelles étapes n'ont PAS été exécutées, et pourquoi ?
 *
 * Chaque écart repéré peut porter une CORRECTION prête à devenir l'étape d'un
 * nouveau programme, que l'utilisateur validera comme les autres.
 */
final class ProgrammeVerificateur
{
    /** Tolérance de comparaison des montants (arrondis monétaires). */
    private const EPSILON = 0.01;

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
     * Établit le rapport. Le résultat est un tableau sérialisable, stocké sur le
     * programme : il doit survivre au rechargement de page et rester opposable.
     *
     * @return array<string, mixed>
     */
    public function verifier(AssistantProgramme $programme, AiScope $scope): array
    {
        $libelles = $this->accessResolver->libellesEntites();

        $etapes = [];
        $corrections = [];
        $compte = ['executee' => 0, 'annulee' => 0, 'impossible' => 0, 'echec' => 0, 'en_attente' => 0, 'proposee' => 0];

        foreach ($programme->getEtapes() as $etape) {
            $statut = $etape->getStatut();
            $compte[$statut] = ($compte[$statut] ?? 0) + 1;

            $ligne = [
                'reference' => (string) $etape->getReference(),
                'position'  => $etape->getOrdre(),
                'libelle'   => (string) $etape->getLibelle(),
                'outil'     => (string) $etape->getOutil(),
                'statut'    => $statut,
                'motif'     => $etape->getErreur(),
                'ecrits'    => [],
                'constats'  => [],
                'ecarts'    => [],
            ];

            if ($statut === AssistantProgrammeEtape::STATUT_EXECUTEE) {
                $ligne = $this->verifierEtapeExecutee($etape, $scope, $libelles, $ligne, $corrections);
            } elseif ($statut !== AssistantProgrammeEtape::STATUT_ANNULEE) {
                // Une étape non exécutée et non refusée est une OMISSION : elle
                // devait être faite et ne l'a pas été. Elle entre telle quelle
                // dans les corrections proposées.
                if ($statut !== AssistantProgrammeEtape::STATUT_PROPOSEE) {
                    $ligne['ecarts'][] = 'Cette étape n’a pas été exécutée.';
                    $corrections[] = [
                        'outil'     => (string) $etape->getOutil(),
                        'libelle'   => (string) $etape->getLibelle(),
                        'arguments' => $etape->getArguments(),
                    ];
                }
            }

            $etape->setVerification(['constats' => $ligne['constats'], 'ecarts' => $ligne['ecarts'], 'ecrits' => $ligne['ecrits']]);
            $etapes[] = $ligne;
        }

        $ecarts = [];
        foreach ($etapes as $ligne) {
            foreach ($ligne['ecarts'] as $ecart) {
                $ecarts[] = sprintf('%s — %s', $ligne['reference'], $ecart);
            }
        }

        $rapport = [
            'reference'   => (string) $programme->getReference(),
            'objectif'    => (string) $programme->getObjectif(),
            'statut'      => $programme->getStatut(),
            'genereLe'    => (new \DateTimeImmutable('now'))->format(\DateTimeImmutable::ATOM),
            'total'       => $programme->nbEtapes(),
            'compte'      => $compte,
            'conforme'    => $ecarts === [],
            'etapes'      => $etapes,
            'ecarts'      => $ecarts,
            'corrections' => $this->dedupliquer($corrections),
        ];

        $programme->setRapport($rapport);
        $this->em->flush();

        return $rapport;
    }

    /**
     * Relecture en base d'une étape exécutée : existence, champs, effet métier.
     *
     * @param array<string, string> $libelles
     * @param array<string, mixed>  $ligne
     * @param array<int, array>     $corrections (par référence)
     *
     * @return array<string, mixed>
     */
    private function verifierEtapeExecutee(
        AssistantProgrammeEtape $etape,
        AiScope $scope,
        array $libelles,
        array $ligne,
        array &$corrections,
    ): array {
        $journal = $etape->getJournal() ?? [];
        if ($journal === []) {
            $ligne['ecarts'][] = 'Aucun journal d’exécution : impossible de vérifier ce qui a été écrit.';

            return $ligne;
        }

        // Les opérations de TÊTE du plan validé, dans l'ordre EXACT où elles ont
        // été exécutées : c'est ce même ordre qui a produit les lignes de journal
        // de niveau 0, l'appariement est donc positionnel et fiable.
        $meta = $etape->getMessage()?->getMeta() ?? [];
        $planStocke = $meta['mutationPlan']['plan'] ?? null;
        $operations = is_array($planStocke) ? MutationPlan::fromArray($planStocke)->operationsOrdonnees() : [];
        $tetes = array_values(array_filter($journal, static fn ($l) => (int) ($l['niveau'] ?? 0) === 0));

        $rang = 0;
        foreach ($journal as $lignJournal) {
            if (($lignJournal['statut'] ?? 'ok') !== 'ok') {
                $ligne['ecarts'][] = trim((string) ($lignJournal['message'] ?? 'Une opération a échoué.'));
                continue;
            }
            if (($lignJournal['op'] ?? '') === 'delete') {
                // Une suppression est vérifiée par l'ABSENCE : l'id journalisé est
                // nul par construction, il n'y a plus rien à relire.
                $ligne['constats'][] = sprintf('Suppression effectuée : %s.', (string) ($lignJournal['libelle'] ?? ''));
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
                $ligne['ecarts'][] = sprintf('%s #%d est introuvable en base alors que le journal la donne écrite.', $libelleEntite, $id);
                $ligne['ecrits'][] = $ecrit;
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
                    $ligne['ecarts'][] = sprintf('%s #%d : %s', $libelleEntite, $id, $ecart);
                }
            } else {
                $ligne['constats'][] = sprintf('%s #%d relu en base, conforme au plan validé.', $libelleEntite, $id);
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
                    $ligne['constats'][] = (string) $constat;
                }
                foreach ($verdict['ecarts'] ?? [] as $ecart) {
                    $ligne['ecarts'][] = (string) $ecart;
                }
                if (is_array($verdict['correction'] ?? null)) {
                    $corrections[] = $verdict['correction'];
                }
            }

            $ligne['ecrits'][] = $ecrit;
        }

        return $ligne;
    }

    /**
     * Relit l'enregistrement DANS LE PÉRIMÈTRE de l'invité (scoping entreprise du
     * moteur de recherche) : le rapport ne doit jamais révéler l'existence d'une
     * donnée hors périmètre, même pour dire qu'elle va bien.
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

        return $resultat['data'][0] ?? null;
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

    /**
     * Dédoublonne les corrections : deux écarts peuvent viser la même écriture
     * (un champ ET un effet métier). Proposer deux fois la même étape ferait
     * enregistrer deux fois.
     *
     * @param array<int, array> $corrections
     *
     * @return list<array>
     */
    private function dedupliquer(array $corrections): array
    {
        $vues = [];
        $retenues = [];
        foreach ($corrections as $correction) {
            if (trim((string) ($correction['outil'] ?? '')) === '') {
                continue;
            }
            $cle = md5(json_encode([$correction['outil'], $correction['arguments'] ?? []]) ?: '');
            if (isset($vues[$cle])) {
                continue;
            }
            $vues[$cle] = true;
            $retenues[] = $correction;
        }

        return $retenues;
    }
}
