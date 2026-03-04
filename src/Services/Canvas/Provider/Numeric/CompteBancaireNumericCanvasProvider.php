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

        $indicators = [
            'soldeActuel' => 'Solde Actuel',
            'totalEntrees' => 'Total Entrées',
            'totalSorties' => 'Total Sorties',
            'nombreTransactions' => 'Nb. Transactions',
            'moyenneTransaction' => 'Moyenne / Trans.',
        ];

        foreach ($indicators as $code => $description) {
            if (property_exists($object, $code)) {
                $attributes[$code] = [
                    'description' => $description,
                    // The frontend expects values to be multiplied by 100 (e.g., cents for currency).
                    // This convention is followed even for counts.
                    'value' => ($object->$code ?? 0) * 100,
                ];
            }
        }

        return $attributes;
    }
}