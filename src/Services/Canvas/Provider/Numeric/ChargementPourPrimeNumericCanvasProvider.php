<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\ChargementPourPrime;

class ChargementPourPrimeNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var ChargementPourPrime $object */
        return array_merge([
            "montantFlatExceptionel" => [
                "description" => "Montant",
                "value" => ($object->getMontantFlatExceptionel() ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
