<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\AutoriteFiscale;
use App\Entity\Note;

class AutoriteFiscaleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(private IndicatorCalculationHelper $calculationHelper)
    {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === AutoriteFiscale::class;
    }

    public function calculate(object $entity): array
    {
        /** @var AutoriteFiscale $entity */
        $stats = $this->calculateAutoriteFiscaleStats($entity);

        return [
            'taxeDue' => round($stats['due'], 2),
            'taxePayee' => round($stats['paid'], 2),
            'taxeSolde' => round($stats['balance'], 2),
        ];
    }

    private function calculateAutoriteFiscaleStats(AutoriteFiscale $autorite): array
    {
        $due = 0.0;
        $paid = 0.0;

        // On parcourt toutes les notes adressées à cette autorité
        foreach ($autorite->getNotes() as $note) {
            if ($note->getType() === Note::TYPE_NOTE_DE_DEBIT) {
                $due += $this->calculationHelper->getNoteMontantPayable($note);
                $paid += $this->calculationHelper->getNoteMontantPaye($note);
            }
        }

        return [
            'due' => $due,
            'paid' => $paid,
            'balance' => $due - $paid,
        ];
    }
}