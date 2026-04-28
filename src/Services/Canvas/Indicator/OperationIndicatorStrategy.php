<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Operation;

class OperationIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Operation::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Operation $entity */
        $montantHT = $entity->getMontantHT() ?? 0.0;
        $montantTaxe = $entity->getMontantTaxe() ?? 0.0;
        $montantTTC = $montantHT + $montantTaxe;

        return [
            'montantTTC' => $montantTTC,
        ];
    }
}