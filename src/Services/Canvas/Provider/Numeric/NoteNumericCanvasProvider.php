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
            'montantTotal' => GrandeurTotalisable::montant('Montant Total', $object->montantTotal ?? null),
            'solde' => GrandeurTotalisable::montant('Solde', $object->solde ?? null),
        ];
    }
}