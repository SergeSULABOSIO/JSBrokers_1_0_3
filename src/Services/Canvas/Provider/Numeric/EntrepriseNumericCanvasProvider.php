<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Entreprise;

class EntrepriseNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Entreprise::class;
    }

    public function getCanvas(object $object): array
    {
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}
