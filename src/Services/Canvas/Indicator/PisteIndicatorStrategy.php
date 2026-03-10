<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Piste;
use App\Entity\Tache;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class PisteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function calculate(object $entity): array
    {
        
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

}