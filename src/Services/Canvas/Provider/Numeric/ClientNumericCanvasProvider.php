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
            "nombrePistes" => [
                "description" => "Nb. Pistes",
                "value" => ($object->nombrePistes ?? 0) * 100,
                "is_percentage" => false
            ],
            "nombrePolices" => [
                "description" => "Nb. Polices",
                "value" => ($object->nombrePolices ?? 0) * 100,
                "is_percentage" => false
            ],
            "nombreSinistres" => [
                "description" => "Nb. Sinistres",
                "value" => ($object->nombreSinistres ?? 0) * 100,
                "is_percentage" => false
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
