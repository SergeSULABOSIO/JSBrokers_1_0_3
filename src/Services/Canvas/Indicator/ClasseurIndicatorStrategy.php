<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Classeur;
use App\Services\ServiceDates;
use DateTimeImmutable;

class ClasseurIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Classeur $entity */
        return [
            'nombreDocuments' => $entity->getDocuments()->count(),
            'ageClasseur' => $this->calculateClasseurAge($entity),
            'dateDernierAjout' => $this->getClasseurDateDernierAjout($entity),
            'apercuTypesFichiers' => $this->getClasseurApercuTypesFichiers($entity),
            'estVide' => $entity->getDocuments()->isEmpty() ? 'Oui' : 'Non',
        ];
    }

    private function calculateClasseurAge(Classeur $classeur): string
    {
        if (!$classeur->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($classeur->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getClasseurDateDernierAjout(Classeur $classeur): ?\DateTimeInterface
    {
        $dateDernierAjout = null;
        foreach ($classeur->getDocuments() as $document) {
            if ($document->getCreatedAt() && (!$dateDernierAjout || $document->getCreatedAt() > $dateDernierAjout)) {
                $dateDernierAjout = $document->getCreatedAt();
            }
        }
        return $dateDernierAjout;
    }

    private function getClasseurApercuTypesFichiers(Classeur $classeur): array
    {
        if ($classeur->getDocuments()->isEmpty()) {
            return ['Info' => 'Ce classeur est vide'];
        }

        $typesCount = [];
        foreach ($classeur->getDocuments() as $document) {
            $type = strtoupper($this->calculationHelper->getDocumentTypeFichier($document));
            if ($type === 'INCONNU' || $type === '') {
                $type = 'Autre';
            }
            $typesCount[$type] = ($typesCount[$type] ?? 0) + 1;
        }

        $formattedCounts = [];
        foreach ($typesCount as $type => $count) {
            $formattedCounts[$type] = $count . ' fichier(s)';
        }

        return $formattedCounts;
    }
}