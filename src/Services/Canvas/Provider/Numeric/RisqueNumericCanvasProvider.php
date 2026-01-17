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
        return array_merge([
            "pourcentageCommissionSpecifiqueHT" => [
                "description" => "Comm. Spécifique HT",
                "value" => ($object->getPourcentageCommissionSpecifiqueHT() ?? 0),
                "unit" => "%",
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}