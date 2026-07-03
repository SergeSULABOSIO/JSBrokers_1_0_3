<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ChargeCourtier;

class ChargeCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ChargeCourtier $entity */
        return [
            'compteOhadaLabel'          => $entity->getCompteOhadaLabel(),
            'compteOhadaFull'           => sprintf('%s — %s', $entity->getCompteOhada(), $entity->getCompteOhadaLabel()),
            'comportementLabel'         => $entity->getComportementLabel(),
            'periodiciteLabel'          => $entity->getPeriodiciteLabel(),
            'actifLabel'                => $entity->isActif() ? 'Active' : 'Inactive',
            'profilCharge'              => sprintf('%s · %s · %s', $entity->getComportementLabel(), $entity->getPeriodiciteLabel(), $entity->isActif() ? 'Active' : 'Inactive'),
            'montantBudgeteMensuelFloat' => round($entity->getMontantBudgeteMensuelFloat(), 2),
        ];
    }
}
