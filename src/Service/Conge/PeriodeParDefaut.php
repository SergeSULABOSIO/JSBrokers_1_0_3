<?php

namespace App\Service\Conge;

use App\Entity\Invite;
use App\Entity\ParametresConge;
use App\Repository\ParametresCongeRepository;

/**
 * LA PÉRIODE PROPOSÉE À L'OUVERTURE D'UNE DEMANDE.
 *
 * ── POURQUOI ELLE N'EST PLUS « AUJOURD'HUI À AUJOURD'HUI » ──────────────────────────
 * Le formulaire s'ouvrait sur la date du jour, aux deux bornes. Deux défauts, et le même
 * en réalité : la proposition ne pouvait pas être acceptée telle quelle. Elle violait le
 * préavis du cabinet — un congé qui commence aujourd'hui ne laisse aucun délai — et elle
 * ne durait qu'un jour, ce que presque personne ne demande. Chaque saisie commençait donc
 * par corriger les deux champs que l'écran venait de remplir.
 *
 * Un défaut qui doit être effacé avant d'être utilisé n'est pas un défaut : c'est un
 * obstacle poli. — Bastien & Scapin > Charge de travail ; Nielsen 6.
 *
 * ── LES DEUX BORNES SONT DES JOURS TRAVAILLÉS ───────────────────────────────────────
 * Une première version proposait « début + 10 jours de calendrier ». Elle tombait un
 * samedi une fois sur trois, et la période ainsi proposée ne coûtait que sept jours
 * ouvrables : le chiffre que le cabinet avait réglé n'apparaissait nulle part dans ce que
 * l'utilisateur allait voir décompté. Une absence qui commence ou se termine un jour non
 * travaillé n'a d'ailleurs aucun sens à écrire.
 *
 * ── LA DURÉE EST DONC COMPTÉE EN JOURS OUVRABLES ────────────────────────────────────
 * Comme tout le reste du module : la dotation de 26 jours, le solde, le préavis, le
 * décompte lui-même. Une période proposée qui se compterait dans une autre unité que
 * celle affichée à l'enregistrement obligerait à faire la conversion de tête.
 *
 * ── ET LE DÉBUT RESPECTE LE PRÉAVIS, DE LA MÊME FAÇON QUE LE CONTRÔLE ───────────────
 * CTRL-03 compte le préavis en jours ouvrables, bornes exclues. La proposition emprunte
 * exactement le même calculateur : une date proposée qui échouerait au contrôle qui la
 * relit serait pire que pas de proposition du tout.
 */
class PeriodeParDefaut
{
    /**
     * Longueur, en jours OUVRABLES, de la période proposée — bornes incluses.
     *
     * Décision produit, comme la dotation annuelle : une constante nommée plutôt qu'un
     * réglage de plus à tenir. Elle deviendra un paramètre du cabinet le jour où deux
     * cabinets voudront deux valeurs différentes, pas avant.
     */
    public const DUREE_JOURS_OUVRABLES = 10;

    /**
     * Garde-fou du parcours de calendrier.
     *
     * Les deux recherches avancent jour après jour — le seul moyen de tenir compte des
     * week-ends, des jours fériés et du régime de chacun. Un régime mal saisi (aucun jour
     * travaillé) ferait tourner la boucle sans fin ; on s'arrête au bout d'un an.
     */
    private const PARCOURS_MAX_JOURS = 366;

    public function __construct(
        private readonly ParametresCongeRepository $parametresRepository,
        private readonly CalculateurJoursOuvrables $calculateurJours,
    ) {
    }

    /**
     * Le premier jour TRAVAILLÉ qui respecte encore le préavis du cabinet.
     *
     * On avance jour après jour plutôt que d'ajouter le préavis d'un coup : le délai se
     * compte en jours ouvrables, et un préavis de cinq jours posé un mercredi tombe le
     * jeudi de la semaine suivante, pas le lundi.
     */
    public function debut(?Invite $agent): \DateTimeImmutable
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $demain = $aujourdhui->modify('+1 day');
        $preavis = $this->parametresPour($agent)?->getDelaiPreavisJours() ?? 0;

        $candidat = $demain;
        for ($pas = 0; $pas < self::PARCOURS_MAX_JOURS; $pas++, $candidat = $candidat->modify('+1 day')) {
            // Une absence ne commence pas un jour que l'intéressé ne travaille pas.
            if (!$this->estTravaille($agent, $candidat)) {
                continue;
            }

            // Le préavis se mesure entre demain et la VEILLE du départ, bornes incluses :
            // ni le jour même, ni le jour du départ n'y comptent (cf. CTRL-03).
            $veille = $candidat->modify('-1 day');
            $ouvrables = $veille < $demain ? 0.0 : $this->calculateurJours->calculer($agent, $demain, $veille);

            if ($ouvrables >= $preavis) {
                return $candidat;
            }
        }

        return $demain; // Inatteignable en pratique : repli plutôt qu'une boucle sans fin.
    }

    /**
     * Le dernier jour TRAVAILLÉ tel que la période dure exactement la durée usuelle.
     *
     * On compte les jours travaillés en avançant, et l'on s'arrête sur le dernier : la
     * période proposée coûte donc précisément ce que le cabinet a réglé, dans l'unité même
     * où le décompte lui sera annoncé.
     */
    public function fin(?Invite $agent, \DateTimeImmutable $debut): \DateTimeImmutable
    {
        return $this->finPourDuree($agent, $debut, self::DUREE_JOURS_OUVRABLES);
    }

    /**
     * Le dernier jour travaillé tel que la période dure EXACTEMENT $duree jours ouvrables.
     *
     * ── POURQUOI CETTE VARIANTE EXISTE ──────────────────────────────────────────────
     * Quand l'utilisateur déplace la date de début, la date de fin doit suivre en gardant
     * la même LONGUEUR d'absence : quelqu'un qui a ramené sa demande à trois jours puis
     * décale son départ veut toujours trois jours, pas dix. La durée conservée est celle
     * de la période telle qu'elle était avant le geste.
     *
     * Le calcul reste ici, côté serveur : lui seul connaît les jours fériés du cabinet et
     * le régime de l'intéressé. Le refaire dans le navigateur donnerait une seconde
     * réponse à « ce jour compte-t-il ? », et l'écran finirait par contredire le décompte
     * annoncé à l'enregistrement.
     */
    public function finPourDuree(?Invite $agent, \DateTimeImmutable $debut, float $duree): \DateTimeImmutable
    {
        // Une durée nulle ou négative n'a pas de fin à proposer : la période se réduit à
        // son premier jour, que l'utilisateur ajustera.
        $restants = (int) ceil(max(1.0, $duree));
        $jour = $debut;
        $dernier = $debut;

        for ($pas = 0; $pas < self::PARCOURS_MAX_JOURS && $restants > 0; $pas++, $jour = $jour->modify('+1 day')) {
            if (!$this->estTravaille($agent, $jour)) {
                continue;
            }

            $restants--;
            $dernier = $jour;
        }

        return $dernier;
    }

    /** Ce jour compte-t-il pour cet agent ? Ni week-end au sens de son régime, ni férié. */
    private function estTravaille(?Invite $agent, \DateTimeImmutable $jour): bool
    {
        return $this->calculateurJours->calculer($agent, $jour, $jour) > 0.0;
    }

    private function parametresPour(?Invite $agent): ?ParametresConge
    {
        $entreprise = $agent?->getEntreprise();

        return $entreprise === null ? null : $this->parametresRepository->pourEntreprise($entreprise);
    }
}
