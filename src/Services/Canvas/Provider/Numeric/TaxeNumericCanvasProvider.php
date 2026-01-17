<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Taxe;

class TaxeNumericCanvasProvider implements NumericCanvasProviderInterface
{
    // This entity does not use CalculatedIndicatorsTrait

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Taxe $object */
        return [
            "tauxIARD" => [
                "description" => "Taux IARD",
                "value" => (float)($object->getTauxIARD() ?? '0') * 100,
                "unit" => "%",
            ],
            "tauxVIE" => [
                "description" => "Taux VIE",
                "value" => (float)($object->getTauxVIE() ?? '0') * 100,
                "unit" => "%",
            ],
        ];
    }
}