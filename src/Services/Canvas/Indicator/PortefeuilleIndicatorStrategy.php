<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Portefeuille;

class PortefeuilleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Portefeuille::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Portefeuille $entity */
        return [
            'nombreClients' => $entity->getClients()->count(),
        ];
    }
}
