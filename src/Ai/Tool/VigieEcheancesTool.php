<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Services\DashboardDataProvider;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données : le BRIEF des échéances du courtier — polices à renouveler
 * sous N jours, tâches non closes (dont celles en retard), pistes encore sans
 * police, derniers sinistres notifiés, tranches échues impayées (primes et
 * commissions à relancer). Répond à « que dois-je surveiller ? ».
 *
 * Gating PAR VOLET (fail-closed) : chaque volet est adossé à une entité du
 * périmètre ; un volet hors périmètre est OMIS avec mention (l'assistant reste
 * utile sur le reste), le refus global n'arrive que si tout est hors périmètre.
 *
 * COÛT : getAllRenouvellements() hydrate les valeurs calculées des avenants —
 * l'horizon est borné dur (HORIZON_MAX) pour contenir ce coût.
 */
final class VigieEcheancesTool implements AiToolInterface
{
    /** volet => entité dont le droit de lecture conditionne le volet. */
    private const VOLETS = [
        'renouvellements' => 'Avenant',
        'taches'          => 'Tache',
        'pistes'          => 'Piste',
        'sinistres'       => 'NotificationSinistre',
        'impayes'         => 'Tranche',
    ];

    /** Lignes restituées par volet (sortie compacte, économie de tokens). */
    private const MAX_LIGNES_PAR_VOLET = 8;

    private const HORIZON_DEFAUT = 30;
    private const HORIZON_MAX = 180;

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly DashboardDataProvider $dashboard,
        private readonly TranchePaiementService $tranchePaiement,
    ) {
    }

    public function name(): string
    {
        return 'vigie_echeances';
    }

    public function description(): string
    {
        return 'Brief des échéances et points de vigilance du cabinet : polices à renouveler '
            . 'sous N jours (défaut 30), tâches non closes (dont en retard), pistes en cours '
            . 'sans police, derniers sinistres notifiés, tranches échues impayées (primes et '
            . 'commissions à relancer). À appeler quand l\'utilisateur demande ses échéances, '
            . 'ce qu\'il doit surveiller/faire, ses renouvellements à venir ou un brief du jour. '
            . 'Pour le détail complet des impayés, préférer suivi_impayes ; pour lister/compter '
            . 'librement une rubrique, préférer rechercher_entites / compter_entites.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'volet' => [
                    'type' => 'string',
                    'enum' => array_merge(array_keys(self::VOLETS), ['tout']),
                    'description' => 'Volet du brief : renouvellements de polices, tâches non closes, '
                        . 'pistes en cours, derniers sinistres — ou tout (brief complet).',
                ],
                'horizonJours' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::HORIZON_MAX,
                    'description' => 'Horizon des renouvellements en jours (défaut ' . self::HORIZON_DEFAUT . ').',
                ],
            ],
            'required' => ['volet'],
        ];
    }

    /**
     * Chemin simulé : « mes échéances », « brief du jour », « renouvellements
     * (sous N jours) », « tâches en retard/non closes ». « Liste les tâches »
     * reste le domaine de rechercher_entites.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\b(vigie|echeances?|brief|a surveiller|points? de vigilance)\b/', $normalized)) {
            $args = ['volet' => 'tout'];
            if (preg_match('/\b(\d{1,3})\s*jours?\b/', $normalized, $m)) {
                $args['horizonJours'] = (int) $m[1];
            }

            return $args;
        }

        if (preg_match('/\brenouvellements?\b|\brenouveler\b/', $normalized)
            && !preg_match('/\bcombien\b/', $normalized)) {
            $args = ['volet' => 'renouvellements'];
            if (preg_match('/\b(\d{1,3})\s*jours?\b/', $normalized, $m)) {
                $args['horizonJours'] = (int) $m[1];
            }

            return $args;
        }

        if (preg_match('/\btaches?\s+(en retard|non closes?|ouvertes?|en cours)\b/', $normalized)) {
            return ['volet' => 'taches'];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $volet = (string) ($args['volet'] ?? 'tout');
        if ($volet !== 'tout' && !isset(self::VOLETS[$volet])) {
            return AiToolResult::introuvable($volet);
        }
        $horizon = max(1, min(self::HORIZON_MAX, (int) ($args['horizonJours'] ?? self::HORIZON_DEFAUT)));

        $demandes = $volet === 'tout' ? array_keys(self::VOLETS) : [$volet];
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED par volet : hors périmètre => volet omis avec mention.
        $horsPerimetre = [];
        $volets = [];
        foreach ($demandes as $v) {
            $entite = self::VOLETS[$v];
            if (!$this->accessResolver->canRead($scope->invite, $entite)) {
                $horsPerimetre[] = $labels[$entite] ?? $entite;
                continue;
            }
            $volets[$v] = $this->collecter($v, $scope, $horizon);
        }

        if ($volets === []) {
            return AiToolResult::horsPerimetre(
                'Échéances (' . implode(', ', $horsPerimetre) . ')'
            );
        }

        return AiToolResult::ok(array_filter([
            'date'          => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'horizonJours'  => $horizon,
            'volets'        => $volets,
            'horsPerimetre' => $horsPerimetre ?: null,
        ], static fn ($v) => $v !== null));
    }

    /** @return array{lignes: array, total: int, tronque: bool, totaux?: array} */
    private function collecter(string $volet, AiScope $scope, int $horizon): array
    {
        $entreprise = $scope->entreprise;
        $max = self::MAX_LIGNES_PAR_VOLET;

        // Volet impayés : tranches échues (prime ou commission encore dues), les plus
        // en retard d'abord — source unique TranchePaiementService (règle de la liste).
        if ($volet === 'impayes') {
            $resultat = $this->tranchePaiement->lister(
                $entreprise,
                TranchePaiementScope::STATUT_ECHUES,
                null,
                null,
                1,
                $max
            );

            return [
                'lignes'  => array_map(fn (object $e) => $this->projeter($volet, $e), $resultat['items']),
                'totaux'  => $resultat['totaux'],
                'total'   => $resultat['totalItems'],
                'tronque' => $resultat['totalItems'] > count($resultat['items']),
            ];
        }

        $items = match ($volet) {
            'renouvellements' => $this->dashboard->getAllRenouvellements($entreprise, $horizon),
            'taches'          => $this->dashboard->getTachesNonCloses($entreprise, $max + 1),
            'pistes'          => $this->dashboard->getPistesEnCours($entreprise, $max + 1),
            'sinistres'       => $this->dashboard->getDerniersSinistres($entreprise, $max + 1),
        };

        $total = count($items);
        $lignes = array_map(
            fn (object $e) => $this->projeter($volet, $e),
            array_slice($items, 0, $max),
        );

        return ['lignes' => $lignes, 'total' => $total, 'tronque' => $total > $max];
    }

    /** Projection compacte d'une ligne (scalaires utiles uniquement, dates Y-m-d). */
    private function projeter(string $volet, object $e): array
    {
        $aujourdhui = new \DateTimeImmutable('today');

        return match ($volet) {
            'renouvellements' => array_filter([
                'id'            => $e->getId(),
                'police'        => $e->getReferencePolice(),
                'client'        => $e->getCotation()?->getPiste()?->getClient()?->getNom(),
                'assureur'      => $e->getCotation()?->getAssureur()?->getNom(),
                'risque'        => $e->getCotation()?->getPiste()?->getRisque()?->getNomComplet(),
                'echeance'      => $e->getEndingAt()?->format('Y-m-d'),
                'joursRestants' => $e->getEndingAt() !== null
                    ? (int) $aujourdhui->diff(\DateTimeImmutable::createFromInterface($e->getEndingAt()))->format('%r%a')
                    : null,
            ], static fn ($v) => $v !== null && $v !== ''),
            'taches' => array_filter([
                'id'          => $e->getId(),
                'description' => mb_substr((string) $e->getDescription(), 0, 80),
                'echeance'    => $e->getToBeEndedAt()?->format('Y-m-d'),
                'enRetard'    => $e->getToBeEndedAt() !== null
                    && \DateTimeImmutable::createFromInterface($e->getToBeEndedAt()) < $aujourdhui,
            ], static fn ($v) => $v !== null && $v !== ''),
            'pistes' => array_filter([
                'id'     => $e->getId(),
                'nom'    => $e->getNom(),
                'client' => $e->getClient()?->getNom(),
                'risque' => $e->getRisque()?->getNomComplet(),
                'creeLe' => $e->getCreatedAt()?->format('Y-m-d'),
            ], static fn ($v) => $v !== null && $v !== ''),
            'sinistres' => array_filter([
                'id'        => $e->getId(),
                'reference' => $e->getReferenceSinistre(),
                'assure'    => $e->getAssure()?->getNom(),
                'assureur'  => $e->getAssureur()?->getNom(),
                'risque'    => $e->getRisque()?->getNomComplet(),
                'notifieLe' => $e->getNotifiedAt()?->format('Y-m-d'),
            ], static fn ($v) => $v !== null && $v !== ''),
            // Tranche échue impayée (indicateurs déjà calculés par TranchePaiementService).
            'impayes' => array_filter([
                'id'              => $e->getId(),
                'tranche'         => $e->getNom(),
                'client'          => $e->clientNom ?? null,
                'police'          => $e->referencePolice ?? null,
                'statut'          => $e->statutPaiement ?? null,
                'urgence'         => $e->urgenceRecouvrement ?? null,
                'echeance'        => $e->getEcheanceAt()?->format('Y-m-d'),
                'joursRetard'     => $e->getEcheanceAt() !== null
                    // Jour à jour : échéance ramenée à minuit (sinon l'heure tronque un jour).
                    ? max(0, -((int) $aujourdhui->diff(\DateTimeImmutable::createFromInterface($e->getEcheanceAt())->setTime(0, 0))->format('%r%a')))
                    : null,
                'soldePrime'      => max(0.0, (float) ($e->primeSoldeDue ?? 0)),
                'soldeCommission' => max(0.0, (float) ($e->solde_restant_du ?? 0)),
                'retroAPayer'     => ($e->retroCommissionExigible ?? 0) > 0 ? (float) $e->retroCommissionExigible : null,
            ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A'),
        };
    }
}
