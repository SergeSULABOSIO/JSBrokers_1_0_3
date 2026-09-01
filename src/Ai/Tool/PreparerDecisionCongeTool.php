<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Repository\DemandeCongeRepository;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\DemandeCongeWorkflow;
use App\Service\Conge\ResolveurDAgent;
use App\Service\Workspace\WorkspaceAccessResolver;
use App\Services\Search\CongeStatutScope;

/**
 * Outil DÉDIÉ à la décision sur une demande de congé : approuver, refuser, annuler.
 *
 * ── AUCUNE LOGIQUE D'ÉCRITURE PROPRE ────────────────────────────────────────────────
 * Il traduit le geste en une opération générique et DÉLÈGUE à preparer_operations. La
 * ligne d'historique et le mouvement de compteur naissent ensuite de
 * CongeTransitionListener, au moment où le plan s'exécute : l'assistant n'a pas à les
 * connaître, et ne peut donc pas les oublier.
 *
 * ── LES RÈGLES SONT CELLES DE L'ÉCRAN, MOT POUR MOT ─────────────────────────────────
 * DemandeCongeWorkflow::verifier*() est appelé ici comme il l'est par le picker. Un agent
 * qui demande à l'assistant d'approuver sa propre demande se heurte donc exactement au
 * même refus, avec la même phrase, qu'en cliquant.
 *
 * ── LE COMPTEUR EST ANNONCÉ AVANT LA DÉCISION ───────────────────────────────────────
 * Le solde du collaborateur, avant et après, est joint au plan : approuver dix jours à
 * quelqu'un qui n'en a plus que trois se répare mal, et le valideur doit le voir au
 * moment où il décide, pas après.
 */
final class PreparerDecisionCongeTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    /** Les gestes possibles. « soumettre » n'en est pas un : c'est preparer_demande_conge. */
    private const GESTES = ['approuver', 'refuser', 'annuler'];

    /** Au-delà, on ne propose plus : on demande de préciser. */
    private const MAX_CANDIDATS = 8;

    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly DemandeCongeWorkflow $workflow,
        private readonly DemandeCongePolicy $policy,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly ResolveurDAgent $resolveurAgent,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'preparer_decision_conge';
    }

    public function description(): string
    {
        return "PRÉPARE une décision sur une demande de congé : APPROUVER, REFUSER ou ANNULER — "
            . "« approuve le congé de Marie », « refuse cette demande », « annule mon congé de la "
            . "semaine prochaine ». Vérifie que la décision est possible (nul ne valide sa propre "
            . "demande ; une absence déjà commencée exige un motif), annonce le solde du "
            . "collaborateur avant et après, puis présente un plan à valider. N'écrit qu'après "
            . 'confirmation explicite.';
    }

    public function aiguillage(): string
    {
        return 'Approuver, refuser ou annuler un congé. Annonce TOUJOURS le solde avant/après que je te '
            . "rends : c'est ce sur quoi le valideur décide.";
    }

    public function estDisponible(AiScope $scope): bool
    {
        // Déclaré à qui peut décider, OU à qui peut au moins annuler les siens — c'est-à-dire
        // à tout collaborateur ayant l'écriture. Un outil déclaré sans droit ne serait qu'un
        // refus payé plein tarif à chaque tour.
        return $this->accessResolver->can($scope->invite, 'DemandeConge', Invite::ACCESS_ECRITURE);
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'geste' => [
                    'type' => 'string',
                    'enum' => self::GESTES,
                    'description' => 'La décision à préparer.',
                ],
                'demandeId' => [
                    'type' => 'integer',
                    'description' => "Identifiant de la demande, quand il est déjà connu (par exemple "
                        . "rendu par l'outil « conges »).",
                ],
                'agent' => [
                    'type' => 'string',
                    'description' => "Nom du collaborateur concerné, pour retrouver sa demande quand "
                        . "l'identifiant n'est pas connu.",
                ],
                'commentaire' => [
                    'type' => 'string',
                    'description' => 'Commentaire de décision, tel que dicté. Obligatoire pour annuler '
                        . 'une absence déjà commencée.',
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => "À ne poser que si l'utilisateur a explicitement demandé de remplacer le plan en attente.",
                ],
            ],
            'required' => ['geste'],
        ];
    }

    /** Toute écriture relève du modèle réel : le chemin simulé est neutralisé. */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        if (!$this->accessResolver->can($scope->invite, 'DemandeConge', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre('Congés');
        }

        $geste = (string) ($args['geste'] ?? '');
        if (!in_array($geste, self::GESTES, true)) {
            return AiToolResult::ok([
                'pret' => false,
                'bloquant' => sprintf('Geste inconnu. Valeurs acceptées : %s.', implode(', ', self::GESTES)),
                'note' => "Rappelle cet outil AVEC l'une des valeurs acceptées, dans ce même tour. "
                    . "Ne présente aucun plan et n'annonce aucun bouton.",
            ]);
        }

        $candidats = $this->candidats($args, $geste, $scope);

        if ($candidats === []) {
            return AiToolResult::ok([
                'pret' => false,
                'bloquant' => 'Aucune demande de congé ne correspond dans cet espace de travail.',
                'note' => "Dis-le en UNE phrase et demande de préciser (le collaborateur, ou la "
                    . "période). Tu peux aussi appeler « conges » pour lister ce qui existe. "
                    . "Ne présente AUCUN plan et n'annonce AUCUN bouton.",
            ]);
        }

        if (count($candidats) > 1) {
            return AiToolResult::ok([
                'pret' => false,
                'ambigu' => array_map($this->resumer(...), $candidats),
                'note' => 'Plusieurs demandes correspondent. Demande à l\'utilisateur LAQUELLE, en UNE '
                    . 'ligne, en les présentant par leur collaborateur et leur période — jamais par leur '
                    . 'identifiant, qu\'il ne connaît pas. Puis ARRÊTE-TOI : tu me rappelleras avec '
                    . '« demandeId » au message SUIVANT.',
            ]);
        }

        $demande = $candidats[0];
        $commentaire = trim((string) ($args['commentaire'] ?? ''));

        // LES RÈGLES DE L'ÉCRAN, APPELÉES ICI. Nul ne valide sa propre demande ; une
        // absence commencée ne s'annule pas sans motif. Même service, même phrase.
        $violations = $geste === 'annuler'
            ? $this->workflow->verifierAnnulation($demande, $scope->invite, $commentaire ?: null)
            : $this->workflow->verifierDecision($demande, $scope->invite, $this->decisionDuGeste($geste));

        if ($violations !== []) {
            // LE MOTIF MANQUANT EST UNE QUESTION, PAS UN REFUS : c'est la seule information
            // que l'outil ait le droit de faire demander.
            if ($geste === 'annuler' && $commentaire === '' && $this->manqueLeMotif($violations)) {
                return AiToolResult::ok([
                    'pret' => false,
                    'aDemander' => [[
                        'champ' => 'commentaire',
                        'question' => sprintf(
                            'Cette absence a déjà commencé. Pour quelle raison le congé de %s est-il annulé ?',
                            (string) ($demande->getAgent()?->getNom() ?? 'ce collaborateur'),
                        ),
                    ]],
                    'note' => "Il manque le MOTIF, la SEULE information que tu aies le droit de demander "
                        . "ici. Pose la question telle quelle, en UNE ligne, ne l'invente pas, puis "
                        . 'ARRÊTE-TOI. Ne présente aucun plan.',
                ]);
            }

            return AiToolResult::ok([
                'pret' => false,
                'bloquant' => implode(' ', $violations),
                'violations' => $violations,
                'note' => "Ce geste n'est pas possible. Dis-le en UNE phrase, avec la raison exacte que "
                    . "je te donne — ne la reformule pas en la rendant vague. Ne présente AUCUN plan et "
                    . "n'annonce AUCUN bouton.",
            ]);
        }

        // ── TRADUCTION EN OPÉRATION GÉNÉRIQUE, PUIS DÉLÉGATION ──────────────────────
        $champs = $this->champsDuGeste($geste, $commentaire, $scope);

        $resultat = $this->preparer->execute([
            'operations' => [[
                'op' => 'edit',
                'entite' => 'DemandeConge',
                'id' => $demande->getId(),
                'champs' => $champs,
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        return AiToolResult::ok($this->enrichir($resultat->data, $demande, $geste), $resultat->uiAction);
    }

    /**
     * Les demandes que ce geste peut viser.
     *
     * Un identifiant explicite l'emporte. Sinon on cherche parmi celles qui sont dans le
     * bon état : approuver ou refuser suppose une demande SOUMISE, annuler une demande
     * encore active. Chercher plus large ferait proposer des gestes impossibles.
     *
     * @return DemandeConge[]
     */
    private function candidats(array $args, string $geste, AiScope $scope): array
    {
        if (isset($args['demandeId'])) {
            $demande = $this->demandeRepository->find((int) $args['demandeId']);

            // Scoping entreprise : un identifiant venu du modèle ne traverse jamais les
            // cabinets, et un collaborateur ne voit pas la demande d'un collègue.
            if (!$demande instanceof DemandeConge
                || $demande->getEntreprise()?->getId() !== $scope->entreprise->getId()
                || !$this->policy->peutVoir($scope->invite, $demande)) {
                return [];
            }

            return [$demande];
        }

        $statuts = $geste === 'annuler'
            ? [DemandeConge::STATUT_SOUMISE, DemandeConge::STATUT_APPROUVEE]
            : [DemandeConge::STATUT_SOUMISE];

        // Un agent nommé restreint la recherche ; sinon, le geste porte sur soi (annuler)
        // ou sur la file du cabinet (décider).
        $nom = trim((string) ($args['agent'] ?? ''));
        if ($nom !== '') {
            $resolution = $this->resolveurAgent->resoudre($scope->entreprise, $nom, $scope->invite);
            if ($resolution['agent'] === null) {
                return [];
            }

            return array_slice(
                $this->demandeRepository->pourAgent($resolution['agent'], $statuts, self::MAX_CANDIDATS),
                0,
                self::MAX_CANDIDATS,
            );
        }

        if ($geste === 'annuler') {
            return array_slice(
                $this->demandeRepository->pourAgent($scope->invite, $statuts, self::MAX_CANDIDATS),
                0,
                self::MAX_CANDIDATS,
            );
        }

        return array_slice(
            $this->demandeRepository->fileDAttente($scope->entreprise, self::MAX_CANDIDATS),
            0,
            self::MAX_CANDIDATS,
        );
    }

    /**
     * Les champs que le geste écrit sur la demande.
     *
     * Le valideur et l'horodatage sont posés ICI, depuis le scope : une décision engage
     * celui qui la prend, jamais un identifiant venu du modèle.
     *
     * @return array<string, string>
     */
    private function champsDuGeste(string $geste, string $commentaire, AiScope $scope): array
    {
        $champs = [
            'statut' => match ($geste) {
                'approuver' => DemandeConge::STATUT_APPROUVEE,
                'refuser' => DemandeConge::STATUT_REFUSEE,
                default => DemandeConge::STATUT_ANNULEE,
            },
            // UNE DÉCISION ENGAGE CELUI QUI LA PREND. Le valideur est celui du SCOPE,
            // jamais un identifiant venu du modèle : sans ces deux champs, la colonne
            // « Décidé par » resterait vide et l'abonné de transition enregistrerait
            // l'agent comme auteur de sa propre approbation.
            'valideur' => (string) $scope->invite->getId(),
            // Format attendu par DateTimeType (« Y-m-d\TH:i ») : une date nue y serait
            // refusée, et le champ resterait vide sans un mot.
            'dateDecision' => (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i'),
            // RG-22 : le canal est tracé, l'auteur reste l'humain.
            'origine' => DemandeConge::ORIGINE_KET,
        ];

        if ($commentaire !== '') {
            $champs['commentaireDecision'] = $commentaire;
        }

        return $champs;
    }

    private function decisionDuGeste(string $geste): string
    {
        return $geste === 'approuver'
            ? DemandeCongeWorkflow::DECISION_APPROUVER
            : DemandeCongeWorkflow::DECISION_REFUSER;
    }

    /** @param string[] $violations */
    private function manqueLeMotif(array $violations): bool
    {
        foreach ($violations as $violation) {
            if (str_contains(mb_strtolower($violation), 'motif est obligatoire')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ce que l'assistant doit ANNONCER avant le bouton : la demande visée et le compteur
     * du collaborateur, avant et après.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function enrichir(array $data, DemandeConge $demande, string $geste): array
    {
        $agent = $demande->getAgent();
        $solde = $agent !== null ? $this->calculateurSolde->pour($agent, $demande->getExercice()) : null;
        $jours = $demande->nbJoursFloat();

        // Approuver DÉPLACE les jours de l'engagé vers le consommé : le disponible ne
        // bouge pas. Annuler, en revanche, les recrédite. Le dire évite au modèle de
        // fabriquer une soustraction qui n'a pas lieu d'être.
        $apres = $solde === null ? null : match ($geste) {
            'annuler' => $demande->getStatut() === DemandeConge::STATUT_APPROUVEE && $demande->estDecomptee()
                ? $solde->disponible() + $jours
                : $solde->disponible(),
            'refuser' => $demande->estDecomptee() ? $solde->disponible() + $jours : $solde->disponible(),
            default => $solde->disponible(),
        };

        $data['recapitulatif'] = [
            'geste' => $geste,
            'agent' => (string) ($agent?->getNom() ?? ''),
            'demande' => $this->resumer($demande),
            'soldeAvant' => $solde?->disponible(),
            'soldeApres' => $apres,
        ];

        $data['consigne'] = match ($geste) {
            'approuver' => "Avant le bouton, annonce le collaborateur, la période, le nombre de jours et "
                . "son solde. Le disponible ne change PAS à l'approbation : les jours passent de "
                . "l'engagé au consommé. Ne dis pas que le congé est approuvé tant que l'utilisateur "
                . "n'a pas cliqué.",
            'refuser' => "Avant le bouton, annonce le collaborateur, la période et le commentaire de "
                . "refus s'il y en a un. Les jours engagés lui seront rendus. Ne dis pas que le congé "
                . "est refusé tant que l'utilisateur n'a pas cliqué.",
            default => "Avant le bouton, annonce le collaborateur, la période et ce que l'annulation "
                . "recrédite. Ne dis pas que le congé est annulé tant que l'utilisateur n'a pas cliqué.",
        };

        return $data;
    }

    /** @return array<string, mixed> */
    private function resumer(DemandeConge $demande): array
    {
        return [
            'id' => $demande->getId(),
            'agent' => (string) ($demande->getAgent()?->getNom() ?? ''),
            'type' => (string) ($demande->getTypeAbsence() ?? ''),
            'du' => $demande->getDateDebut()?->format('d/m/Y'),
            'au' => $demande->getDateFin()?->format('d/m/Y'),
            'jours' => $demande->nbJoursFloat(),
            'statut' => CongeStatutScope::libelle($demande->getStatut()),
        ];
    }
}
