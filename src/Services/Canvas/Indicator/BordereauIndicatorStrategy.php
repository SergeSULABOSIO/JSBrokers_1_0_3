<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Bordereau;
use App\Repository\AvenantRepository;
use App\Services\ServiceDates;
use DateTimeImmutable;
use App\Services\Canvas\Indicator\IndicatorCalculationHelper;

class BordereauIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $indicatorCalculationHelper,
        private AvenantRepository $avenantRepository,
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

        $montants = $this->getMontantsFromAvenants($entity);
        $totalMontantHT   = $montants['montantHT'];
        $totalMontantTaxe = $montants['taxeAssureur'];
        $entity->montantCommissionHT = $totalMontantHT;
        $entity->montantTaxe = $totalMontantTaxe;
        $montantCommissionTTC = $totalMontantHT + $totalMontantTaxe;

        $comHtPayableNow  = $entity->getMontantComHtPayableNow() ?? 0.0;
        $taxePayableNow   = $entity->getMontantTaxePayableNow() ?? 0.0;
        $comTtcPayableNow = round($comHtPayableNow + $taxePayableNow, 2);

        $montantEncaisse = $this->indicatorCalculationHelper->getBordereauMontantEncaisse($entity);
        $solde = $comTtcPayableNow - $montantEncaisse;

        // NOUVEAU : Calcul et hydratation du statut transitoire
        $entity->statut = $this->determineBordereauStatus($entity);

        $isValidated = $entity->getCurrentAnalysisStep() === Bordereau::STATUT_ANALYSE_TERMINEE;

        return [
            'typeString' => $this->getBordereauTypeString($entity),
            'statutString' => $this->getBordereauStatutString($entity), // Utilise le statut calculé
            'ageBordereau' => $this->calculateBordereauAge($entity),
            'nombreDocuments' => $entity->getDocuments()->count(),
            'montantCommissionHT' => $totalMontantHT, // Ajout des montants calculés
            'montantTaxe' => $totalMontantTaxe,       // Ajout des montants calculés
            'assureurNom' => $entity->getAssureur()?->getNom() ?? 'N/A',
            'montantCommissionTTC' => $montantCommissionTTC,
            'montantEncaisse' => $montantEncaisse,
            'solde' => $solde,
            'montantPayableDisplay' => $isValidated ? ($entity->getMontantPayableNow() ?? 0.0) : 0.0,
            'comHtPayableNow'  => round($comHtPayableNow, 2),
            'taxePayableNow'   => round($taxePayableNow, 2),
            'comTtcPayableNow' => $comTtcPayableNow,
        ];
    }

    private function getMontantsFromAvenants(Bordereau $bordereau): array
    {
        $avenantIds = array_values(array_filter(array_unique(
            array_column($bordereau->getAnalysisResults() ?? [], 'avenant_id')
        )));

        if (empty($avenantIds)) {
            return ['montantHT' => 0.0, 'taxeAssureur' => 0.0];
        }

        $avenants = $this->avenantRepository->findBy(['id' => $avenantIds]);

        $totalHT   = 0.0;
        $totalTaxe = 0.0;
        foreach ($avenants as $avenant) {
            $cotation = $avenant->getCotation();
            if ($cotation) {
                $totalHT   += $this->indicatorCalculationHelper->getCotationMontantCommissionHt($cotation, -1, false);
                $totalTaxe += $this->indicatorCalculationHelper->getCotationMontantTaxeAssureur($cotation, false);
            }
        }

        return ['montantHT' => $totalHT, 'taxeAssureur' => $totalTaxe];
    }

    private function getBordereauTypeString(Bordereau $bordereau): string
    {
        return match ($bordereau->getType()) {
            Bordereau::TYPE_BOREDERAU_PRODUCTION => 'Bordereau de production',
            default => 'Type inconnu',
        };
    }

    /**
     * NOUVEAU : Détermine le statut numérique du bordereau basé sur son état d'analyse.
     */
    private function determineBordereauStatus(Bordereau $bordereau): int
    {
        $currentStep = $bordereau->getCurrentAnalysisStep();
        $selectedSheetName = $bordereau->getSelectedSheetName();
        $mappedColumns = $bordereau->getMappedColumns();

        if ($currentStep === null || $currentStep === 0) {
            return Bordereau::STATUT_EN_ATTENTE_ANALYSE;
        }

        if ($currentStep === 1) {
            if ($selectedSheetName) {
                return Bordereau::STATUT_SELECTION_FEUILLE_EN_COURS; // Feuille sélectionnée, mais pas encore mappée
            }
            return Bordereau::STATUT_EN_ATTENTE_ANALYSE; // Si l'étape 1 est active mais aucune feuille n'est sélectionnée
        }

        if ($currentStep === 2) {
            if ($mappedColumns === null || empty($mappedColumns)) {
                return Bordereau::STATUT_MAPPAGE_INCOMPLET;
            }
            // Pour une vérification plus poussée, il faudrait comparer avec les champs obligatoires.
            // Pour l'instant, si des colonnes sont mappées, on considère le mappage en cours.
            return Bordereau::STATUT_MAPPAGE_EN_COURS;
        }

        if ($currentStep === 3) {
            return Bordereau::STATUT_ANALYSE_TERMINEE;
        }

        if ($currentStep === Bordereau::STATUT_ANALYSE_TERMINEE) {
            return Bordereau::STATUT_VALIDE;
        }

        if ($currentStep === Bordereau::STEP_NOTE_EMISE) {
            return Bordereau::STATUT_FACTURE;
        }

        return Bordereau::STATUT_INCONNU;
    }

    private function getBordereauStatutString(Bordereau $bordereau): string
    {
        return match ($bordereau->statut) { // Utilise la propriété 'statut' calculée
            Bordereau::STATUT_VALIDE => 'Validé',
            Bordereau::STATUT_FACTURE => 'Facturé',
            Bordereau::STATUT_EN_ATTENTE_ANALYSE => 'Analyse non démarrée',
            Bordereau::STATUT_SELECTION_FEUILLE_EN_COURS => 'Feuille sélectionnée',
            Bordereau::STATUT_MAPPAGE_INCOMPLET => 'Mappage incomplet',
            Bordereau::STATUT_MAPPAGE_EN_COURS => 'Mappage en cours',
            Bordereau::STATUT_ANALYSE_TERMINEE => 'Analyse terminée',
            default => 'Statut d\'analyse inconnu',
        };
    }

    private function calculateBordereauAge(Bordereau $bordereau): string
    {
        if (!$bordereau->getReceivedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($bordereau->getReceivedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }
}