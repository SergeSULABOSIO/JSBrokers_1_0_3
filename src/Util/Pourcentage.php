<?php

namespace App\Util;

/**
 * Value object IMMUABLE représentant un pourcentage, source UNIQUE de vérité pour
 * l'interprétation stockage ⇄ calcul ⇄ affichage.
 *
 * Le projet stocke les pourcentages selon DEUX conventions historiques, cause
 * récurrente de bugs (×100 / ÷100 éparpillés) :
 *  - FRACTION (0.16 = 16 %) : Risque (pourcentageCommissionSpecifiqueHT),
 *    Partenaire (part), ConditionPartage (taux), TypeRevenu (pourcentage),
 *    RevenuPourCourtier (tauxExceptionel)… ;
 *  - POURCENTAGE ENTIER (16 = 16 %) : Taxe (tauxIARD/tauxVIE, PercentType type=integer).
 *
 * On NE change PAS le stockage : on encapsule la conversion ICI. La convention
 * de chaque champ est déclarée UNE FOIS, au plus près de la donnée (accesseurs
 * d'entité renvoyant un Pourcentage), et tout le monde manipule ensuite le VO :
 *  - CALCUL   : appliquerA($base) (= base × fraction) — plus jamais ×taux ni ×taux/100 ;
 *  - AFFICHAGE: pourcent() (le nombre, ex. 16.0) ou format() (« 16,00 % »).
 */
final class Pourcentage
{
    private function __construct(private readonly float $fraction)
    {
    }

    /** Depuis une FRACTION (0.16 → 16 %). */
    public static function fromFraction(?float $fraction): self
    {
        return new self((float) ($fraction ?? 0.0));
    }

    /** Depuis un POURCENTAGE ENTIER (16 → 16 %). Accepte string (décimal Doctrine). */
    public static function fromPourcent(int|float|string|null $pourcent): self
    {
        return new self(((float) ($pourcent ?? 0.0)) / 100.0);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    /** Fraction (pour multiplier une assiette) : 16 % → 0.16. */
    public function fraction(): float
    {
        return $this->fraction;
    }

    /** Nombre en pour-cent (pour l'affichage) : 0.16 → 16.0. */
    public function pourcent(): float
    {
        return $this->fraction * 100.0;
    }

    /** Applique le pourcentage à une assiette : base × fraction. */
    public function appliquerA(float $base): float
    {
        return $base * $this->fraction;
    }

    public function estNul(): bool
    {
        return abs($this->fraction) < 1e-12;
    }

    /** Représentation lisible : « 16,00 % » (séparateur décimal FR par défaut). */
    public function format(int $scale = 2, string $suffixe = ' %', string $sepDecimal = ',', string $sepMilliers = ' '): string
    {
        return number_format($this->pourcent(), $scale, $sepDecimal, $sepMilliers) . $suffixe;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
