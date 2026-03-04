<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\TypeRevenu;
use App\Services\ServiceMonnaies;

class TypeRevenuNumericCanvasProvider implements NumericCanvasProviderInterface
{
    use CalculatedIndicatorsNumericProviderTrait;

    public function __construct(
        private ServiceMonnaies $serviceMonnaies
    )
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var TypeRevenu $object */
        $attributes = [
            "montantflat" => [
                "description" => "Montant Fixe",
                "value" => $object->getMontantflat() ?? 0,
                "unit" => $this->serviceMonnaies->getCodeMonnaieAffichage(),
            ],
            "pourcentage" => [
                "description" => "Pourcentage",
                "value" => ($object->getPourcentage() ?? 0) * 100,
                "unit" => "%",
            ],
        ];

        return array_merge($attributes, $this->getCalculatedIndicatorsNumericAttributes($object));
    }
}