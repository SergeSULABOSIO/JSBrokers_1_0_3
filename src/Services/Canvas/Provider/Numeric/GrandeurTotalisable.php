<?php

namespace App\Services\Canvas\Provider\Numeric;

/**
 * UNE GRANDEUR DE LA BARRE DES TOTAUX, ET SA NATURE (chantier 4, décisions de Serge du
 * 2026-10-25).
 *
 * La barre traitait toute valeur comme un montant en centimes : elle additionnait les taux et
 * affichait des comptes en monnaie (« 5,00 $ » pour cinq transactions). Chaque grandeur dit
 * désormais ce qu'elle est, et seules deux natures existent :
 *   - MONTANT : en centimes, entier — la monnaie de travail du cabinet, jamais convertie ;
 *   - NOMBRE  : un compte, entier, tel quel.
 * Une grandeur NON ADDITIVE (taux, indice, cours de change, moyenne) ne passe pas par ici :
 * une somme de taux n'est pas un taux — elle n'a pas sa place dans la barre (décision 1).
 */
final class GrandeurTotalisable
{
    public const MONTANT = 'montant';
    public const NOMBRE = 'nombre';

    /**
     * @return array{description: string, value: int, nature: string}
     */
    public static function montant(string $libelle, int|float|null $valeur): array
    {
        // L'arrondi entier évite les 1234.5600000000001 de la multiplication flottante.
        return ['description' => $libelle, 'value' => (int) round(((float) ($valeur ?? 0)) * 100), 'nature' => self::MONTANT];
    }

    /**
     * @return array{description: string, value: int, nature: string}
     */
    public static function nombre(string $libelle, int|float|null $valeur): array
    {
        return ['description' => $libelle, 'value' => (int) round((float) ($valeur ?? 0)), 'nature' => self::NOMBRE];
    }
}
