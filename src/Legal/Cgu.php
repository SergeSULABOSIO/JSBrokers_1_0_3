<?php

namespace App\Legal;

/**
 * @file Référence des Conditions et Termes d'Utilisation (CGU) de JS Brokers.
 * @description Source de vérité pour le versionnage des CGU. La VERSION et la DATE
 * sont affichées sur la page publique des conditions et enregistrées dans le compte
 * de l'utilisateur au moment où il accepte (preuve d'acceptation : qui a accepté
 * quelle version et quand). Incrémenter VERSION et mettre à jour DATE à chaque
 * révision substantielle du contrat — cela permettra, le cas échéant, de demander
 * une nouvelle acceptation aux comptes existants.
 *
 * Classe dédiée et volontairement minimale : on évite d'alourdir la god-class
 * App\Constantes\Constante.
 */
final class Cgu
{
    /** Version courante des conditions. */
    public const VERSION = '1.0';

    /** Date (format ISO) de la dernière mise à jour des conditions. */
    public const DATE = '2026-06-18';
}
