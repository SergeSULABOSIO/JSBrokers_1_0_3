<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Entity\Invite;
use App\Repository\DemandeCongeRepository;
use App\Repository\ParametresCongeRepository;
use App\Repository\PeriodeBlocageRepository;
use App\Services\Search\CongeStatutScope;

/**
 * LES CONTRÔLES DE LA SOUMISSION — un seul jeu, deux canaux.
 *
 * Appelé par le contrôleur, par l'assistant et par les tests. Un refus par CTRL-01 à
 * l'écran est un refus par CTRL-01 via l'assistant, avec le MÊME message : il n'y a
 * qu'une implémentation, donc il ne peut pas y en avoir deux versions.
 *
 * ── SEPT CONTRÔLES, DONT TROIS CONTOURNABLES ────────────────────────────────────────
 *  CTRL-01  solde disponible          — dur
 *  CTRL-02  chevauchement             — dur
 *  CTRL-03  délai de préavis          — contournable par un valideur
 *  CTRL-04  absents simultanés        — contournable par un valideur
 *  CTRL-05  période de blocage        — contournable par un valideur
 *  CTRL-06  cohérence des dates       — dur
 *  CTRL-07  plafond et justificatif   — dur
 *
 * Un contrôle contourné n'est pas un contrôle absent : il devient un AVERTISSEMENT,
 * conservé sur la demande et repris dans le mail de soumission. Un valideur qui passe
 * outre en a le droit ; le cabinet a celui de le savoir.
 *
 * ── CHAQUE CONTRÔLE PARAMÉTRABLE SE DÉSACTIVE ───────────────────────────────────────
 * Préavis à zéro, plafond vide, aucune période active : le contrôle ne s'applique pas.
 * Un cabinet qui ne veut pas d'une règle doit pouvoir l'éteindre franchement, sans quoi
 * il apprendra à la contourner.
 */
class DemandeCongeValidator
{
    public function __construct(
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongeRepository $demandeRepository,
        private readonly ParametresCongeRepository $parametresRepository,
        private readonly PeriodeBlocageRepository $blocageRepository,
        private readonly EquipeDuCollaborateur $equipes,
        private readonly CalculateurJoursOuvrables $calculateurJours,
    ) {
    }

    /**
     * Tout ce qui empêche cette demande d'être soumise, et tout ce qui n'a été franchi
     * que grâce au statut de valideur.
     *
     * @param bool $peutContourner l'acteur est-il valideur ? Les trois contrôles souples
     *                             deviennent alors des avertissements.
     */
    public function controler(DemandeConge $demande, bool $peutContourner = false): ControleConge
    {
        $violations = $this->controlesDurs($demande);

        // Les bases manquent : inutile d'aller chercher un préavis ou un plafond sur une
        // demande dont on ne connaît ni l'agent ni les dates.
        if ($violations !== []) {
            return new ControleConge($violations);
        }

        $souples = $this->controlesSouples($demande);

        return $peutContourner
            ? new ControleConge([], $souples)
            : new ControleConge($souples);
    }

    /**
     * Compatibilité de lecture : la liste de ce qui bloque, sans les avertissements.
     *
     * @return string[]
     */
    public function violationsPourSoumission(DemandeConge $demande, bool $peutContourner = false): array
    {
        return $this->controler($demande, $peutContourner)->violations;
    }

    // ═══════════════════════ CONTRÔLES DURS ═══════════════════════

    /** @return string[] */
    private function controlesDurs(DemandeConge $demande): array
    {
        $violations = [];

        $agent = $demande->getAgent();
        $type = $demande->getTypeAbsence();
        $debut = $demande->getDateDebut();
        $fin = $demande->getDateFin();

        // Les relations métier d'abord : sans elles, aucun autre contrôle n'a de sens.
        if ($agent === null) {
            $violations[] = 'Aucun collaborateur n\'est rattaché à cette demande.';
        }
        if ($type === null) {
            $violations[] = "Le type d'absence est obligatoire.";
        }

        // CTRL-06 — cohérence des dates et décompte strictement positif.
        if ($debut === null || $fin === null) {
            $violations[] = 'Les dates de début et de fin sont obligatoires.';
        } elseif ($fin < $debut) {
            $violations[] = 'La date de fin ne peut pas précéder la date de début.';
        }

        if ($violations !== []) {
            return $violations;
        }

        /** @var Invite $agent */
        /** @var \App\Entity\TypeAbsence $type */
        /** @var \DateTimeImmutable $debut */
        /** @var \DateTimeImmutable $fin */

        $jours = $demande->nbJoursFloat();
        if ($jours <= 0.0) {
            $violations[] = sprintf(
                'Cette période ne contient aucun jour ouvrable pour %s : week-ends, jours fériés et régime de travail retirés, il ne reste rien à décompter.',
                $agent->getNom() ?? 'ce collaborateur',
            );
        }

        // CTRL-07 — le type doit être actif, son plafond respecté, son justificatif joint.
        //
        // La règle « type actif » vit ICI et non dans le formulaire : le formulaire doit
        // continuer à proposer un type désactivé pour qu'une demande ancienne reste
        // éditable, et l'assistant doit se heurter à la même règle que l'écran.
        if (!$type->isActif()) {
            $violations[] = sprintf("Le type d'absence « %s » est désactivé : il ne peut plus être choisi.", (string) $type);
        }

        $plafond = $type->getPlafondParDemande();
        if ($plafond !== null && $jours > (float) $plafond) {
            $violations[] = sprintf(
                'Le type « %s » est plafonné à %s jour(s) par demande ; celle-ci en compte %s.',
                (string) $type,
                $this->formater((float) $plafond),
                $this->formater($jours),
            );
        }

        if (!$type->isAutoriseDemiJournee() && ($demande->isDemiJourneeDebut() || $demande->isDemiJourneeFin())) {
            $violations[] = sprintf("Le type « %s » n'autorise pas les demi-journées.", (string) $type);
        }

        if ($type->isJustificatifRequis() && $demande->getDocuments()->isEmpty()) {
            $violations[] = sprintf("Le type « %s » exige une pièce justificative.", (string) $type);
        }

        // CTRL-02 — aucun chevauchement avec une autre demande active de l'agent.
        $chevauchements = $this->demandeRepository->chevauchements($agent, $debut, $fin, $demande->getId());
        if ($chevauchements !== []) {
            $autre = $chevauchements[0];
            $violations[] = sprintf(
                'Cette période chevauche une demande déjà en cours (%s au %s, %s).',
                $autre->getDateDebut()?->format('d/m/Y') ?? '?',
                $autre->getDateFin()?->format('d/m/Y') ?? '?',
                mb_strtolower(CongeStatutScope::libelle($autre->getStatut())),
            );
        }

        // CTRL-01 — le solde DISPONIBLE, jamais l'acquis.
        //
        // Sur l'acquis, un agent poserait deux fois les mêmes jours en enchaînant deux
        // demandes avant toute décision : les deux passeraient, et le compteur ne
        // deviendrait faux qu'à la seconde approbation.
        if ($type->isDecompte() && $jours > 0.0) {
            $solde = $this->calculateurSolde->pour($agent, (int) $debut->format('Y'));
            // La demande en cours d'édition est déjà comptée dans l'engagé si elle a
            // déjà été soumise : on la neutralise pour ne pas la retrancher deux fois.
            $disponible = $solde->disponible()
                + ($demande->getStatut() === DemandeConge::STATUT_SOUMISE ? $jours : 0.0);

            if ($jours > $disponible) {
                $violations[] = sprintf(
                    'Solde insuffisant : %s jour(s) disponible(s) sur l\'exercice %d, %s demandé(s).',
                    $this->formater($disponible),
                    $solde->exercice,
                    $this->formater($jours),
                );
            }
        }

        return $violations;
    }

    // ═══════════════════════ CONTRÔLES CONTOURNABLES ═══════════════════════

    /** @return string[] */
    private function controlesSouples(DemandeConge $demande): array
    {
        $agent = $demande->getAgent();
        $entreprise = $demande->getEntreprise();
        $debut = $demande->getDateDebut();
        $fin = $demande->getDateFin();

        if ($agent === null || $entreprise === null || $debut === null || $fin === null) {
            return [];
        }

        $parametres = $this->parametresRepository->pourEntreprise($entreprise);
        $messages = [];

        if ($message = $this->controlePreavis($demande, $parametres, $debut)) {
            $messages[] = $message;
        }
        if ($message = $this->controleBlocage($demande, $debut, $fin)) {
            $messages[] = $message;
        }
        if ($message = $this->controleAbsentsSimultanes($demande, $parametres, $agent, $debut, $fin)) {
            $messages[] = $message;
        }

        return $messages;
    }

    /**
     * CTRL-03 — le délai de préavis.
     *
     * Compté en JOURS OUVRABLES, comme le décompte lui-même : un préavis de cinq jours
     * annoncé le vendredi ne doit pas être satisfait par le mercredi suivant sous
     * prétexte que le week-end est passé.
     */
    private function controlePreavis(
        DemandeConge $demande,
        \App\Entity\ParametresConge $parametres,
        \DateTimeInterface $debut,
    ): ?string {
        if (!$parametres->controlePreavis()) {
            return null;
        }

        $aujourdhui = new \DateTimeImmutable('today');
        if ($debut <= $aujourdhui) {
            return sprintf(
                'Cette absence commence le %s : le préavis de %d jour(s) ouvrable(s) ne peut plus être respecté.',
                $debut->format('d/m/Y'),
                $parametres->getDelaiPreavisJours(),
            );
        }

        // Les jours ouvrables ENTRE aujourd'hui et le départ, bornes exclues du calcul du
        // préavis : le jour même ne compte pas, le jour du départ non plus.
        $veille = \DateTimeImmutable::createFromInterface($debut)->modify('-1 day');
        $demain = $aujourdhui->modify('+1 day');

        $ouvrables = $veille < $demain
            ? 0.0
            : $this->calculateurJours->calculer($demande->getAgent(), $demain, $veille);

        if ($ouvrables < $parametres->getDelaiPreavisJours()) {
            return sprintf(
                'Préavis insuffisant : %d jour(s) ouvrable(s) sont demandés, il en reste %s avant le %s.',
                $parametres->getDelaiPreavisJours(),
                $this->formater($ouvrables),
                $debut->format('d/m/Y'),
            );
        }

        return null;
    }

    /** CTRL-05 — la période ne tombe pas dans un blocage déclaré. */
    private function controleBlocage(
        DemandeConge $demande,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
    ): ?string {
        $entreprise = $demande->getEntreprise();
        if ($entreprise === null) {
            return null;
        }

        $blocages = $this->blocageRepository->actifsChevauchant($entreprise, $debut, $fin);
        if ($blocages === []) {
            return null;
        }

        $premier = $blocages[0];

        return sprintf(
            'Cette période tombe dans un blocage : %s (du %s au %s).',
            (string) $premier->getLibelle(),
            $premier->getDateDebut()?->format('d/m/Y') ?? '?',
            $premier->getDateFin()?->format('d/m/Y') ?? '?',
        );
    }

    /**
     * CTRL-04 — le nombre d'absents simultanés dans l'équipe.
     *
     * L'« équipe » est celle du responsable (cf. EquipeDuCollaborateur) : le projet n'a
     * pas de notion de service, et en inventer une pour ce seul contrôle aurait ajouté un
     * concept à maintenir partout.
     */
    private function controleAbsentsSimultanes(
        DemandeConge $demande,
        \App\Entity\ParametresConge $parametres,
        Invite $agent,
        \DateTimeInterface $debut,
        \DateTimeInterface $fin,
    ): ?string {
        if (!$parametres->controleAbsentsSimultanes()) {
            return null;
        }

        $entreprise = $demande->getEntreprise();
        if ($entreprise === null) {
            return null;
        }

        $equipe = [];
        foreach ($this->equipes->collegues($agent) as $collegue) {
            $equipe[$collegue->getId()] = true;
        }
        if ($equipe === []) {
            return null;
        }

        // On ne compte QUE les absences approuvées de l'équipe : une demande encore en
        // attente n'est pas une absence, et la compter refuserait des congés au nom de
        // quelque chose qui pourrait ne jamais arriver.
        $absents = [];
        foreach ($this->demandeRepository->absencesApprouveesSurPeriode($entreprise, $debut, $fin, $agent) as $autre) {
            $id = $autre->getAgent()?->getId();
            if ($id !== null && isset($equipe[$id])) {
                $absents[$id] = $autre;
            }
        }

        $plafond = (int) $parametres->getMaxAbsentsSimultanes();
        if (count($absents) < $plafond) {
            return null;
        }

        $noms = array_map(
            static fn (DemandeConge $d) => (string) ($d->getAgent()?->getNom() ?? '?'),
            array_values($absents),
        );

        return sprintf(
            'Plafond d\'absences atteint : %d collaborateur(s) %s déjà absent(s) sur cette période (%s), pour un maximum de %d.',
            count($absents),
            $this->equipes->estUneVraieEquipe($agent) ? 'de votre équipe' : 'du cabinet',
            implode(', ', $noms),
            $plafond,
        );
    }

    /** « 2,5 » plutôt que « 2.5 » : c'est un nombre de jours, lu par un francophone. */
    private function formater(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',');
    }
}
