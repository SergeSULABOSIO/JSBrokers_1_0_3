<?php

namespace App\Services\Canvas\Indicator;

/**
 * Interface pour les stratégies de calcul d'indicateurs spécifiques à une entité.
 */
interface IndicatorCalculationStrategyInterface
{
    /**
     * Détermine si cette stratégie peut gérer le calcul pour la classe d'entité donnée.
     */
    public function supports(string $entityClassName): bool;

    /**
     * Calcule et retourne les indicateurs pour l'entité donnée.
     */
    public function calculate(object $entity): array;
}