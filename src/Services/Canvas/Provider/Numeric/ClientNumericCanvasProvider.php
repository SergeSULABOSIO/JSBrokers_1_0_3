<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Client;

class ClientNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Client::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Client $object */
        return array_merge([
            "nombrePistes" => GrandeurTotalisable::nombre("Nb. Pistes", $object->nombrePistes ?? null),
            "nombrePolices" => GrandeurTotalisable::nombre("Nb. Polices", $object->nombrePolices ?? null),
            "nombreSinistres" => GrandeurTotalisable::nombre("Nb. Sinistres", $object->nombreSinistres ?? null),
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
