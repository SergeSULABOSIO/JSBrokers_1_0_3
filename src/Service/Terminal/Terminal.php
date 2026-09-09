<?php

namespace App\Service\Terminal;

/**
 * @file Le type de terminal depuis lequel l'utilisateur travaille.
 * @description Joseara ne sert pas la même surface à tous les appareils : sur
 * un téléphone ou une tablette, on travaille par la CONVERSATION avec Ket ;
 * l'interface complète à quatre colonnes reste l'affaire de l'ordinateur.
 *
 * ── POURQUOI UN TYPE, ET PAS UN BOOLÉEN « estMobile » ──────────────────────
 * Trois besoins distincts se lisent sur le même axe, et un booléen les
 * confondrait :
 *  - QUELLE SURFACE servir (`modeKet()`) — mobile et tablette la partagent ;
 *  - QUEL LIBELLÉ montrer à l'utilisateur (« votre téléphone » ≠ « votre
 *    tablette ») ;
 *  - QUOI MÉMORISER dans le cookie, qui doit pouvoir dire « ordinateur » de
 *    façon explicite pour tenir tête à la détection.
 *
 * ⚠ `modeKet()` est le SEUL endroit qui dit ce que « mobile » englobe. Tout
 * code qui referait le test à la main (`=== MOBILE || === TABLETTE`) créerait
 * une seconde définition, et c'est exactement ainsi qu'une tablette finirait
 * par recevoir une surface et pas l'autre.
 */
enum Terminal: string
{
    case MOBILE = 'mobile';
    case TABLETTE = 'tablette';
    case ORDINATEUR = 'ordinateur';

    /**
     * Cet appareil reçoit-il la coquille « mode Ket » (la conversation en plein
     * écran) plutôt que l'espace de travail à colonnes ?
     */
    public function modeKet(): bool
    {
        return $this !== self::ORDINATEUR;
    }

    /**
     * Lecture TOLÉRANTE d'une valeur venue du dehors (cookie, paramètre d'URL,
     * en-tête) : `null` quand elle ne nomme aucun terminal connu. On ne retombe
     * PAS sur une valeur par défaut ici — c'est au détecteur de décider quoi
     * faire d'une absence, et lui seul connaît les autres indices.
     */
    public static function depuisValeur(?string $valeur): ?self
    {
        if ($valeur === null) {
            return null;
        }

        return self::tryFrom(strtolower(trim($valeur)));
    }
}
