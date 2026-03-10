<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Taxe;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class TaxeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Taxe::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Taxe $entity */
        return [
            'redevableString' => $this->getTaxeRedevableString($entity),
            'nombreAutorites' => $entity->getAutoriteFiscales()->count(),
            'tauxIARDPercent' => (float)($entity->getTauxIARD() ?? 0) * 100,
            'tauxVIEPercent' => (float)($entity->getTauxVIE() ?? 0) * 100,
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getTaxeRedevableString(Taxe $taxe): string
    {
        return match ($taxe->getRedevable()) {
            Taxe::REDEVABLE_ASSUREUR => "L'assureur",
            Taxe::REDEVABLE_COURTIER => "Le courtier",
            default => "Non défini",
        };
    }
}