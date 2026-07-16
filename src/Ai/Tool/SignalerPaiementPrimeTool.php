<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\Invite;
use App\Entity\Tranche;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\JSBDynamicSearchService;

/**
 * Outil d'ACTION UI : ouvre le formulaire « Signaler un paiement de prime » d'une
 * tranche — le PaiementPrime déclaratif (l'ASSUREUR a encaissé la prime, jamais la
 * trésorerie du courtier), à ne PAS confondre avec l'entité Paiement (trésorerie).
 * Le dialogue s'ouvre PRÉREMPLI par le circuit standard de l'action de liste
 * (ui:tranche.signaler-paiement-prime) : montant = solde de prime restant, date du
 * jour, description auto — l'utilisateur relit et enregistre lui-même.
 *
 * FAIL-CLOSED : préparer ce signalement = mutation à venir sur la tranche —
 * niveau Écriture exigé, et la tranche est résolue STRICTEMENT dans l'entreprise
 * du scope (le endpoint de contexte re-valide de toute façon côté serveur).
 */
final class SignalerPaiementPrimeTool implements AiToolInterface
{
    public function __construct(
        private readonly WorkspaceAccessResolver $accessResolver,
        private readonly JSBDynamicSearchService $searchService,
    ) {
    }

    public function name(): string
    {
        return 'signaler_paiement_prime';
    }

    public function description(): string
    {
        return "Ouvre le formulaire « Signaler un paiement de prime » d'une TRANCHE : trace "
            . "déclarative du règlement de la prime par l'assuré, encaissé par l'ASSUREUR "
            . "(sans impact sur la trésorerie du cabinet) — rend la commission exigible. "
            . 'À appeler quand l\'utilisateur veut signaler/enregistrer/tracer le paiement '
            . 'd\'une prime sur une tranche (trancheId requis — obtiens-le via '
            . 'rechercher_entites si besoin). NE PAS utiliser ouvrir_dialogue avec l\'entité '
            . 'Paiement pour cela : Paiement = encaissement de trésorerie du courtier, ce '
            . 'qui est un tout autre circuit. Le formulaire s\'ouvre prérempli (solde de '
            . 'prime restant, date du jour) : l\'utilisateur vérifie et enregistre lui-même.';
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

        return AiToolResult::ok(
            [
                'trancheId' => $trancheId,
                'tranche'   => $tranche->getNom(),
                'note'      => "Le formulaire « Signaler un paiement de prime » s'ouvre dans l'espace de travail, "
                    . 'prérempli avec le solde de prime restant et la date du jour (déclaratif : encaissé par '
                    . "l'assureur, sans impact sur la trésorerie) — l'utilisateur vérifie et enregistre lui-même.",
            ],
            uiAction: [
                'type'      => 'signaler-paiement-prime',
                'trancheId' => $trancheId,
            ],
        );
    }
}
