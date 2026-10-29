<?php

namespace App\Service\Liste;

/**
 * COMBIEN DE FICHES PAR PAGE — décisions de Serge (2026-10-29).
 *
 * L'utilisateur choisit, dans la barre du bas, parmi des valeurs PRÉVISIBLES (une liste, pas une
 * saisie) ; 20 par défaut. Le choix n'est pas une préférence enregistrée : il vit avec l'onglet
 * du navigateur (il survit au F5, pas à sa fermeture) et « Réinitialiser » le ramène à 20.
 *
 * Le PLAFOND est le serveur : chaque fiche chargée est décomptée en tokens (lecture) et une page
 * trop longue ralentit la liste. Ce que le navigateur envoie hors de la liste vaut le défaut.
 */
final class TailleDePage
{
    /** Les choix offerts, dans l'ordre du sélecteur. Le dernier est le plafond. */
    public const CHOIX = [10, 20, 50, 100];

    public const PAR_DEFAUT = 20;

    /** La taille demandée, si elle est l'un des choix ; le défaut sinon. */
    public static function depuis(mixed $demande): int
    {
        $taille = is_numeric($demande) ? (int) $demande : null;

        return in_array($taille, self::CHOIX, true) ? $taille : self::PAR_DEFAUT;
    }
}
