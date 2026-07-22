<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\PaiementPrime;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;
use App\Services\Search\PortefeuilleCritereFactory;
use App\Services\Search\PortefeuilleScope;
use App\Services\Tranche\TranchePaiementService;

/**
 * Outil de données : les SIGNALEMENTS de paiement de prime (entité PaiementPrime) —
 * qui a réglé quoi, quand, sous quelle référence, avec quelles preuves.
 *
 * Marché par défaut : l'ASSUREUR facture et encaisse la prime ; le courtier ne fait que
 * TRACER l'information pour savoir quand sa commission devient exigible. Un signalement
 * n'impacte donc JAMAIS la trésorerie du cabinet — à ne pas confondre avec l'entité
 * Paiement (rubrique Paiements), qui est, elle, un encaissement du courtier.
 *
 * Deux modes :
 *  - CIBLÉ (trancheId) : les signalements d'UNE tranche, replacés dans son contexte de
 *    règlement (prime totale, part signalée, solde, exigibilité de la commission) ;
 *  - TRANSVERSAL (sans trancheId) : la liste des signalements de l'entreprise, filtrable
 *    par rattachement (client/cotation) et par période de paiement.
 *
 * FAIL-CLOSED : PaiementPrime est une SOUS-ENTITÉ STRUCTURELLE gouvernée par sa Tranche
 * (même règle que TrancheController::getPaiementPrimeContext côté écriture) — sans droit
 * de LECTURE sur Tranche, ces données n'existent pas pour l'assistant. Scoping entreprise
 * doublement assuré par JSBDynamicSearchService (la tranche ET les signalements portent
 * un lien `entreprise`).
 *
 * PÉRIMÈTRE : le mode ciblé désigne un enregistrement par son id — pas de filtre
 * portefeuille, comme lire_fiche et signaler_paiement_prime. Le mode transversal est une
 * LISTE : le portefeuille de l'invité s'y applique par défaut, comme à l'écran.
 */
final class PaiementsPrimeTool implements AiToolInterface
{
    /** Lignes restituées par page (sortie compacte, économie de tokens). */
    private const MAX_LIGNES = 20;

    /**
     * Rattachements admis en mode transversal : nom court => chemin de relations depuis
     * PaiementPrime (le moteur de recherche joint chaque segment et filtre par identité).
     */
    private const LIE_A_CHEMINS = [
        'Cotation' => 'tranche.cotation',
        'Client'   => 'tranche.cotation.piste.client',
    ];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly TranchePaiementService $tranchePaiement,
        private readonly PortefeuilleCritereFactory $portefeuilleCritere,
    ) {
    }

    public function name(): string
    {
        return 'paiements_prime';
    }

    public function description(): string
    {
        return 'Consulte les SIGNALEMENTS de paiement de prime : date de règlement, montant, '
            . 'référence, description et nombre de pièces justificatives. Avec trancheId, '
            . 'restitue les signalements d\'UNE tranche et son contexte de règlement (prime '
            . 'totale, part signalée, solde restant, commission devenue exigible) ; sans '
            . 'trancheId, liste les signalements de l\'entreprise, filtrables par client ou '
            . 'cotation (lieA) et par période de paiement (du/au). À appeler dès que la '
            . 'question porte sur le paiement de la PRIME par l\'assuré : « la prime de cette '
            . 'tranche a-t-elle été payée ? », « quels paiements de prime ont été signalés ? », '
            . '« quand la prime a-t-elle été réglée, pour quel montant ? ». ATTENTION : un '
            . 'signalement est DÉCLARATIF — l\'assureur encaisse la prime, jamais la trésorerie '
            . 'du cabinet ; ne jamais confondre avec l\'entité Paiement (rubrique Paiements = '
            . 'encaissements du courtier). Pour EN CRÉER un, utiliser signaler_paiement_prime.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trancheId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Mode ciblé : identifiant de la tranche dont on veut les '
                        . 'signalements (obtenu via rechercher_entites ou une fiche attachée).',
                ],
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Mode transversal : restreint aux signalements des tranches '
                        . 'rattachées à cette fiche (client ou cotation).',
                    'properties' => [
                        'entite' => ['type' => 'string', 'enum' => array_keys(self::LIE_A_CHEMINS)],
                        'id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['entite', 'id'],
                ],
                'du' => [
                    'type' => 'string',
                    'description' => 'Mode transversal : début de la période de paiement, AAAA-MM-JJ.',
                ],
                'au' => [
                    'type' => 'string',
                    'description' => 'Mode transversal : fin de la période de paiement, AAAA-MM-JJ.',
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page de résultats (défaut 1, ' . self::MAX_LIGNES . ' lignes par page).',
                ],
                'perimetre' => PortefeuilleScope::proprieteSchema(),
            ],
            'required' => [],
        ];
    }

    /**
     * Chemin simulé : formulation INTERROGATIVE sur le paiement d'une prime — « quels
     * paiements de prime sur la tranche 12 ? », « la prime de la tranche 5 a-t-elle été
     * payée ? », « historique des primes réglées ce mois-ci ». Les formulations
     * IMPÉRATIVES (« signale / enregistre le paiement… ») restent à signaler_paiement_prime.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // Question sur une prime, formulée pour VOIR : l'impératif (« signale le
        // paiement… ») reste le domaine de signaler_paiement_prime.
        if (!PaiementPrimeIntent::concerne($normalized) || !PaiementPrimeIntent::estInterrogatif($normalized)) {
            return null;
        }

        $args = [];
        if (preg_match('/\btranche\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            $args['trancheId'] = (int) $m[1];

            return $args; // Mode ciblé : le périmètre portefeuille ne s'applique pas.
        }

        if (($p = PortefeuilleScope::detecterPerimetreDepuisTexte($normalized)) !== null) {
            $args['perimetre'] = $p;
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();
        $libelleTranche = $labels['Tranche'] ?? 'Tranches';

        // FAIL-CLOSED : sous-entité gouvernée par sa tranche — la lecture des Tranches
        // conditionne l'accès aux signalements de paiement de prime.
        if (!$this->accessResolver->canRead($scope->invite, 'Tranche')) {
            return AiToolResult::horsPerimetre($libelleTranche);
        }

        $trancheId = (int) ($args['trancheId'] ?? 0);

        return $trancheId > 0
            ? $this->executerCible($trancheId, $args, $scope, $libelleTranche)
            : $this->executerTransversal($args, $scope);
    }

    /** Mode CIBLÉ : les signalements d'une tranche, replacés dans son contexte de règlement. */
    private function executerCible(int $trancheId, array $args, AiScope $scope, string $libelleTranche): AiToolResult
    {
        // Scoping : la tranche doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Tranche::class, ['id' => $trancheId], $scope->entreprise, null, 1, 1);
        $tranche = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || !$tranche instanceof Tranche) {
            return AiToolResult::introuvable(sprintf('%s #%d', $libelleTranche, $trancheId));
        }

        // Indicateurs calculés par le MÊME moteur que la rubrique Tranches et suivi_impayes.
        $this->tranchePaiement->chargerIndicateurs([$tranche]);

        $page = max(1, (int) ($args['page'] ?? 1));
        $signalements = $this->searchService->search(
            PaiementPrime::class,
            ['tranche' => ['operator' => '=', 'value' => $trancheId]],
            $scope->entreprise,
            null,
            $page,
            self::MAX_LIGNES,
        );
        if (($signalements['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable(sprintf('%s #%d', $libelleTranche, $trancheId));
        }

        $commissionExigible = (float) ($tranche->commissionExigible ?? 0);

        return AiToolResult::ok(array_filter([
            'tranche' => array_filter([
                'id' => $tranche->getId(),
                'nom' => $tranche->getNom(),
                'echeance' => $tranche->getEcheanceAt()?->format('Y-m-d'),
                'statutPaiement' => $tranche->statutPaiement ?? null,
                'urgence' => $tranche->urgenceRecouvrement ?? null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A'),
            'prime' => [
                'totale' => round((float) ($tranche->primeTranche ?? 0), 2),
                'payee' => round((float) ($tranche->primePayee ?? 0), 2),
                'signalee' => round((float) ($tranche->primeDeclareePayee ?? 0), 2),
                'solde' => round(max(0.0, (float) ($tranche->primeSoldeDue ?? 0)), 2),
            ],
            'commissionExigible' => $commissionExigible > 0 ? round($commissionExigible, 2) : null,
            'signalements' => array_map(fn (PaiementPrime $p) => $this->projeter($p), $signalements['data']),
            'total' => (int) $signalements['totalItems'],
            'page' => (int) $signalements['currentPage'],
            'totalPages' => (int) $signalements['totalPages'],
            'note' => "Signalement DÉCLARATIF : l'assuré a réglé la prime, encaissée par l'ASSUREUR — "
                . "jamais la trésorerie du cabinet (rien à voir avec l'entité Paiement). C'est ce qui "
                . 'rend la commission de courtage exigible. « payee » peut dépasser « signalee » : la '
                . 'prime est aussi réputée payée par les notes client encaissées ou par un bordereau '
                . 'réconcilié attestant que l\'assureur la détient.',
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /** Mode TRANSVERSAL : les signalements de l'entreprise, dans le périmètre de l'écran. */
    private function executerTransversal(array $args, AiScope $scope): AiToolResult
    {
        $criteria = [];

        $lien = null;
        $lieA = $args['lieA'] ?? null;
        if (\is_array($lieA) && $lieA !== []) {
            $lienType = (string) ($lieA['entite'] ?? '');
            $lienId = (int) ($lieA['id'] ?? 0);
            if (!isset(self::LIE_A_CHEMINS[$lienType]) || $lienId < 1) {
                return AiToolResult::introuvable($lienType . '#' . $lienId);
            }
            // FAIL-CLOSED sur l'entité de rattachement aussi : la référencer, c'est la lire.
            if (!$this->accessResolver->canRead($scope->invite, $lienType)) {
                return AiToolResult::horsPerimetre($this->accessResolver->libellesEntites()[$lienType] ?? $lienType);
            }
            $criteria[self::LIE_A_CHEMINS[$lienType]] = ['operator' => '=', 'value' => $lienId];
            $lien = ['entite' => $lienType, 'id' => $lienId];
        }

        // Période de RÈGLEMENT (paidAt) : plage de dates gérée nativement par le moteur.
        $du = $this->dateValide($args['du'] ?? null);
        $au = $this->dateValide($args['au'] ?? null);
        if ($du !== null || $au !== null) {
            $criteria['paidAt'] = array_filter(['from' => $du, 'to' => $au], static fn ($v) => $v !== null);
        }

        // PÉRIMÈTRE : par défaut le portefeuille de l'invité, comme les rubriques à l'écran.
        $perimetreEntreprise = PortefeuilleScope::estEntreprise($args['perimetre'] ?? null);
        $criterePortefeuille = $perimetreEntreprise
            ? []
            : $this->portefeuilleCritere->pour('PaiementPrime', $scope->invite);

        $page = max(1, (int) ($args['page'] ?? 1));
        $result = $this->searchService->search(
            PaiementPrime::class,
            $criteria + $criterePortefeuille,
            $scope->entreprise,
            null,
            $page,
            self::MAX_LIGNES,
        );
        if (($result['status']['code'] ?? 500) !== 200) {
            return AiToolResult::introuvable('Paiements de prime signalés');
        }

        $items = array_map(fn (PaiementPrime $p) => $this->projeter($p, true), $result['data']);
        $montantPage = 0.0;
        foreach ($result['data'] as $paiement) {
            $montantPage += (float) ($paiement->getMontant() ?? 0);
        }

        return AiToolResult::ok(array_filter([
            'perimetre' => PortefeuilleScope::libellePerimetre($perimetreEntreprise, $criterePortefeuille),
            'lien' => $lien,
            'du' => $du,
            'au' => $au,
            'items' => $items,
            'montantPage' => $items === [] ? null : round($montantPage, 2),
            'montantPageNote' => $items === [] ? null : 'Somme des signalements de CETTE page uniquement.',
            'total' => (int) $result['totalItems'],
            'page' => (int) $result['currentPage'],
            'totalPages' => (int) $result['totalPages'],
            'note' => "Signalements DÉCLARATIFS du règlement des primes par les assurés (encaissées par "
                . "l'ASSUREUR) : aucun impact sur la trésorerie du cabinet.",
        ], static fn ($v) => $v !== null && $v !== []));
    }

    /**
     * Projection compacte d'un signalement. $avecTranche rattache la ligne à sa tranche
     * (mode transversal, où le contexte n'est pas déjà donné par l'entête).
     */
    private function projeter(PaiementPrime $paiement, bool $avecTranche = false): array
    {
        $tranche = $paiement->getTranche();

        return array_filter([
            'id' => $paiement->getId(),
            'date' => $paiement->getPaidAt()?->format('Y-m-d'),
            'montant' => round((float) ($paiement->getMontant() ?? 0), 2),
            'reference' => $paiement->getReference(),
            'description' => $paiement->getDescription(),
            'preuves' => $paiement->getPreuves()->count() ?: null,
            'tranche' => ($avecTranche && $tranche !== null)
                ? ['id' => $tranche->getId(), 'nom' => $tranche->getNom()]
                : null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /** Date AAAA-MM-JJ validée, ou null (une valeur mal formée est simplement ignorée). */
    private function dateValide(mixed $valeur): ?string
    {
        $valeur = trim((string) ($valeur ?? ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) ? $valeur : null;
    }
}
