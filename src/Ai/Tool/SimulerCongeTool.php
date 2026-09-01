<?php

namespace App\Ai\Tool;

use App\Ai\AiText;
use App\Ai\Scope\AiScope;
use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Service\Conge\CalculateurJoursOuvrables;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\NormaliseurDePeriodes;
use App\Service\Conge\ResolveurDAgent;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * COMBIEN COÛTERAIT CE CONGÉ ? — sans rien créer.
 *
 * ── POURQUOI IL EXISTE SÉPARÉMENT ───────────────────────────────────────────────────
 * « Si je pars du 3 au 14, ça me fait combien ? » est une question, pas une demande. Y
 * répondre en créant un plan d'écriture forcerait l'utilisateur à annuler quelque chose
 * qu'il n'avait pas demandé — et un bouton « Valider et exécuter » apparu sans raison est
 * la meilleure façon de faire cliquer par réflexe.
 *
 * ── IL RÉSOUT AUSSI LA PÉRIODE ──────────────────────────────────────────────────────
 * « La semaine prochaine » devient deux dates, par le SERVEUR, avec l'interprétation
 * retenue — que l'assistant doit afficher. C'est ce qui permet à l'utilisateur de
 * corriger AVANT que quoi que ce soit ne s'écrive.
 *
 * ── AUCUNE RÈGLE MÉTIER ICI ─────────────────────────────────────────────────────────
 * Le décompte vient de CalculateurJoursOuvrables, le solde de CalculateurSolde : les
 * mêmes services que l'écran et les e-mails. Ce que l'outil annonce est donc, au chiffre
 * près, ce que l'enregistrement produira.
 */
final class SimulerCongeTool implements AiToolInterface
{
    public function __construct(
        private readonly CalculateurJoursOuvrables $calculateurJours,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly NormaliseurDePeriodes $periodes,
        private readonly ResolveurDAgent $resolveurAgent,
        private readonly DemandeCongePolicy $policy,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'simuler_conge';
    }

    public function description(): string
    {
        return "Calcule le nombre de JOURS OUVRABLES qu'une période de congé coûterait — week-ends, "
            . "jours fériés du cabinet et régime de travail du collaborateur déduits — et annonce le "
            . "solde avant et après. N'ÉCRIT RIEN. À appeler pour « ça me ferait combien de jours ? », "
            . '« est-ce que j\'ai assez de solde pour partir du 3 au 14 ? », « combien de jours ouvrables '
            . "entre telle et telle date ? ». Accepte une période en langage naturel (« la semaine "
            . "prochaine », « du 3 au 7 ») : je la résous en dates et je te rends l'interprétation "
            . 'retenue, que tu DOIS afficher.';
    }

    public function aiguillage(): string
    {
        return "Combien de jours coûterait un congé, ai-je assez de solde, décompte d'une période. "
            . "Affiche TOUJOURS l'interprétation de la période que je te rends : l'utilisateur doit "
            . "pouvoir corriger les dates avant que quoi que ce soit ne s'écrive.";
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'periode' => [
                    'type' => 'string',
                    'description' => "Période en langage naturel, telle que l'utilisateur l'a dite "
                        . '(« la semaine prochaine », « du 3 au 7 », « demain »). Utilise ce paramètre '
                        . 'plutôt que de calculer les dates toi-même.',
                ],
                'debut' => ['type' => 'string', 'description' => 'Date de début (JJ/MM/AAAA), si elle est déjà connue.'],
                'fin' => ['type' => 'string', 'description' => 'Date de fin (JJ/MM/AAAA), si elle est déjà connue.'],
                'demiJourneeDebut' => ['type' => 'boolean', 'description' => 'Le premier jour n\'est pris qu\'à moitié.'],
                'demiJourneeFin' => ['type' => 'boolean', 'description' => 'Le dernier jour n\'est pris qu\'à moitié.'],
                'agent' => ['type' => 'string', 'description' => "Nom du collaborateur. Par défaut, l'utilisateur connecté."],
            ],
        ];
    }

    public function match(string $question, AiScope $scope): ?array
    {
        $normalized = AiText::normalize($question);

        if (preg_match('/\bcombien de jours?\b/', $normalized) && preg_match('/\bconges?\b/', $normalized)) {
            return ['periode' => $question];
        }

        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if (!$this->accessResolver->canRead($scope->invite, 'DemandeConge')) {
            return AiToolResult::horsPerimetre('Congés');
        }

        $resolution = $this->resolveurAgent->resoudre(
            $scope->entreprise,
            isset($args['agent']) ? (string) $args['agent'] : null,
            $scope->invite,
        );

        if ($resolution['agent'] === null) {
            return AiToolResult::introuvable(
                sprintf('collaborateur « %s »', trim((string) ($args['agent'] ?? ''))),
                "Demande l'orthographe exacte du nom. N'invente aucun décompte.",
            );
        }

        /** @var Invite $agent */
        $agent = $resolution['agent'];

        if ($agent->getId() !== $scope->invite->getId() && !$this->policy->estValideur($scope->invite)) {
            return AiToolResult::horsPerimetre('Congés des autres collaborateurs');
        }

        $periode = $this->resoudrePeriode($args);
        if ($periode === null) {
            return AiToolResult::ok([
                'pret' => false,
                'aDemander' => [[
                    'champ' => 'periode',
                    'question' => 'Sur quelles dates exactement ? (par exemple : du 12/10/2026 au 20/10/2026)',
                ]],
                // UN REFUS EXPLICITE VAUT MIEUX QU'UNE DATE INVENTÉE : un congé posé sur
                // une période mal comprise coûte plus cher que le tour de dialogue.
                'note' => "Je n'ai pas compris la période. Pose la question telle quelle, en UNE ligne, "
                    . "et n'invente aucune date. Rappelle-moi au message suivant avec sa réponse.",
            ]);
        }

        [$debut, $fin, $interpretation] = $periode;

        $demiDebut = (bool) ($args['demiJourneeDebut'] ?? false);
        $demiFin = (bool) ($args['demiJourneeFin'] ?? false);

        $jours = $this->calculateurJours->calculer($agent, $debut, $fin, $demiDebut, $demiFin);
        $solde = $this->calculateurSolde->pour($agent, (int) $debut->format('Y'));

        return AiToolResult::ok([
            'pret' => true,
            'agent' => (string) $agent->getNom(),
            'interpretationDeLaPeriode' => $interpretation,
            'du' => $debut->format('d/m/Y'),
            'au' => $fin->format('d/m/Y'),
            'demiJourneeDebut' => $demiDebut,
            'demiJourneeFin' => $demiFin,
            'joursOuvrablesDecomptes' => $jours,
            'soldeAvant' => $solde->disponible(),
            'soldeApres' => $solde->disponible() - $jours,
            'suffisant' => $solde->couvre($jours),
            'exercice' => $solde->exercice,
            'note' => "RIEN N'A ÉTÉ ÉCRIT : c'est une simulation. Annonce l'interprétation de la "
                . "période, le décompte et le solde. Si l'utilisateur veut réellement poser ce congé, "
                . 'il faudra le lui demander explicitement au message suivant.',
        ]);
    }

    /**
     * Les dates, qu'elles viennent d'une expression naturelle ou de deux dates données.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string}|null
     */
    private function resoudrePeriode(array $args): ?array
    {
        // Deux dates explicites l'emportent : elles ne laissent aucune place au doute.
        $debut = isset($args['debut']) ? $this->periodes->resoudre('du ' . $args['debut'] . ' au ' . ($args['fin'] ?? $args['debut'])) : null;
        if ($debut instanceof \App\Service\Conge\PeriodeResolue) {
            return [$debut->debut, $debut->fin, $debut->interpretation];
        }

        $expression = trim((string) ($args['periode'] ?? ''));
        if ($expression === '') {
            return null;
        }

        $resolue = $this->periodes->resoudre($expression);

        return $resolue === null ? null : [$resolue->debut, $resolue->fin, $resolue->interpretation];
    }
}
