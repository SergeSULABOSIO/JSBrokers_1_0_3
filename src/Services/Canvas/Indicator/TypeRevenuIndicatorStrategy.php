<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\TypeRevenu;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class TypeRevenuIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === TypeRevenu::class;
    }

    public function calculate(object $entity): array
    {
        /** @var TypeRevenu $entity */
        return [
            'descriptionModeCalcul' => $this->getTypeRevenuDescriptionModeCalcul($entity),
            'redevableString' => $this->getTypeRevenuRedevableString($entity),
            'sharedString' => $this->getTypeRevenuSharedString($entity),
            'nombreUtilisations' => $this->countTypeRevenuUtilisations($entity),
            'pourcentageDisplay' => ($entity->getPourcentage() ?? 0) * 100,
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getTypeRevenuDescriptionModeCalcul(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        
        return match ($typeRevenu->getModeCalcul()) {
            TypeRevenu::MODE_CALCUL_POURCENTAGE_CHARGEMENT => "Pourcentage sur chargement",
            TypeRevenu::MODE_CALCUL_MONTANT_FLAT => "Montant fixe",
            default => "Non défini",
        };
    }

    private function getTypeRevenuRedevableString(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        
        return match ($typeRevenu->getRedevable()) {
            TypeRevenu::REDEVABLE_CLIENT => "Client",
            TypeRevenu::REDEVABLE_ASSUREUR => "Assureur",
            TypeRevenu::REDEVABLE_REASSURER => "Réassureur",
            TypeRevenu::REDEVABLE_PARTENAIRE => "Partenaire",
            default => "Non défini",
        };
    }

    private function getTypeRevenuSharedString(?TypeRevenu $typeRevenu): ?string
    {
        if ($typeRevenu === null) return null;
        return $typeRevenu->isShared() ? "Oui" : "Non";
    }

    private function countTypeRevenuUtilisations(TypeRevenu $typeRevenu): int
    {
        return $typeRevenu->getRevenuPourCourtiers()->count();
    }
}