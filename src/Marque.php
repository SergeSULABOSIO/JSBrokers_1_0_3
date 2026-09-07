<?php

namespace App;

/**
 * @file Identité de marque de la plateforme.
 * @description Source de vérité pour le nom commercial et les coordonnées
 * publiques. La marque est composée par du code à plusieurs endroits — nom
 * d'expéditeur des e-mails, raison sociale sur la facture, métadonnées des
 * fichiers produits, préfixe des exports : ces points-là lisent la constante,
 * de sorte qu'un changement de marque ne se règle qu'ici.
 *
 * Les gabarits Twig et les traductions écrivent le nom en toutes lettres :
 * une indirection y rendrait le texte illisible pour un gain nul. C'est
 * `tests/Frontend/MarqueUnifieeTest` qui garantit qu'aucune ancienne marque
 * n'y revient.
 *
 * « JS Brokers » reste le nom de code du projet (dépôt, dossiers, classes CSS
 * `jsb-*`, classes `*Jsbx`) : il ne s'agit pas d'un libellé affiché.
 *
 * Classe dédiée et volontairement minimale, sur le modèle de App\Legal\Cgu :
 * on évite d'alourdir la god-class App\Constantes\Constante.
 */
final class Marque
{
    /** Nom commercial affiché à l'utilisateur. */
    public const NOM = 'Joseara';

    /** Nom de domaine public de la plateforme. */
    public const DOMAINE = 'joseara.com';

    /** Adresse de contact publique (expéditeur et boîte de réception). */
    public const CONTACT = 'contact@joseara.com';
}
