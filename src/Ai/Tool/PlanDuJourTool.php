<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Boussole\PlanDuJourService;
use App\Ai\Scope\AiScope;

/**
 * Outil de pilotage : LE PROGRAMME DU JOUR du courtier — la todo list par laquelle
 * Ket ouvre la conversation, redemandable à tout moment (« et mon plan du jour ? »).
 *
 * Il DÉLÈGUE intégralement à PlanDuJourService, qui sert aussi la bulle d'ouverture
 * du chat : les chiffres annoncés par Ket en cours de conversation sont donc, par
 * construction, ceux qui ont été affichés à l'ouverture.
 *
 * Le gating fail-closed vit dans le service (une section dont l'entité est hors
 * périmètre disparaît) ; l'outil ne refuse globalement que si rien ne subsiste.
 */
final class PlanDuJourTool implements AiToolInterface
{
    public function __construct(private readonly PlanDuJourService $planDuJour)
    {
    }

    public function name(): string
    {
        return 'plan_du_jour';
    }

    public function description(): string
    {
        return 'Programme du jour du courtier dans son périmètre : tâches qui lui incombent '
            . '(assignées à lui ou à personne) et tâches de son portefeuille, prochaines actions '
            . 'promises via les feedbacks, polices à renouveler (échues ou sous 30 jours), primes '
            . 'à encaisser auprès des clients, commissions à facturer aux assureurs puis notes de '
            . 'débit émises à recouvrer. Chaque section est triée par urgence et porte le nombre '
            . 'total, le montant en jeu et jusqu\'à ' . PlanDuJourService::MAX_LIGNES_PAR_SECTION
            . ' lignes. À appeler quand l\'utilisateur demande son plan du jour, sa todo list, '
            . 'ce qu\'il doit faire aujourd\'hui, ou par quoi commencer. '
            . 'Ce plan est DÉJÀ AFFICHÉ à l\'ouverture d\'une conversation vide : ne le répète pas '
            . 'intégralement en début de conversation, appuie-toi dessus. '
            . 'Pour le DÉTAIL chiffré d\'un volet, enchaîner sur l\'outil dédié : suivi_impayes '
            . '(primes et commissions), vigie_echeances (pistes, sinistres), '
            . 'saturation_portefeuille (opportunités de vente).';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'section' => [
                    'type' => 'string',
                    'enum' => [
                        'taches_assignees',
                        'taches_portefeuille',
                        'feedbacks_actions',
                        'renouvellements',
                        'primes_dues',
                        'commissions_a_facturer',
                        'commissions_a_recouvrer',
                        'tout',
                    ],
                    'description' => 'Section à restituer, ou « tout » (défaut) pour le programme complet.',
                ],
            ],
            'required' => [],
        ];
    }

    /** Chemin simulé : « plan du jour », « ma journée », « que dois-je faire ». */
    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match(
            '/\b(plan du jour|programme du jour|ma journee|que dois je faire|par quoi (je )?commence|todo|to do)\b/',
            $normalized
        )) {
            return ['section' => 'tout'];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        $demandee = (string) ($args['section'] ?? 'tout');
        $plan = $this->planDuJour->plan($scope->entreprise, $scope->invite);

        $sections = $plan['sections'];
        if ($demandee !== 'tout') {
            $sections = array_values(array_filter(
                $sections,
                static fn (array $s): bool => $s['cle'] === $demandee
            ));
        }

        return AiToolResult::ok([
            'date' => $plan['date'],
            'sections' => $sections,
            'priorite' => $demandee === 'tout' ? $plan['priorite'] : ($sections[0] ?? null),
            // Aucune section : soit tout est traité, soit rien n'est visible dans le
            // périmètre. Ket doit féliciter, pas inventer une tâche.
            'toutAuVert' => $sections === [],
        ]);
    }
}
