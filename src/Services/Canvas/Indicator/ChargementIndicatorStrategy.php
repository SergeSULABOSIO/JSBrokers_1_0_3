<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Chargement;

class ChargementIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Chargement::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Chargement $entity */
        $montantTotal = 0.0;
        $poidsTotal = 0.0;
        $utilisations = $entity->getChargementPourPrimes();
        $nombreUtilisations = $utilisations->count();

        foreach ($utilisations as $utilisation) {
            $montantTotal += $utilisation->getMontantFlatExceptionel() ?? 0.0;
            $poidsTotal += $this->calculationHelper->getChargementPourPrimePoidsSurPrime($utilisation) ?? 0.0;
        }
        $poidsMoyen = ($nombreUtilisations > 0) ? ($poidsTotal / $nombreUtilisations) : 0.0;

        return [
            'fonction_string' => $this->calculationHelper->Chargement_getFonctionString($entity),
            'montantTotalApplique' => round($montantTotal, 2),
            'nombreUtilisations' => $nombreUtilisations,
            'poidsMoyenSurPrime' => round($poidsMoyen, 2),
        ];
    }
}