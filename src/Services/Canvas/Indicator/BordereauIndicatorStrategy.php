<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Bordereau;
use App\Services\ServiceDates;
use DateTimeImmutable;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;

class BordereauIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $indicatorCalculationHelper // Inject the helper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Bordereau::class;
    }

    // Removed calculateBordereauDelaiSoumission as it's no longer relevant in the new workflow.
    // The concept of "submission delay" by the broker doesn't apply if the insurer provides the bordereau.

    public function calculate(object $entity): array
    {
        /** @var Bordereau $entity */
        
        // Calcul des montants HT et Taxe à partir des opérations
        $totalMontantHT = 0.0;
        $totalMontantTaxe = 0.0;
        foreach ($entity->getOperations() as $operation) {
            // Assurez-vous que l'opération a ses propres montants HT et Taxe
            // ou que ces derniers sont calculés et hydratés sur l'objet Operation.
            // Pour l'instant, on suppose qu'ils sont directement accessibles.
            $totalMontantHT += $operation->getMontantHT() ?? 0.0;
            $totalMontantTaxe += $operation->getMontantTaxe() ?? 0.0;
        }
        $entity->montantCommissionHT = $totalMontantHT;
        $entity->montantTaxe = $totalMontantTaxe;
        $montantCommissionTTC = $totalMontantHT + $totalMontantTaxe;
        $montantEncaisse = $this->indicatorCalculationHelper->getBordereauMontantEncaisse($entity);
        $solde = $montantCommissionTTC - $montantEncaisse;

        return [
            'typeString' => $this->getBordereauTypeString($entity),
            'statutString' => $this->getBordereauStatutString($entity),
            'ageBordereau' => $this->calculateBordereauAge($entity),
            'nombreDocuments' => $entity->getDocuments()->count(),
            'montantCommissionHT' => $totalMontantHT, // Ajout des montants calculés
            'montantTaxe' => $totalMontantTaxe,       // Ajout des montants calculés
            'assureurNom' => $entity->getAssureur()?->getNom() ?? 'N/A',
            'montantCommissionTTC' => $montantCommissionTTC,
            'montantEncaisse' => $montantEncaisse,
            'solde' => $solde,
        ];
    }

    private function getBordereauTypeString(Bordereau $bordereau): string
    {
        return match ($bordereau->getType()) {
            Bordereau::TYPE_BOREDERAU_PRODUCTION => 'Bordereau de production',
            default => 'Type inconnu',
        };
    }

    private function getBordereauStatutString(Bordereau $bordereau): string
    {
        return match ($bordereau->getStatut()) {
            Bordereau::STATUT_A_VERIFIER => 'À vérifier',
            Bordereau::STATUT_CONTESTE => 'Contesté',
            Bordereau::STATUT_VALIDE => 'Validé',
            Bordereau::STATUT_PAYE => 'Payé',
            Bordereau::STATUT_PARTIELLEMENT_PAYE => 'Partiellement payé',
            Bordereau::STATUT_ANNULE => 'Annulé',
            default => 'Statut inconnu',
        };
    }

    private function calculateBordereauAge(Bordereau $bordereau): string
    {
        if (!$bordereau->getReceivedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($bordereau->getReceivedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }
}