<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ModelePieceSinistre;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class ModelePieceSinistreIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ModelePieceSinistre::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ModelePieceSinistre $entity */
        return [
            'nombreUtilisations' => $this->countModelePieceSinistreUtilisations($entity),
            'statutObligation' => $this->getModelePieceSinistreStatutObligationString($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function countModelePieceSinistreUtilisations(ModelePieceSinistre $modele): int
    {
        return $modele->getPieceSinistres()->count();
    }

    private function getModelePieceSinistreStatutObligationString(ModelePieceSinistre $modele): string
    {
        return $modele->isObligatoire() ? 'Obligatoire' : 'Facultative';
    }
}