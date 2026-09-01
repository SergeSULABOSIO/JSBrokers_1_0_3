<?php

namespace App\Service\Conge;

use App\Entity\DemandeConge;
use App\Repository\DemandeCongeRepository;

/**
 * LES CONTRÔLES DE LA SOUMISSION — un seul jeu, deux canaux.
 *
 * Appelé par le contrôleur, par l'assistant et par les tests. Un refus par CTRL-01 à
 * l'écran est un refus par CTRL-01 via l'assistant, avec le MÊME message : il n'y a
 * qu'une implémentation, donc il ne peut pas y en avoir deux versions.
 *
 * Rend une liste de messages, jamais une exception : l'appelant décide s'il s'agit d'un
 * 422, d'un plan refusé ou d'un simple avertissement affiché avant l'envoi.
 *
 * Périmètre du lot 1 : CTRL-01, CTRL-02, CTRL-06 et CTRL-07. Le délai de préavis
 * (CTRL-03), le nombre d'absents simultanés (CTRL-04) et les périodes de blocage
 * (CTRL-05) demandent un écran de paramétrage qui n'existe pas encore ; les ajouter ici
 * sans lui reviendrait à coder leurs valeurs en dur, c'est-à-dire à décider à la place
 * du cabinet.
 */
class DemandeCongeValidator
{
    public function __construct(
        private readonly CalculateurSolde $calculateurSolde,
        private readonly DemandeCongeRepository $demandeRepository,
    ) {
    }

    /**
     * Tout ce qui empêche cette demande d'être soumise.
     *
     * @return string[] messages destinés à l'utilisateur, vides si tout va bien
     */
    public function violationsPourSoumission(DemandeConge $demande): array
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
            return $violations; // Inutile d'aller plus loin : les bases manquent.
        }

        /** @var \App\Entity\Invite $agent */
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
                mb_strtolower(\App\Services\Search\CongeStatutScope::libelle($autre->getStatut())),
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

    /** « 2,5 » plutôt que « 2.5 » : c'est un nombre de jours, lu par un francophone. */
    private function formater(float $jours): string
    {
        return rtrim(rtrim(number_format($jours, 1, ',', ' '), '0'), ',');
    }
}
