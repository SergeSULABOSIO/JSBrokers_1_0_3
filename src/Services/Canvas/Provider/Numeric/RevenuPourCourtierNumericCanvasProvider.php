<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\RevenuPourCourtier;

class RevenuPourCourtierNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var RevenuPourCourtier $object */
        $attributes = [
            "montantFlatExceptionel" => [
                "description" => "Montant Flat",
                "value" => ($object->getMontantFlatExceptionel() ?? 0) * 100,
            ],
            "tauxExceptionel" => [
                "description" => "Taux",
                "value" => $object->getTauxExceptionel() ?? 0,
                "unit" => "%",
            ],
        ];

        return array_merge($attributes, $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}