<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\AutoriteFiscale;

class AutoriteFiscaleNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}
