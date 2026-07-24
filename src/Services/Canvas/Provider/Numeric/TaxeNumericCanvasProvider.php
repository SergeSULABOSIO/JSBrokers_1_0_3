<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Taxe;

class TaxeNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Taxe $object */
        return array_merge([
            "tauxIARD" => [
                "description" => "Taux IARD",
                // Taux stocké en POURCENTAGE ENTIER (TaxeType : PercentType type=integer,
                // « 16 pour 16% ») → afficher tel quel, sans ×100 (qui donnait 1600%).
                "value" => (float)($object->getTauxIARD() ?? '0'),
                "unit" => "%",
            ],
            "tauxVIE" => [
                "description" => "Taux VIE",
                "value" => (float)($object->getTauxVIE() ?? '0'),
                "unit" => "%",
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}