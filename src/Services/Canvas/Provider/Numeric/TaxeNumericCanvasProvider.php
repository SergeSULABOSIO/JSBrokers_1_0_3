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
                // Affichage via le VO Pourcentage (convention « entier » portée par l'entité).
                "value" => $object->tauxPourcentage(true)->pourcent(),
                "unit" => "%",
            ],
            "tauxVIE" => [
                "description" => "Taux VIE",
                "value" => $object->tauxPourcentage(false)->pourcent(),
                "unit" => "%",
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}