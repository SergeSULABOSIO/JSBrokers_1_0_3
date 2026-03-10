<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Monnaie;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class MonnaieIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Monnaie::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Monnaie $entity */
        $taux = (float)$entity->getTauxusd();
        return [
            'fonctionString' => $this->getMonnaieFonctionString($entity),
            'localeString' => $entity->isLocale() ? 'Oui' : 'Non',
            'tauxInverse' => $taux > 0 ? round(1 / $taux, 4) : 0,
            'statutTaux' => $taux == 1 ? 'Pivot' : ($taux > 1 ? 'Forte' : 'Faible'),
            'formatExemple' => "1 000 " . $entity->getCode(),
            'typeDevise' => $entity->isLocale() ? 'Nationale' : 'Étrangère',
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getMonnaieFonctionString(Monnaie $monnaie): string
    {
        return match ($monnaie->getFonction()) {
            Monnaie::FONCTION_AUCUNE => "Aucune",
            Monnaie::FONCTION_SAISIE_ET_AFFICHAGE => "Saisie et Affichage",
            Monnaie::FONCTION_SAISIE_UNIQUEMENT => "Saisie Uniquement",
            Monnaie::FONCTION_AFFICHAGE_UNIQUEMENT => "Affichage Uniquement",
            default => "Non définie",
        };
    }
}