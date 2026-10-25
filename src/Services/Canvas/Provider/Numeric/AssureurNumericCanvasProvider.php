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
            "nombrePolicesSouscrites" => GrandeurTotalisable::nombre("Nb. Polices", $object->nombrePolicesSouscrites ?? null),
            "nombreSinistresGeres" => GrandeurTotalisable::nombre("Nb. Sinistres", $object->nombreSinistresGeres ?? null),
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
