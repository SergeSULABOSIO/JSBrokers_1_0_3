<?php

namespace App\Service\Conge;

use App\Entity\Invite;
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
 * obstacle poli. — Bastien & Scapin > Charge de travail ; Nielsen 6 (reconnaître plutôt
 * que se rappeler : le préavis du cabinet est proposé, pas à retrouver).
 *
 * ── LE DÉBUT RESPECTE LE PRÉAVIS, ET DE LA MÊME FAÇON QUE LE CONTRÔLE ───────────────
 * CTRL-03 compte le préavis en jours OUVRABLES, bornes exclues. La proposition emprunte
 * exactement le même calcul, par le même calculateur : une date proposée qui échouerait
 * au contrôle qui la relit serait pire que pas de proposition du tout.
 *
 * ── LA FIN COUVRE UNE DURÉE USUELLE, BORNES INCLUSES ────────────────────────────────
 * « Dix jours » se lit comme la longueur de l'absence, non comme un décalage : du 7 au
 * 16, et non du 7 au 17. Ce sont des jours de CALENDRIER — la période proposée est un
 * point de départ que l'utilisateur ajuste, et son coût réel en jours ouvrables lui est
 * annoncé à l'enregistrement.
 */
class PeriodeParDefaut
{
    /**
     * Longueur, en jours de calendrier, de la période proposée — bornes incluses.
     *
     * Décision produit, comme la dotation annuelle : une constante nommée plutôt qu'un
     * réglage de plus à tenir. Elle deviendra un paramètre du cabinet le jour où deux
     * cabinets voudront deux valeurs différentes, pas avant.
     */
    public const DUREE_JOURS = 10;

    public function __construct(
        private readonly ParametresCongeRepository $parametresRepository,
        private readonly CalculateurJoursOuvrables $calculateurJours,
    ) {
    }

    /**
     * Le premier jour proposé : le plus proche qui respecte encore le préavis du cabinet.
     *
     * On avance jour après jour plutôt que d'ajouter le préavis d'un coup : le délai se
     * compte en jours OUVRABLES, et un préavis de cinq jours posé un jeudi tombe le jeudi
     * suivant, pas le mardi. Le parcours est borné — un préavis absurde ne doit pas faire
     * tourner la boucle indéfiniment.
     */
    public function debut(?Invite $agent): \DateTimeImmutable
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $parametres = $this->parametresPour($agent);

        $preavis = $parametres?->getDelaiPreavisJours() ?? 0;
        if ($preavis <= 0) {
            // Sans préavis, on propose DEMAIN : le contrôle refuse une absence qui commence
            // aujourd'hui, quel que soit le délai réglé.
            return $aujourdhui->modify('+1 day');
        }

        $candidat = $aujourdhui->modify('+1 day');
        $plafond = $aujourdhui->modify(sprintf('+%d days', ($preavis * 3) + 14));

        while ($candidat <= $plafond) {
            $veille = $candidat->modify('-1 day');
            $ouvrables = $veille < $aujourdhui->modify('+1 day')
                ? 0.0
                : $this->calculateurJours->calculer($agent, $aujourdhui->modify('+1 day'), $veille);

            if ($ouvrables >= $preavis) {
                return $candidat;
            }

            $candidat = $candidat->modify('+1 day');
        }

        return $plafond;
    }

    /** Le dernier jour proposé : la durée usuelle à compter du début, bornes incluses. */
    public function fin(\DateTimeImmutable $debut): \DateTimeImmutable
    {
        return $debut->modify(sprintf('+%d days', self::DUREE_JOURS - 1));
    }

    private function parametresPour(?Invite $agent): ?\App\Entity\ParametresConge
    {
        $entreprise = $agent?->getEntreprise();

        return $entreprise === null ? null : $this->parametresRepository->pourEntreprise($entreprise);
    }
}
