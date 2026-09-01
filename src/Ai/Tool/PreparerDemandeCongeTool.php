<?php

namespace App\Ai\Tool;

use App\Ai\Scope\AiScope;
use App\Ai\Trousse\AiToolEcriture;
use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Entity\TypeAbsence;
use App\Repository\TypeAbsenceRepository;
use App\Service\Conge\CalculateurJoursOuvrables;
use App\Service\Conge\CalculateurSolde;
use App\Service\Conge\DemandeCongePolicy;
use App\Service\Conge\DemandeCongeValidator;
use App\Service\Conge\NormaliseurDePeriodes;
use App\Service\Conge\PeriodeResolue;
use App\Service\Conge\ResolveurDAgent;
use App\Service\Workspace\WorkspaceAccessResolver;

/**
 * Outil DÉDIÉ à la pose d'une demande de congé.
 *
 * ── AUCUNE LOGIQUE D'ÉCRITURE PROPRE ────────────────────────────────────────────────
 * Il traduit le geste en une opération générique et DÉLÈGUE à preparer_operations — donc
 * au même WorkspaceMutationService : validation, budget, verrou « un seul plan en
 * attente », boutons de validation, exécution, journal. DRY strict, même patron que
 * PreparerMarquageNonRenouvelableTool.
 *
 * La ligne d'historique et le mouvement de compteur, eux, naissent de
 * CongeTransitionListener au moment où le plan s'exécute : l'assistant n'a pas à les
 * connaître, et ne peut donc pas les oublier.
 *
 * ── LA PÉRIODE EST RÉSOLUE PAR LE SERVEUR ───────────────────────────────────────────
 * « La semaine prochaine » devient deux dates ici, jamais dans le modèle, et
 * l'interprétation retenue part dans le récapitulatif de confirmation. Une demande créée
 * sur une date mal comprise coûte plus cher que le tour de dialogue supplémentaire.
 *
 * ── LE DÉCOMPTE ET LE SOLDE SONT ANNONCÉS AVANT D'ÉCRIRE ────────────────────────────
 * Le nombre de jours réellement décompté et le solde avant/après sont calculés par les
 * mêmes services que l'écran, et joints au plan. L'utilisateur voit donc ce qu'il valide.
 */
final class PreparerDemandeCongeTool implements AiToolProduisantUnPlan, AiToolConditionnel, AiToolEcriture
{
    public function __construct(
        private readonly PreparerOperationsTool $preparer,
        private readonly NormaliseurDePeriodes $periodes,
        private readonly ResolveurDAgent $resolveurAgent,
        private readonly TypeAbsenceRepository $typeAbsenceRepository,
        private readonly CalculateurJoursOuvrables $calculateurJours,
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongeValidator $validator,
        private readonly DemandeCongePolicy $policy,
        private readonly WorkspaceAccessResolver $accessResolver,
    ) {
    }

    public function name(): string
    {
        return 'preparer_demande_conge';
    }

    public function description(): string
    {
        return "PRÉPARE une demande de congé et la SOUMET aux valideurs du cabinet — « je voudrais "
            . "poser des congés », « je pars du 3 au 14 », « mets-moi en congé la semaine prochaine », "
            . "« déclare mon arrêt maladie de lundi ». Résout la période en dates, calcule le nombre de "
            . "jours réellement décomptés (week-ends, jours fériés et régime de travail déduits), "
            . "vérifie le solde et les chevauchements, puis présente un plan à valider. "
            . "N'écrit qu'après confirmation explicite de l'utilisateur.";
    }

    public function aiguillage(): string
    {
        return "Poser un congé, demander des vacances, déclarer une absence ou un arrêt maladie. "
            . "Annonce TOUJOURS l'interprétation de la période et le nombre de jours décomptés que je "
            . "te rends : c'est ce que l'utilisateur valide.";
    }

    public function estDisponible(AiScope $scope): bool
    {
        return $this->accessResolver->can($scope->invite, 'DemandeConge', Invite::ACCESS_ECRITURE);
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'periode' => [
                    'type' => 'string',
                    'description' => "Période en langage naturel, telle que l'utilisateur l'a dite "
                        . '(« la semaine prochaine », « du 3 au 7 », « demain »). Ne calcule pas les '
                        . 'dates toi-même : passe-moi ses mots.',
                ],
                'debut' => ['type' => 'string', 'description' => 'Date de début (JJ/MM/AAAA), si elle est déjà connue.'],
                'fin' => ['type' => 'string', 'description' => 'Date de fin (JJ/MM/AAAA), si elle est déjà connue.'],
                'typeAbsence' => [
                    'type' => 'string',
                    'description' => "Libellé ou code du type d'absence (« Congé annuel », « Maladie », « CA »). "
                        . 'Par défaut, le congé annuel.',
                ],
                'motif' => ['type' => 'string', 'description' => 'Motif de la demande, tel que dicté.'],
                'demiJourneeDebut' => ['type' => 'boolean', 'description' => "Le premier jour n'est pris qu'à moitié."],
                'demiJourneeFin' => ['type' => 'boolean', 'description' => "Le dernier jour n'est pris qu'à moitié."],
                'agent' => [
                    'type' => 'string',
                    'description' => "Nom du collaborateur concerné. Omets-le pour l'utilisateur connecté. "
                        . "Poser un congé pour quelqu'un d'autre exige d'être valideur.",
                ],
                'remplacerPlanEnAttente' => [
                    'type' => 'boolean',
                    'description' => "À ne poser que si l'utilisateur a explicitement demandé de remplacer le plan en attente.",
                ],
            ],
        ];
    }

    /** Toute écriture relève du modèle réel : le chemin simulé est neutralisé. */
    public function match(string $question, AiScope $scope): ?array
    {
        return null;
    }

    public function execute(array $args, AiScope $scope): AiToolResult
    {
        // SÉCURITÉ DANS L'OUTIL, JAMAIS DANS LE PROMPT (fail-closed).
        if (!$this->accessResolver->can($scope->invite, 'DemandeConge', Invite::ACCESS_ECRITURE)) {
            return AiToolResult::horsPerimetre('Congés');
        }

        // ── QUI ? ────────────────────────────────────────────────────────────────────
        $resolution = $this->resolveurAgent->resoudre(
            $scope->entreprise,
            isset($args['agent']) ? (string) $args['agent'] : null,
            $scope->invite,
        );

        if ($resolution['introuvable']) {
            return AiToolResult::introuvable(
                sprintf('collaborateur « %s »', trim((string) ($args['agent'] ?? ''))),
                "Aucun collaborateur de ce nom dans ce cabinet. Demande l'orthographe exacte, en UNE "
                . 'ligne. Ne présente AUCUN plan et n\'annonce AUCUN bouton.',
            );
        }
        if ($resolution['ambigu'] !== []) {
            return AiToolResult::ok([
                'pret' => false,
                'ambigu' => array_map(static fn (Invite $i) => (string) $i->getNom(), $resolution['ambigu']),
                'note' => 'Plusieurs collaborateurs portent ce nom. Demande LEQUEL, en UNE ligne, puis '
                    . 'ARRÊTE-TOI : tu me rappelleras au message SUIVANT avec le nom complet. '
                    . 'Ne présente aucun plan tant qu\'il n\'a pas répondu.',
            ]);
        }

        /** @var Invite $agent */
        $agent = $resolution['agent'];

        // POSER UN CONGÉ POUR AUTRUI EXIGE D'ÊTRE VALIDEUR. Sans ce contrôle, n'importe
        // quel collaborateur engagerait le solde d'un collègue.
        if ($agent->getId() !== $scope->invite->getId() && !$this->policy->estValideur($scope->invite)) {
            return AiToolResult::horsPerimetre("Congés d'un autre collaborateur");
        }

        // ── QUAND ? ──────────────────────────────────────────────────────────────────
        $periode = $this->resoudrePeriode($args);
        if ($periode === null) {
            return AiToolResult::ok([
                'pret' => false,
                'aDemander' => [[
                    'champ' => 'periode',
                    'question' => 'Sur quelles dates exactement ? (par exemple : du 12/10/2026 au 20/10/2026)',
                ]],
                'note' => "Je n'ai pas compris la période, et je n'en invente pas : un congé posé sur "
                    . 'de mauvaises dates se répare mal. Pose la question telle quelle, en UNE ligne, '
                    . 'puis ARRÊTE-TOI. Ne présente aucun plan.',
            ]);
        }

        // ── QUOI ? ───────────────────────────────────────────────────────────────────
        $type = $this->resoudreType($args, $scope);
        if ($type === null) {
            return AiToolResult::ok([
                'pret' => false,
                'aDemander' => [[
                    'champ' => 'typeAbsence',
                    'question' => sprintf(
                        "Quel type d'absence ? (%s)",
                        implode(', ', array_map(
                            static fn (TypeAbsence $t) => (string) $t->getLibelle(),
                            $this->typeAbsenceRepository->actifsDe($scope->entreprise),
                        )) ?: 'aucun type actif dans ce cabinet',
                    ),
                ]],
                'note' => "Le type d'absence n'a pas été reconnu. Propose la liste que je te donne, en "
                    . 'UNE ligne, puis ARRÊTE-TOI. Ne présente aucun plan.',
            ]);
        }

        // ── CE QUE ÇA COÛTE, AVANT D'ÉCRIRE ─────────────────────────────────────────
        $demande = $this->esquisser($agent, $type, $periode, $args, $scope);
        $jours = $demande->nbJoursFloat();

        // Les contrôles de la soumission sont ceux de l'écran, mot pour mot : un refus par
        // CTRL-01 ici est un refus par CTRL-01 là-bas, avec le même message. Et les trois
        // contrôles souples se contournent ici comme ailleurs, pour un valideur — le
        // contournement partant dans le plan, donc sous les yeux de qui valide.
        $controle = $this->validator->controler($demande, $this->policy->estValideur($scope->invite));
        $violations = $controle->violations;
        if ($violations !== []) {
            return AiToolResult::ok([
                'pret' => false,
                'bloquant' => implode(' ', $violations),
                'violations' => $violations,
                'note' => 'Cette demande ne peut pas être soumise en l\'état. Dis-le en UNE phrase, avec '
                    . 'la raison exacte que je te donne — ne la reformule pas en la rendant vague. '
                    . 'Ne présente AUCUN plan et n\'annonce AUCUN bouton.',
            ]);
        }

        $solde = $this->calculateurSolde->pour($agent, (int) $periode->debut->format('Y'));

        // ── TRADUCTION EN OPÉRATION GÉNÉRIQUE, PUIS DÉLÉGATION ──────────────────────
        // Le verrou anti-empilement de plans, le budget et les boutons « Valider et
        // exécuter » / « Annuler » viennent de là, jamais d'ici.
        //
        // Le statut est SOUMISE : l'utilisateur qui valide ce plan pose sa demande, il ne
        // fabrique pas un brouillon qu'il faudrait ensuite penser à envoyer.
        $resultat = $this->preparer->execute([
            'operations' => [[
                'op' => 'create',
                'entite' => 'DemandeConge',
                'champs' => [
                    'agent' => (string) $agent->getId(),
                    'typeAbsence' => (string) $type->getId(),
                    'dateDebut' => $periode->debut->format('Y-m-d'),
                    'dateFin' => $periode->fin->format('Y-m-d'),
                    'demiJourneeDebut' => $demande->isDemiJourneeDebut() ? '1' : '0',
                    'demiJourneeFin' => $demande->isDemiJourneeFin() ? '1' : '0',
                    'motif' => (string) ($args['motif'] ?? ''),
                    'statut' => DemandeConge::STATUT_SOUMISE,
                    // RG-22 : le canal est tracé, l'auteur reste l'humain.
                    'origine' => DemandeConge::ORIGINE_KET,
                    // Le contournement voyage avec la demande : sans lui, une demande
                    // posée par un valideur malgré un blocage n'en garderait aucune trace.
                    'controlesContournes' => (string) $controle->contournementsEnTexte(),
                ],
            ]],
            'remplacerPlanEnAttente' => ($args['remplacerPlanEnAttente'] ?? false) === true,
        ], $scope);

        // LE REFUS DU MOTEUR PASSE AVANT TOUT, ET SEUL : ses refus sont des STATUS_OK
        // porteurs de « pret: false », et leur agrafer une consigne « présente le plan »
        // ferait rédiger au modèle un plan en prose sans bouton (plan fantôme).
        if ($resultat->status !== AiToolResult::STATUS_OK || ($resultat->data['pret'] ?? false) !== true) {
            return $resultat;
        }

        $data = $resultat->data;
        $data['recapitulatif'] = [
            'agent' => (string) $agent->getNom(),
            'typeAbsence' => (string) $type,
            'interpretationDeLaPeriode' => $periode->interpretation,
            'du' => $periode->debut->format('d/m/Y'),
            'au' => $periode->fin->format('d/m/Y'),
            'joursOuvrablesDecomptes' => $jours,
            'decompteDuSolde' => $type->isDecompte(),
            'soldeAvant' => $solde->disponible(),
            'soldeApres' => $type->isDecompte() ? $solde->disponible() - $jours : $solde->disponible(),
            // Ce que le statut de valideur a permis de franchir. Vide pour tout le monde
            // d'autre : les mêmes contrôles y sont des refus.
            'controlesContournes' => $controle->avertissements,
        ];
        $data['consigne'] = "Avant le bouton, ANNONCE le récapitulatif : l'interprétation de la période, "
            . "les dates, le nombre de jours décomptés et le solde avant/après. C'est ce que "
            . "l'utilisateur confirme. N'annonce pas la demande comme enregistrée : elle ne le sera "
            . "qu'après son clic."
            . ($controle->aDesContournements()
                ? " ⚠ DIS AUSSI, en toutes lettres, ce que le statut de valideur fait franchir ici : "
                    . implode(' ', $controle->avertissements)
                    . " Ce n'est pas un détail — c'est une règle du cabinet que cette demande enfreint."
                : '');

        return AiToolResult::ok($data, $resultat->uiAction);
    }

    /**
     * Une demande NON PERSISTÉE, montée pour être mesurée : décompte figé et contrôles.
     * Elle ne quitte jamais cette méthode — le plan, lui, ne transporte que des champs.
     */
    private function esquisser(
        Invite $agent,
        TypeAbsence $type,
        PeriodeResolue $periode,
        array $args,
        AiScope $scope,
    ): DemandeConge {
        $demande = new DemandeConge();
        $demande->setAgent($agent);
        $demande->setTypeAbsence($type);
        $demande->setDateDebut($periode->debut);
        $demande->setDateFin($periode->fin);
        $demande->setDemiJourneeDebut((bool) ($args['demiJourneeDebut'] ?? false));
        $demande->setDemiJourneeFin((bool) ($args['demiJourneeFin'] ?? false));
        $demande->setMotif(isset($args['motif']) ? (string) $args['motif'] : null);
        $demande->setEntreprise($scope->entreprise);
        $demande->setOrigine(DemandeConge::ORIGINE_KET);

        $demande->setNbJours(number_format(
            $this->calculateurJours->calculer(
                $agent,
                $periode->debut,
                $periode->fin,
                $demande->isDemiJourneeDebut(),
                $demande->isDemiJourneeFin(),
            ),
            1,
            '.',
            '',
        ));

        return $demande;
    }

    private function resoudrePeriode(array $args): ?PeriodeResolue
    {
        // Deux dates explicites l'emportent : elles ne laissent aucune place au doute.
        if (isset($args['debut'])) {
            $resolue = $this->periodes->resoudre(
                sprintf('du %s au %s', $args['debut'], $args['fin'] ?? $args['debut']),
            );
            if ($resolue instanceof PeriodeResolue) {
                return $resolue;
            }
        }

        $expression = trim((string) ($args['periode'] ?? ''));

        return $expression === '' ? null : $this->periodes->resoudre($expression);
    }

    /**
     * Le type d'absence désigné, par son libellé ou son code. Le congé annuel par défaut :
     * c'est ce qu'on veut dire neuf fois sur dix en parlant de « poser des congés ».
     */
    private function resoudreType(array $args, AiScope $scope): ?TypeAbsence
    {
        $actifs = $this->typeAbsenceRepository->actifsDe($scope->entreprise);
        if ($actifs === []) {
            return null;
        }

        $demande = \App\Ai\AiText::cle((string) ($args['typeAbsence'] ?? ''));

        if ($demande === '') {
            foreach ($actifs as $type) {
                if ($type->getCode() === TypeAbsence::CODE_CONGE_ANNUEL) {
                    return $type;
                }
            }

            return $actifs[0];
        }

        foreach ($actifs as $type) {
            if (\App\Ai\AiText::cle((string) $type->getCode()) === $demande
                || \App\Ai\AiText::cle((string) $type->getLibelle()) === $demande) {
                return $type;
            }
        }

        // Correspondance partielle : on dit « maladie » et rarement « Congé de maladie ».
        foreach ($actifs as $type) {
            $libelle = \App\Ai\AiText::cle((string) $type->getLibelle());
            if ($libelle !== '' && (str_contains($libelle, $demande) || str_contains($demande, $libelle))) {
                return $type;
            }
        }

        return null;
    }
}
