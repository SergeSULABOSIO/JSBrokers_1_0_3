<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\PieceSinistre;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class PieceSinistreIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === PieceSinistre::class;
    }

    public function calculate(object $entity): array
    {
        /** @var PieceSinistre $entity */
        return [
            'agePiece' => $this->calculatePieceSinistreAge($entity),
            'typePieceNom' => $this->getPieceSinistreTypeName($entity),
            'estObligatoire' => $this->getPieceSinistreEstObligatoire($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function calculatePieceSinistreAge(PieceSinistre $piece): string
    {
        if (!$piece->getReceivedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($piece->getReceivedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getPieceSinistreTypeName(PieceSinistre $piece): string
    {
        return $piece->getType() ? $piece->getType()->getNom() : 'Non défini';
    }

    private function getPieceSinistreEstObligatoire(PieceSinistre $piece): string
    {
        return $piece->getType() ? ($piece->getType()->isObligatoire() ? 'Oui' : 'Non') : 'N/A';
    }
}