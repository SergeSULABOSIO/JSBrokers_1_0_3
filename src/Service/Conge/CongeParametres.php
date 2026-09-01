<?php

namespace App\Service\Conge;

/**
 * Les valeurs de référence du module de congés.
 *
 * Classe dédiée et volontairement minimale, dans l'esprit de App\Token\TokenPricing et
 * de App\Legal\Cgu : on n'alourdit pas la god-class App\Constantes\Constante, et on ne
 * disperse pas non plus un chiffre de référence au fil des services qui l'utilisent.
 *
 * ── POURQUOI 26 JOURS ───────────────────────────────────────────────────────────────
 * C'est une DÉCISION PRODUIT, prise avec le cabinet, pas une règle déduite d'un texte.
 * Elle vaut pour tous les cabinets à leur création ; le valideur ajuste ensuite le
 * compteur de chacun par un mouvement motivé, qui laisse une trace — là où changer une
 * constante n'en laisserait aucune.
 *
 * Ce forfait ne sert qu'au PROVISIONNEMENT. Passé ce moment, la vérité du compteur est
 * le journal des mouvements, et lui seul : rien ici ne se relit pour calculer un solde.
 */
final class CongeParametres
{
    /** Dotation annuelle par défaut, en jours ouvrables, sur le type « Congé annuel ». */
    public const DOTATION_ANNUELLE_DEFAUT = 26.0;

    /**
     * Arrondi de la dotation au prorata : au DEMI-JOUR SUPÉRIEUR.
     *
     * Supérieur, parce que l'arrondi d'un droit se fait en faveur de celui qui le
     * détient — et parce qu'un demi-jour de trop coûte moins cher au cabinet qu'une
     * réclamation.
     */
    public const PAS_ARRONDI = 0.5;

    /**
     * Dotation d'un agent entré en cours d'exercice : au prorata des MOIS ENTIERS de
     * présence restant à courir, bornes incluses.
     *
     * Les mois entiers, et non les jours : c'est la maille dans laquelle les congés se
     * discutent, et elle évite d'avoir à trancher ce que vaut une entrée le 17.
     */
    public static function dotationAuProrata(
        float $dotationAnnuelle,
        \DateTimeInterface $entree,
        int $exercice,
    ): float {
        $anneeEntree = (int) $entree->format('Y');

        if ($anneeEntree < $exercice) {
            return self::arrondir($dotationAnnuelle); // Présent toute l'année.
        }

        if ($anneeEntree > $exercice) {
            return 0.0; // Pas encore arrivé : aucun droit sur cet exercice.
        }

        // Mois de l'entrée compris : quelqu'un qui arrive le 3 mars a bien travaillé en
        // mars. On compte donc de son mois d'entrée à décembre, bornes incluses.
        $moisPresents = 13 - (int) $entree->format('n');

        return self::arrondir($dotationAnnuelle * $moisPresents / 12);
    }

    /**
     * Dotation d'un collaborateur qui QUITTE le cabinet : au prorata des mois entiers
     * réellement passés dans l'exercice, entrée et sortie prises en compte.
     *
     * ── POURQUOI UNE SECONDE FORMULE ────────────────────────────────────────────────
     * L'entrée seule ne suffit plus : quelqu'un arrivé en mars et parti en juin n'a pas
     * droit aux dix mois que `dotationAuProrata` lui accorderait. On borne donc des DEUX
     * côtés — c'est la même règle des mois entiers, appliquée à un intervalle fermé.
     *
     * Le mois de sortie compte : partir le 3 juin, c'est avoir travaillé en juin.
     */
    public static function dotationAuProrataDeSortie(
        float $dotationAnnuelle,
        ?\DateTimeInterface $entree,
        \DateTimeInterface $sortie,
        int $exercice,
    ): float {
        if ((int) $sortie->format('Y') < $exercice) {
            return 0.0; // Parti avant l'exercice : aucun droit dessus.
        }

        // Premier mois de présence dans l'exercice : celui de l'entrée si elle y tombe,
        // janvier sinon.
        $premierMois = ($entree !== null && (int) $entree->format('Y') === $exercice)
            ? (int) $entree->format('n')
            : 1;

        // Dernier mois : celui de la sortie si elle tombe dans l'exercice, décembre sinon.
        $dernierMois = (int) $sortie->format('Y') === $exercice ? (int) $sortie->format('n') : 12;

        if ($dernierMois < $premierMois) {
            return 0.0;
        }

        return self::arrondir($dotationAnnuelle * ($dernierMois - $premierMois + 1) / 12);
    }

    /** Arrondi au demi-jour supérieur. */
    public static function arrondir(float $jours): float
    {
        return ceil($jours / self::PAS_ARRONDI) * self::PAS_ARRONDI;
    }
}
