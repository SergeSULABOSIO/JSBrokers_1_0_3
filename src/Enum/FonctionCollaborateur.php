<?php

namespace App\Enum;

/**
 * @file Fonction (poste) d'un collaborateur au sein de son département.
 * @description Quatre niveaux hiérarchiques modifiables à volonté par le
 * super-admin. La fonction porte le niveau hiérarchique (affiché + e-mail) ;
 * l'accès aux rubriques est, lui, déterminé par le département (cf. Departement).
 */
enum FonctionCollaborateur: string
{
    case DIRECTEUR   = 'directeur';
    case RESPONSABLE = 'responsable';
    case CHARGE      = 'charge';
    case ASSISTANT   = 'assistant';

    public function label(): string
    {
        return match ($this) {
            self::DIRECTEUR   => 'Directeur de département',
            self::RESPONSABLE => 'Responsable',
            self::CHARGE      => 'Chargé (agent)',
            self::ASSISTANT   => 'Assistant',
        };
    }

    /** Niveau d'autorité associé à la fonction (affiché + e-mail). */
    public function niveauLabel(): string
    {
        return match ($this) {
            self::DIRECTEUR   => 'Gestion complète',
            self::RESPONSABLE => 'Gestion',
            self::CHARGE      => 'Opérationnel (écriture)',
            self::ASSISTANT   => 'Support (lecture)',
        };
    }

    /** Classe de badge charté (cs-badge--*) pour le rendu de la fonction. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::DIRECTEUR   => 'cs-badge--cobalt',
            self::RESPONSABLE => 'cs-badge--ok',
            self::CHARGE,
            self::ASSISTANT   => 'cs-badge--muted',
        };
    }
}
