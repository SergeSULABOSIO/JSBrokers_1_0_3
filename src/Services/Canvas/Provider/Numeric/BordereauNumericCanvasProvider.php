<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Bordereau;

class BordereauNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Bordereau $object */
        return array_merge([
            "montantTTC" => [
                "description" => "Montant TTC",
                "value" => ($object->getMontantTTC() ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
