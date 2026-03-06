<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\RevenuPourCourtier;

class RevenuPourCourtierNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var RevenuPourCourtier $object */
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}