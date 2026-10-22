<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Classeur;
use App\Services\ServiceDates;
use DateTimeImmutable;

class ClasseurIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper,
        // LA FRONTIÈRE DES TÂCHES : un classeur ne compte que les pièces que le lecteur voit.
        private \App\Services\Search\TacheVisibiliteScope $tacheVisibilite,
    ) {
    }

    /** @return list<\App\Entity\Document> */
    private function pieces(Classeur $classeur): array
    {
        return $this->tacheVisibilite->filtrer($classeur->getDocuments());
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Classeur::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Classeur $entity */
        return [
            'nombreDocuments' => \count($this->pieces($entity)),
            'ageClasseur' => $this->calculateClasseurAge($entity),
            'dateDernierAjout' => $this->getClasseurDateDernierAjout($entity),
            'apercuTypesFichiers' => $this->getClasseurApercuTypesFichiers($entity),
            'estVide' => $this->pieces($entity) === [] ? 'Oui' : 'Non',
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
        foreach ($this->pieces($classeur) as $document) {
            if ($document->getCreatedAt() && (!$dateDernierAjout || $document->getCreatedAt() > $dateDernierAjout)) {
                $dateDernierAjout = $document->getCreatedAt();
            }
        }
        return $dateDernierAjout;
    }

    private function getClasseurApercuTypesFichiers(Classeur $classeur): array
    {
        $pieces = $this->pieces($classeur);
        if ($pieces === []) {
            return ['Info' => 'Ce classeur est vide'];
        }

        $typesCount = [];
        foreach ($pieces as $document) {
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