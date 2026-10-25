<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\OffreIndemnisationSinistre;

class OffreIndemnisationSinistreNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === OffreIndemnisationSinistre::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var OffreIndemnisationSinistre $object */
        return array_merge([
            "franchiseAppliquee" => GrandeurTotalisable::montant("Franchise", $object->getFranchiseAppliquee()),
        ], $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}
