<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Monnaie;

class MonnaieNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function getCanvas(object $object): array
    {
        // Un taux de change ne s'additionne pas : rien à totaliser (chantier 4, décision 1).
        return [];
    }
}