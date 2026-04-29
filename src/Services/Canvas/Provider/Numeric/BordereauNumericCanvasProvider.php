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
            "montantCommissionTTC" => [ // Utilise le nom correct de l'attribut calculé
                "description" => "Commission TTC", // Met à jour la description pour plus de clarté
                "value" => ($object->montantCommissionTTC ?? 0) * 100, // Accède directement à la propriété publique
            ],
        ];
    }
}
