<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\CompteBancaire;
use App\Services\Canvas\CalculationProvider;

class CompteBancaireNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function __construct(private CalculationProvider $calculationProvider)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var \App\Entity\CompteBancaire $object */

        // The calculated values are loaded onto the object by the CanvasBuilder.
        // This provider's job is to format them for the numeric summary view.
        $attributes = [];

        // Montants en centimes, le nombre de transactions tel quel ; la moyenne par transaction
        // ne s'additionne pas (chantier 4, décision 1).
        $indicators = [
            'soldeActuel' => ['Solde Actuel', GrandeurTotalisable::MONTANT],
            'totalEntrees' => ['Total Entrées', GrandeurTotalisable::MONTANT],
            'totalSorties' => ['Total Sorties', GrandeurTotalisable::MONTANT],
            'nombreTransactions' => ['Nb. Transactions', GrandeurTotalisable::NOMBRE],
        ];

        foreach ($indicators as $code => [$description, $nature]) {
            if (property_exists($object, $code)) {
                $attributes[$code] = $nature === GrandeurTotalisable::NOMBRE
                    ? GrandeurTotalisable::nombre($description, $object->$code ?? 0)
                    : GrandeurTotalisable::montant($description, $object->$code ?? 0);
            }
        }

        return $attributes;
    }
}