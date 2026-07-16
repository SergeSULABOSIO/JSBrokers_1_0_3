<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Tranche;
use App\Services\Search\TranchePaiementScope;
use App\Services\Tranche\TranchePaiementService;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil de données : suivi des paiements par tranche — primes clients et commissions
 * exigibles, soldes, retards par rapport à l'échéance, triés par urgence. Alimente les
 * relances structurées de l'assistant (« quelles primes sont en retard ? », « relances
 * à faire pour le client X »).
 *
 * FAIL-CLOSED : sans droit de lecture sur Tranche, les données n'existent pas.
 * Source unique : TranchePaiementService (même règle métier que la liste Tranches —
 * « payée » = prime encaissée ET commission collectée).
 */
final class SuiviImpayesTool implements AiToolInterface
{
    /** Lignes restituées par page (sortie compacte, économie de tokens). */
    private const MAX_LIGNES = 10;

    private const LIE_A_AUTORISES = ['Client', 'Cotation'];

    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly TranchePaiementService $tranchePaiement,
    ) {
    }

    public function name(): string
    {
        return 'suivi_impayes';
    }

    public function description(): string
    {
        return 'Suivi des paiements par tranche : primes clients et commissions exigibles, '
            . 'soldes restants, retards par rapport à la date d\'échéance, triés par urgence '
            . '(les plus en retard d\'abord). Statut commission_exigible = commissions à '
            . 'collecter MAINTENANT auprès de l\'assureur (la prime a été payée par l\'assuré '
            . '— facturée ou signalée). Signale aussi les rétrocommissions partenaires à payer '
            . '(exigibles dès que la commission partageable est encaissée). À appeler pour : '
            . 'impayés, arriérés, relances à faire, primes ou commissions en retard/dues/exigibles, '
            . 'qui doit payer, soldes dus, rétros à verser aux partenaires. Restreignable à un '
            . 'client ou une cotation via lieA.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'statut' => [
                    'type' => 'string',
                    'enum' => array_keys(TranchePaiementScope::VALEURS),
                    'description' => 'Filtre : impayees (tout solde exigible, défaut), echues (impayées '
                        . 'en retard), a_echoir (impayées non échues), partiellement (règlement entamé), '
                        . 'payees (prime et commission soldées).',
                ],
                'lieA' => [
                    'type' => 'object',
                    'description' => 'Restreint aux tranches rattachées à cette fiche (client ou cotation).',
                    'properties' => [
                        'entite' => ['type' => 'string', 'enum' => self::LIE_A_AUTORISES],
                        'id' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['entite', 'id'],
                ],
                'page' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Page de résultats (défaut 1, ' . self::MAX_LIGNES . ' lignes par page).',
                ],
            ],
            'required' => [],
        ];
    }

    /**
     * Chemin simulé : « impayés », « arriérés », « primes en retard », « relances »…
     * « Liste les tranches » reste le domaine de rechercher_entites.
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        $declencheurEncaissement = (bool) preg_match(
            '/\b(impayes?|arrieres?|relances?|retards? de paiement|primes? (dues?|en retard|a collecter)|commissions? (dues?|en retard|a collecter)|soldes? dus?|qui doit payer)\b/',
            $normalized
        );
        // Flux inverse : rétro évoquée AVEC une notion de paiement/dette (« liste les
        // rétrocommissions » reste le domaine de rechercher_entites).
        $declencheurRetro = preg_match('/\bretro(commission)?s?\b/', $normalized)
            && preg_match('/\b(payer|verser|reverser|dues?|dois|exigibles?|soldes?)\b/', $normalized);
        // Exigibilité : commissions devenues collectables (prime encaissée par l'assureur).
        $declencheurExigible = preg_match('/\bcommissions?\b/', $normalized)
            && preg_match('/\bexigibles?\b|\bcollecter\b|\bencaisser\b/', $normalized);

        if (!$declencheurEncaissement && !$declencheurRetro && !$declencheurExigible) {
            return null;
        }

        $args = ['statut' => TranchePaiementScope::STATUT_IMPAYEES];
        if ($declencheurRetro || preg_match('/\bpartenaires?\b/', $normalized)) {
            // Ce que le courtier doit VERSER aux partenaires.
            $args['statut'] = TranchePaiementScope::STATUT_RETRO_A_PAYER;
        } elseif ($declencheurExigible) {
            // Commissions devenues collectables (prime payée par l'assuré).
            $args['statut'] = TranchePaiementScope::STATUT_COMMISSION_EXIGIBLE;
        } elseif (preg_match('/\b(echues?|en retard)\b/', $normalized)) {
            $args['statut'] = TranchePaiementScope::STATUT_ECHUES;
        }

        return $args;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();

        // FAIL-CLOSED : sans droit de lecture explicite, les données n'existent pas.
        if (!$this->accessResolver->canRead($scope->invite, 'Tranche')) {
            return AiToolResult::horsPerimetre($labels['Tranche'] ?? 'Suivi des paiements (Tranches)');
        }

        $statut = (string) ($args['statut'] ?? TranchePaiementScope::STATUT_IMPAYEES);
        if (!TranchePaiementScope::estValide($statut)) {
            return AiToolResult::introuvable($statut);
        }

        $lieAEntite = null;
        $lieAId = null;
        if (isset($args['lieA']) && is_array($args['lieA'])) {
            $lieAEntite = (string) ($args['lieA']['entite'] ?? '');
            $lieAId = (int) ($args['lieA']['id'] ?? 0);
            if (!in_array($lieAEntite, self::LIE_A_AUTORISES, true) || $lieAId < 1) {
                return AiToolResult::introuvable($lieAEntite . '#' . $lieAId);
            }
        }

        $page = max(1, (int) ($args['page'] ?? 1));
        $resultat = $this->tranchePaiement->lister(
            $scope->entreprise,
            $statut,
            $lieAEntite,
            $lieAId,
            $page,
            self::MAX_LIGNES
        );

        return AiToolResult::ok(array_filter([
            'date' => (new \DateTimeImmutable('now'))->format('Y-m-d'),
            'statut' => TranchePaiementScope::libelle($statut),
            'lignes' => array_map(fn (Tranche $t) => $this->projeter($t), $resultat['items']),
            'totaux' => $resultat['totaux'],
            'total' => $resultat['totalItems'],
            'page' => $resultat['currentPage'],
            'tronque' => $resultat['totalItems'] > count($resultat['items']) ? true : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Projection compacte d'une tranche (indicateurs déjà calculés par le service).
     * Les soldes négatifs (trop-perçus) sont restitués à 0 : rien à relancer.
     */
    private function projeter(Tranche $tranche): array
    {
        $echeance = $tranche->getEcheanceAt();
        $joursRetard = null;
        if ($echeance !== null) {
            // Comparaison JOUR à JOUR : l'échéance est ramenée à minuit, sinon l'heure
            // portée par la date tronque un jour de retard sur le diff avec « today ».
            $aujourdhui = new \DateTimeImmutable('today');
            $echeanceJour = \DateTimeImmutable::createFromInterface($echeance)->setTime(0, 0);
            $ecart = (int) $aujourdhui->diff($echeanceJour)->format('%r%a');
            $joursRetard = $ecart < 0 ? -$ecart : 0;
        }

        return array_filter([
            'id' => $tranche->getId(),
            'tranche' => $tranche->getNom(),
            'client' => $tranche->clientNom ?? null,
            'police' => $tranche->referencePolice ?? null,
            'cotation' => $tranche->cotationNom ?? null,
            'statut' => $tranche->statutPaiement ?? null,
            'urgence' => $tranche->urgenceRecouvrement ?? null,
            'echeance' => $echeance?->format('Y-m-d'),
            'joursRetard' => $joursRetard,
            'prime' => $tranche->primeTranche ?? null,
            'soldePrime' => max(0.0, (float) ($tranche->primeSoldeDue ?? 0)),
            'soldeCommission' => max(0.0, (float) ($tranche->solde_restant_du ?? 0)),
            // Signaux d'exigibilité (absents quand rien n'est exigible, pour rester compact) :
            // commission à collecter auprès de l'assureur (prime payée/signalée) et rétro
            // partenaire à verser (commission partageable encaissée).
            'commissionExigible' => ($tranche->commissionExigible ?? 0) > 0 ? (float) $tranche->commissionExigible : null,
            'retroAPayer' => ($tranche->retroCommissionExigible ?? 0) > 0 ? (float) $tranche->retroCommissionExigible : null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 'N/A');
    }
}
