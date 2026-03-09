<?php

namespace App\Services\Canvas\Provider\Numeric;

use App\Entity\Note;

class NoteNumericCanvasProvider implements NumericCanvasProviderInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function getCanvas(object $object): array
    {
        /** @var Note $object */

        return [
            'montantTotal' => [
                'description' => 'Montant Total',
                'value' => ($object->montantTotal ?? 0) * 100,
            ],
            'solde' => [
                'description' => 'Solde',
                'value' => ($object->solde ?? 0) * 100,
            ]
        ];
    }
}