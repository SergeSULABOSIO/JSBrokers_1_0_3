<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\CompteBancaire;
use App\Services\Canvas\CalculationProvider;

class CompteBancaireNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function __construct(private CalculationProvider $calculationProvider)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->calculationProvider->getIndicateursSpecifics($object);
    }
}