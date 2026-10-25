<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Bordereau;

class BordereauNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Bordereau $object */
        return [
            "montantCommissionTTC" => GrandeurTotalisable::montant("Commission TTC", $object->montantCommissionTTC ?? null),
            "montantEncaisse" => GrandeurTotalisable::montant("Montant Encaissé", $object->montantEncaisse ?? null),
        ];
    }
}
