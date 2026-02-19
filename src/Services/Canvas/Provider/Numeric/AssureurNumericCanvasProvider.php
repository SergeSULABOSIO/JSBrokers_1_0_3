<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Assureur;

class AssureurNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Assureur::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Assureur $object */
        return array_merge([
            "nombrePolicesSouscrites" => [
                "description" => "Nb. Polices",
                "value" => ($object->nombrePolicesSouscrites ?? 0) * 100,
                "is_percentage" => false
            ],
            "nombreSinistresGeres" => [
                "description" => "Nb. Sinistres",
                "value" => ($object->nombreSinistresGeres ?? 0) * 100,
                "is_percentage" => false
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
