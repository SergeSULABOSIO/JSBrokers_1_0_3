<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Monnaie;

class MonnaieNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Monnaie $object */
        return [
            "tauxusd" => [
                "description" => "Taux de change USD",
                "value" => ($object->getTauxusd() ?? 0),
                "unit" => "",
            ],
        ];
    }
}