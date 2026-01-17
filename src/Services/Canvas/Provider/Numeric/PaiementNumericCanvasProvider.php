<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Paiement;

class PaiementNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Paiement $object */
        return array_merge([
            "montant" => [
                "description" => "Montant",
                "value" => ($object->getMontant() ?? 0) * 100,
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}