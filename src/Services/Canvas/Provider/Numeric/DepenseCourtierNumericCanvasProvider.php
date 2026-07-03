<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\DepenseCourtier;

/**
 * Valeurs numériques totalisables de la liste des Dépenses du courtier (barre des
 * totaux : total global + total de la sélection). Montants en CENTIMES (contrat
 * du contrôleur Stimulus list-summary). Les propriétés dynamiques proviennent de
 * DepenseCourtierIndicatorStrategy (chargées par CanvasBuilder).
 */
class DepenseCourtierNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === DepenseCourtier::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var DepenseCourtier $object */

        return [
            'montantTtc' => [
                'description' => 'Montant TTC',
                'value' => ($object->montantTtc ?? $object->getMontantFloat()) * 100,
            ],
            'montantHt' => [
                'description' => 'Montant HT',
                'value' => ($object->montantHt ?? $object->getMontantHtFloat()) * 100,
            ],
            'tvaDeductible' => [
                'description' => 'TVA déductible',
                'value' => ($object->tvaDeductible ?? $object->getTvaDeductibleFloat()) * 100,
            ],
        ];
    }
}
