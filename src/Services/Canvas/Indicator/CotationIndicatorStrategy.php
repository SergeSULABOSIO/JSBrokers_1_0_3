<?php

namespace App\Services\Canvas\Indicator;

use App\Service\Partage\RattachementDuPartage;
use App\Entity\Cotation;
use App\Services\Search\CotationSouscriptionScope;
use App\Services\ServiceDates;
use DateTimeImmutable;

class CotationIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper,
        // LE VOYANT « effort commercial » : une seule autorité le calcule, pour les
        // quatre écrans de l'arbre d'une affaire.
        private RattachementDuPartage $rattachement,
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Cotation $entity */
        return [
            // LE VOYANT DE LA LIGNE, et le drapeau des actions de partage : une seule
            // valeur pour une seule information — deux champs finiraient par se
            // contredire. `null` pour une affaire du cabinet seul, qui est le cas normal.
            // LE VOYANT DU PARTAGE et les deux drapeaux de ses actions, en UNE traversée.
            // Un seul voyant nomme les deux familles : une affaire peut être APPORTÉE par
            // un partenaire et TRAVAILLÉE par un agent, et deux colonnes auraient été vides
            // l'une comme l'autre sur la plupart des lignes.
            ...$this->rattachement->indicateurs($entity),
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($entity),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($entity),
            'contextePiste' => $this->calculationHelper->getCotationContextePiste($entity),
            'statutSouscription' => CotationSouscriptionScope::statutLibelle($this->calculationHelper->isCotationBound($entity)),
            'referencePolice' => $this->calculationHelper->getCotationReferencePolice($entity),
            'periodeCouverture' => $this->calculationHelper->getCotationPeriodeCouverture($entity),
            'indemnisationDue' => round($this->calculationHelper->getCotationIndemnisationDue($entity), 2),
            'indemnisationVersee' => round($this->calculationHelper->getCotationIndemnisationVersee($entity), 2),
            'indemnisationSolde' => round($this->calculationHelper->getCotationIndemnisationSolde($entity), 2),
            'tauxSP' => $this->calculationHelper->getCotationTauxSP($entity),
            'tauxSPInterpretation' => $this->calculationHelper->getCotationTauxSPInterpretation($entity),
            'dateDernierReglement' => $this->calculationHelper->getCotationDateDernierReglement($entity),
            'vitesseReglement' => $this->calculationHelper->getCotationVitesseReglement($entity),
            'delaiDepuisCreation' => $this->calculateDelaiDepuisCreation($entity),
            'nombreTranches' => $this->calculationHelper->calculateNombreTranches($entity),
            'montantMoyenTranche' => $this->calculationHelper->calculateMontantMoyenTranche($entity),
            'primeTotale' => round($this->calculationHelper->getCotationMontantPrimePayableParClient($entity), 2),
            'primePayee' => round($this->calculationHelper->getCotationMontantPrimePayableParClientPayee($entity), 2),
            'primeSoldeDue' => round($this->calculationHelper->getCotationMontantPrimePayableParClient($entity) - $this->calculationHelper->getCotationMontantPrimePayableParClientPayee($entity), 2),
            'tauxCommission' => $this->calculationHelper->getCotationTauxSP($entity),
            'montantHT' => round($this->calculationHelper->getCotationMontantCommissionHt($entity, -1, false), 2),
            'montantTTC' => round($this->calculationHelper->getCotationMontantCommissionTtc($entity, -1, false), 2),
            'detailCalcul' => "Somme des revenus",
            'taxeCourtierMontant' => round($this->calculationHelper->getCotationMontantTaxeCourtier($entity, false), 2),
            'taxeAssureurMontant' => round($this->calculationHelper->getCotationMontantTaxeAssureur($entity, false), 2),
            'tauxTaxeAssureurPercent' => $this->calculationHelper->getCotationTauxTaxeAssureurPercent($entity),
            'tauxTaxeCourtierPercent' => $this->calculationHelper->getCotationTauxTaxeCourtierPercent($entity),
            'montant_du' => round($this->calculationHelper->getCotationMontantCommissionTtc($entity, -1, false), 2),
            'montant_paye' => round($this->calculationHelper->getCotationMontantCommissionEncaissee($entity), 2),
            'solde_restant_du' => round($this->calculationHelper->getCotationMontantCommissionTtc($entity, -1, false) - $this->calculationHelper->getCotationMontantCommissionEncaissee($entity), 2),
            'taxeCourtierPayee' => round($this->calculationHelper->getCotationMontantTaxeCourtierPayee($entity), 2),
            'taxeCourtierSolde' => round($this->calculationHelper->getCotationMontantTaxeCourtier($entity, false) - $this->calculationHelper->getCotationMontantTaxeCourtierPayee($entity), 2),
            'taxeAssureurPayee' => round($this->calculationHelper->getCotationMontantTaxeAssureurPayee($entity), 2),
            'taxeAssureurSolde' => round($this->calculationHelper->getCotationMontantTaxeAssureur($entity, false) - $this->calculationHelper->getCotationMontantTaxeAssureurPayee($entity), 2),
            'montantPur' => round($this->calculationHelper->getCotationMontantCommissionPure($entity, -1, false), 2),
            'retroCommission' => round($this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1), 2),
            'retroCommissionReversee' => round($this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
            'retroCommissionSolde' => round($this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1) - $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
            'reserve' => round($this->calculationHelper->getCotationMontantCommissionPure($entity, -1, false) - $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1), 2),
        ];
    }

    private function calculateDelaiDepuisCreation(Cotation $cotation): string
    {
        if (!$cotation->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($cotation->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }
}