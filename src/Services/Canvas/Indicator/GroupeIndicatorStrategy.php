<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Groupe;
use App\Entity\Tache;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class GroupeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Groupe::class;
    }

    public function calculate(object $entity): array
    {
        
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

}