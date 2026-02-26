<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Piste;

class PisteNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Piste $object */
        // On fusionne les propriétés directes avec les indicateurs calculés (qui incluent maintenant primeTotaleSouscrite)
        return array_merge([
            "primePotentielle" => [
                "description" => "Prime Potentielle",
                "value" => ($object->getPrimePotentielle() ?? 0) * 100,
            ],
            "commissionPotentielle" => [
                "description" => "Commission Potentielle",
                "value" => ($object->getCommissionPotentielle() ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}