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
            "part" => [
                "description" => "Part",
                "value" => ($object->getPart() ?? 0) * 100,
                "unit" => "%",
            ],
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}