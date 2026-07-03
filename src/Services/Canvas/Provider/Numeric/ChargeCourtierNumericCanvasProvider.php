<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\ChargeCourtier;

/**
 * Valeurs numériques totalisables de la liste des Charges du courtier (barre des
 * totaux : total global + total de la sélection). Montants en CENTIMES (contrat
 * du contrôleur Stimulus list-summary). Les propriétés dynamiques proviennent de
 * ChargeCourtierIndicatorStrategy (chargées par CanvasBuilder).
 */
class ChargeCourtierNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargeCourtier::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var ChargeCourtier $object */

        return [
            'montantBudgeteMensuelFloat' => [
                'description' => 'Budget mensuel',
                'value' => ($object->montantBudgeteMensuelFloat ?? $object->getMontantBudgeteMensuelFloat()) * 100,
            ],
        ];
    }
}
