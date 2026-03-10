<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Bordereau;
use App\Services\ServiceDates;
use DateTimeImmutable;

class BordereauIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Bordereau $entity */
        return [
            'typeString' => $this->getBordereauTypeString($entity),
            'ageBordereau' => $this->calculateBordereauAge($entity),
            'delaiSoumission' => $this->calculateBordereauDelaiSoumission($entity),
            'nombreDocuments' => $entity->getDocuments()->count(),
            'assureurNom' => $entity->getAssureur()?->getNom() ?? 'N/A',
        ];
    }

    private function getBordereauTypeString(Bordereau $bordereau): string
    {
        return match ($bordereau->getType()) {
            Bordereau::TYPE_BOREDERAU_PRODUCTION => 'Bordereau de production',
            default => 'Type inconnu',
        };
    }

    private function calculateBordereauAge(Bordereau $bordereau): string
    {
        if (!$bordereau->getReceivedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($bordereau->getReceivedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateBordereauDelaiSoumission(Bordereau $bordereau): string
    {
        if (!$bordereau->getCreatedAt() || (!$bordereau->getReceivedAt())) return 'N/A';
        $jours = $this->serviceDates->daysEntre($bordereau->getCreatedAt(), $bordereau->getReceivedAt()) ?? 0;
        return $jours . ' jour(s)';
    }
}