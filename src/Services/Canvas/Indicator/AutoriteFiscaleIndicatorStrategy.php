<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\AutoriteFiscale;

class AutoriteFiscaleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function calculate(object $entity): array
    {
        return [];
    }
}