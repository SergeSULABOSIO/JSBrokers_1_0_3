<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Taxe;

class TaxeNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Taxe $object */
        // Les taux IARD et VIE ne s'additionnent pas (chantier 4, décision 1).
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}