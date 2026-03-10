<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\OffreIndemnisationSinistre;
use App\Entity\Paiement;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class OffreIndemnisationSinistreIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === OffreIndemnisationSinistre::class;
    }

    public function calculate(object $entity): array
    {
        /** @var OffreIndemnisationSinistre $entity */
        return [
            'montantPayableCalcule' => round($entity->getMontantPayable() ?? 0.0, 2),
            'compensationVersee' => round($this->getOffreIndemnisationCompensationVersee($entity), 2),
            'soldeAVerser' => round($this->getOffreIndemnisationSoldeAVerser($entity), 2),
            'pourcentagePaye' => $this->getOffreIndemnisationPourcentagePaye($entity),
            'nombrePaiements' => $this->getOffreIndemnisationNombrePaiements($entity),
            'montantMoyenParPaiement' => round($this->getOffreIndemnisationMontantMoyenParPaiement($entity) ?? 0.0, 2),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    private function getOffreIndemnisationSoldeAVerser(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        $montantPayable = $offre_indemnisation->getMontantPayable() ?? 0.0;
        $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre_indemnisation);
        return $montantPayable - $compensationVersee;
    }

    private function getOffreIndemnisationPourcentagePaye(OffreIndemnisationSinistre $offre): ?float
    {
        $montantPayable = $offre->getMontantPayable();
        if ($montantPayable > 0) {
            $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre);
            return round(($compensationVersee / $montantPayable) * 100, 2);
        }
        return 0.0;
    }

    private function getOffreIndemnisationNombrePaiements(OffreIndemnisationSinistre $offre): int
    {
        return $offre->getPaiements()->count();
    }

    private function getOffreIndemnisationMontantMoyenParPaiement(OffreIndemnisationSinistre $offre): ?float
    {
        $nombrePaiements = $this->getOffreIndemnisationNombrePaiements($offre);
        if ($nombrePaiements > 0) {
            $compensationVersee = $this->getOffreIndemnisationCompensationVersee($offre);
            return round($compensationVersee / $nombrePaiements, 2);
        }
        return null;
    }
}