<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Risque;

class RisqueNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Risque $object */
        // Le taux de commission spécifique ne s'additionne pas (chantier 4, décision 1).
        return $this->getCalculatedIndicatorsNumericAttributes($object);
    }
}