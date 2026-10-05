<?php

namespace App\Services\Canvas\Autocomplete;

/**
 * CE QU'UN CHIFFRE EST, et donc comment il se lit.
 *
 * Trois genres suffisent à couvrir les dix-huit champs de relation du produit. Le genre
 * ne décide pas d'une couleur ou d'un format pour le plaisir de varier : il décide de ce
 * que le lecteur doit comprendre sans qu'on le lui écrive.
 */
enum GenreDeChiffre
{
    /** Une somme posée ou encaissée. Elle se lit, elle ne s'interprète pas. */
    case Montant;

    /**
     * Un reste.
     *
     * Le seul genre qui porte un jugement : zéro est une bonne nouvelle, non-zéro une
     * chose à faire. D'où une couleur — mais jamais seule (WCAG 1.4.1) : « 0,00 » dit le
     * soldé, un nombre non nul dit le reste, et le signe moins dit le trop-perçu.
     */
    case Solde;

    /** Un pourcentage. Il distingue deux lignes de même libellé mieux qu'un montant. */
    case Taux;
}
