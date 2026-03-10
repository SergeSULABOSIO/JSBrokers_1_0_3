<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ChargementPourPrime;
use App\Services\ServiceDates;
use DateTimeImmutable;

class ChargementPourPrimeIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ChargementPourPrime::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ChargementPourPrime $entity */
        return [
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($entity->getCotation()),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($entity->getCotation()),
            'montant_final' => round($entity->getMontantFlatExceptionel() ?? 0.0, 2),
            'montantTaxeAppliquee' => 0.0, // Toujours 0 selon l'ancien CalculationProvider
            'poidsSurPrimeTotale' => $this->calculationHelper->getChargementPourPrimePoidsSurPrime($entity),
            'ageChargement' => $this->calculateChargementPourPrimeAge($entity),
            'fonctionChargement' => $this->calculationHelper->Chargement_getFonctionString($entity->getType()),
        ];
    }

    private function calculateChargementPourPrimeAge(ChargementPourPrime $chargement): string
    {
        if (!$chargement->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($chargement->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }
}