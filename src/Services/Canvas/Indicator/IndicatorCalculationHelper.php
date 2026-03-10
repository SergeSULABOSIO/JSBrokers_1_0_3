<?php

// src/Services/Canvas/Indicator/IndicatorCalculationHelper.php
namespace App\Services\Canvas\Indicator;

class IndicatorCalculationHelper
{
    /**
     * Fournit une interprétation textuelle d'un taux de sinistralité (S/P).
     */
    public function getInterpretationTauxSP(float $taux): string
    {
        if ($taux == 0) {
            return "Aucun sinistre enregistré ou prime nulle.";
        }
        if ($taux < 70) {
            return "Excellent. Le portefeuille est très rentable.";
        } elseif ($taux <= 80) {
            return "Sain. Équilibre classique.";
        } elseif ($taux <= 100) {
            return "Prudence. Rentabilité faible.";
        }
        return "Déficitaire. Pertes techniques.";
    }
}
