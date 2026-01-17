<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Cotation;

class CotationNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}
