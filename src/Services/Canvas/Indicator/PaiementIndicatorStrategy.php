<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Paiement;

class PaiementIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Paiement $entity */
        return [
            'typePaiement' => $this->calculationHelper->getPaiementTypePaiement($entity),
            'contexte' => $this->calculationHelper->getPaiementContexte($entity),
            'referencePolice' => $this->calculationHelper->getPaiementReferencePolice($entity),
            'clientNom' => $this->calculationHelper->getPaiementClientNom($entity),
            'montantPaiement' => $this->calculationHelper->getPaiementMontantPaiement($entity),
        ];
    }
}