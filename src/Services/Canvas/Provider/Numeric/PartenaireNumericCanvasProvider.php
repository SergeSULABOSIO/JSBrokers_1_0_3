<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Partenaire;

class PartenaireNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Partenaire $object */
        return array_merge([
            "nombrePistesApportees" => [
                "description" => "Nb. Pistes",
                "value" => ($object->nombrePistesApportees ?? 0) * 100,
                "is_percentage" => false
            ],
            "nombreClientsAssocies" => [
                "description" => "Nb. Clients",
                "value" => ($object->nombreClientsAssocies ?? 0) * 100,
                "is_percentage" => false
            ],
            "nombrePolicesGenerees" => [
                "description" => "Nb. Polices",
                "value" => ($object->nombrePolicesGenerees ?? 0) * 100,
                "is_percentage" => false
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}