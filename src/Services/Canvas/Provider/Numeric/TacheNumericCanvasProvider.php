<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Tache;

class TacheNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tache::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}
