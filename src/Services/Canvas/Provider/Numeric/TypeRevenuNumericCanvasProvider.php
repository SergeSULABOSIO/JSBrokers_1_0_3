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
        // Le montant fixe EN CENTIMES (il ne l'était pas : la barre l'affichait cent fois trop
        // petit) ; le pourcentage ne s'additionne pas (chantier 4, décision 1).
        $attributes = [
            "montantflat" => GrandeurTotalisable::montant("Montant Fixe", $object->getMontantflat()),
        ];

        return array_merge($attributes, $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}