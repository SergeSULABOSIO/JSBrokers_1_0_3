<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ÉCRITURE : signale le paiement d'une prime sur une tranche — le
 * PaiementPrime déclaratif (l'ASSUREUR a encaissé la prime, jamais la trésorerie du
 * courtier), à ne PAS confondre avec l'entité Paiement (trésorerie).
 *
 * Il n'introduit AUCUNE logique d'écriture : il TRADUIT ses arguments en une
 * opération générique (create PaiementPrime, parent = la tranche) et DÉLÈGUE à
 * preparer_operations (donc au même WorkspaceMutationService : validation, budget,
 * plan à valider, exécution transactionnelle, journal). DRY strict — même pattern
 * que ModifierCompositionPrimeTool.
 *
 * Les défauts métier (montant = solde de prime restant, date du jour, référence
 * auto) sont posés ICI, à l'identique du PaiementPrimeType : contrairement au form
 * HTTP, le dry-run réclame les champs NON-NULL (montant, paidAt, reference) AVANT de
 * construire le formulaire — les pré-remplir permet donc « trancheId seul suffit » ET
 * fait apparaître des valeurs concrètes dans l'aperçu du plan.
 *
 * FAIL-CLOSED : signaler = écriture sur une sous-entité de la tranche — niveau
 * Écriture sur « Tranche » exigé (le moteur le re-contrôle via GOUVERNANCE_PARENT),
 * et la tranche est résolue STRICTEMENT dans l'entreprise du scope.
 */
final class SignalerPaiementPrimeTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
        private readonly PreparerOperationsTool $preparer,
        private readonly IndicatorCalculationHelper $calculationHelper,
    ) {
    }

    public function name(): string
    {
        return 'signaler_paiement_prime';
    }

    public function description(): string
    {
        return "Signale le paiement d'une prime sur une TRANCHE : trace déclarative du "
            . "règlement de la prime par l'assuré, encaissé par l'ASSUREUR (sans impact sur "
            . 'la trésorerie du cabinet) — rend la commission de courtage exigible. '
            . 'À appeler quand l\'utilisateur veut signaler/enregistrer/tracer le paiement '
            . 'd\'une prime sur une tranche (trancheId requis — obtiens-le via '
            . 'rechercher_entites si besoin). NE PAS utiliser ouvrir_dialogue avec l\'entité '
            . 'Paiement pour cela : Paiement = encaissement de trésorerie du courtier, ce '
            . 'qui est un tout autre circuit. Fournis trancheId (et, si l\'utilisateur les '
            . 'donne, montant et paidAt) ; sinon les défauts s\'appliquent (montant = solde '
            . 'de prime restant, date du jour, référence auto). L\'outil prépare un PLAN + '
            . 'BUDGET à valider (comme preparer_operations) ; après validation, c\'est TOI '
            . 'qui enregistres. Pour seulement CONSULTER les signalements déjà enregistrés '
            . '(dates, montants, références), utiliser paiements_prime.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'trancheId' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Identifiant de la tranche dont la prime a été payée.',
                ],
                'montant' => [
                    'type' => 'number',
                    'description' => 'Montant réglé, dans la devise de la tranche. Omets-le pour '
                        . 'utiliser le solde de prime restant (paiements partiels possibles).',
                ],
                'paidAt' => [
                    'type' => 'string',
                    'description' => 'Date du règlement par l\'assuré (AAAA-MM-JJ). Omets-la pour la date du jour.',
                ],
                'reference' => [
                    'type' => 'string',
                    'description' => 'Référence du règlement. Omets-la pour une référence auto-générée.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Précision facultative sur la source de l\'information.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => 'Ne mets true QUE si un plan attend déjà une décision ET que '
                        . 'l\'utilisateur demande de le CHANGER : le plan en attente est annulé et '
                        . 'remplacé. Sinon, tant qu\'un plan attend, la préparation est refusée.',
                ],
            ],
            'required' => ['trancheId'],
        ];
    }

    /**
     * Chemin simulé : « signale le paiement de la prime de la tranche 71 »,
     * « enregistre le paiement de prime sur la tranche n°12 »… L'id de tranche
     * doit figurer dans la question (le LLM réel sait le chercher, pas le simulé).
     */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        // « quels paiements de prime ont ete signales ? » : l'accent tombe à la
        // normalisation, le participe devient indiscernable de l'impératif. Une
        // formulation interrogative est une LECTURE (paiements_prime), pas une saisie.
        if (PaiementPrimeIntent::estInterrogatif($normalized)) {
            return null;
        }

        $parleDePrimePayee = preg_match('/\b(signale[rsz]?|enregistre[rsz]?|trace[rsz]?|declare[rsz]?)\b/', $normalized)
            && preg_match('/\bprimes?\b/', $normalized)
            && preg_match('/\b(paiements?|payee?s?|regle[es]?|reglements?)\b/', $normalized);
        if (!$parleDePrimePayee) {
            return null;
        }

        if (!preg_match('/\btranche\s*(?:n[°o]?\s*)?#?(\d+)\b/u', $normalized, $m)) {
            return null;
        }

        return ['trancheId' => (int) $m[1]];
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $labels = $this->accessResolver->libellesEntites();
        $libelleTranche = $labels['Tranche'] ?? 'Tranches';

        // FAIL-CLOSED : préparer un signalement = mutation à venir sur la tranche.
        if (!$this->accessResolver->can($scope->invite, 'Tranche', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre($libelleTranche);
        }

        $trancheId = (int) ($args['trancheId'] ?? 0);
        if ($trancheId <= 0) {
            return AiToolResult::introuvable($libelleTranche);
        }

        // Scoping : la tranche doit exister DANS l'entreprise du scope.
        $result = $this->searchService->search(Tranche::class, ['id' => $trancheId], $scope->entreprise, null, 1, 1);
        $tranche = $result['data'][0] ?? null;
        if (($result['status']['code'] ?? 500) !== 200 || $tranche === null) {
            return AiToolResult::introuvable(sprintf('%s #%d', $libelleTranche, $trancheId));
        }

        // Traduction en opération générique + délégation au moteur unique. Le parent
        // « tranche » est posé par id (le moteur appelle setTranche). Les champs
        // NON-NULL (montant, paidAt, reference) sont pré-remplis à l'identique du
        // PaiementPrimeType, car le dry-run les EXIGE avant de construire le
        // formulaire ; l'utilisateur peut tous les surcharger.
        $champs = [
            'tranche'   => $trancheId,
            'montant'   => $this->resoudreMontant($args, $tranche),
            'paidAt'    => $this->resoudrePaidAt($args),
            'reference' => $this->resoudreReference($args),
        ];
        if (($args['description'] ?? null) !== null && $args['description'] !== '') {
            $champs['description'] = (string) $args['description']; // sinon auto par le FormType.
        }

        return $this->preparer->execute([
            'operations' => [[
                'op'     => 'create',
                'entite' => 'PaiementPrime',
                'champs' => $champs,
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);
    }

    /** Montant fourni, sinon solde de prime restant à signaler (formule du PaiementPrimeType). */
    private function resoudreMontant(array $args, Tranche $tranche): float
    {
        if (($args['montant'] ?? null) !== null && $args['montant'] !== '') {
            return (float) $args['montant'];
        }

        $prime = $this->calculationHelper->getCotationMontantPrimePayableParClient($tranche->getCotation())
            * $this->calculationHelper->getTrancheTauxFactor($tranche);
        $solde = round($prime - $this->calculationHelper->getTranchePrimePayee($tranche), 2);

        return $solde > 0 ? $solde : 0.0;
    }

    /** Date fournie (AAAA-MM-JJ…), sinon maintenant — format attendu par DateTimeType single_text. */
    private function resoudrePaidAt(array $args): string
    {
        $brut = trim((string) ($args['paidAt'] ?? ''));
        try {
            $date = $brut !== '' ? new \DateTimeImmutable($brut) : new \DateTimeImmutable('now');
        } catch (\Throwable) {
            $date = new \DateTimeImmutable('now');
        }

        return $date->format('Y-m-d\TH:i:s');
    }

    /** Référence fournie, sinon générée (même schéma que le PaiementPrimeType). */
    private function resoudreReference(array $args): string
    {
        $ref = trim((string) ($args['reference'] ?? ''));

        return $ref !== '' ? $ref : 'PRIME-' . (new \DateTimeImmutable('now'))->format('dmY-His');
    }
}
