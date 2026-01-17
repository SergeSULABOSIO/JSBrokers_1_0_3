<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Note;
use App\Entity\Paiement;

class NoteNumericCanvasProvider implements NumericCanvasProviderInterface
{
    // This entity does not use CalculatedIndicatorsTrait

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Note $object */
        
        $totalPaiements = array_reduce($object->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);

        return [
            'montant_total_paiements' => [
                'description' => 'Total Paiements',
                'value' => $totalPaiements * 100,
            ]
        ];
    }
}