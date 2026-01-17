<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\TypeRevenu;

class TypeRevenuNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var TypeRevenu $object */
        $attributes = [
            "montantflat" => [
                "description" => "Montant Flat",
                "value" => ($object->getMontantflat() ?? 0) * 100,
            ],
            "pourcentage" => [
                "description" => "Pourcentage",
                "value" => $object->getPourcentage() ?? 0,
                "unit" => "%",
            ],
        ];

        return array_merge($attributes, $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}