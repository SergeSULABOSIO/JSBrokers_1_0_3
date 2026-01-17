<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Tranche;

class TrancheNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}
