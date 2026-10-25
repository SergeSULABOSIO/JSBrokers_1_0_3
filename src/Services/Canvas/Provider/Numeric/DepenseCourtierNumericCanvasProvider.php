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
            'montantTtc' => GrandeurTotalisable::montant('Montant TTC', $object->montantTtc ?? $object->getMontantFloat()),
            'montantHt' => GrandeurTotalisable::montant('Montant HT', $object->montantHt ?? $object->getMontantHtFloat()),
            'tvaDeductible' => GrandeurTotalisable::montant('TVA déductible', $object->tvaDeductible ?? $object->getTvaDeductibleFloat()),
        ];
    }
}
