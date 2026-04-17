<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Avenant;
use App\Repository\CotationRepository;
use App\Services\ServiceDates;
use DateTimeImmutable;

class AvenantIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper,
        private CotationRepository $cotationRepository
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Avenant::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Avenant $entity */
        $cotation = $entity->getCotation();
        if (!$cotation) {
            return [
                'dureeCouverture' => $this->calculateDureeCouvertureAvenant($entity),
                'joursRestants' => $this->calculateJoursRestantsAvenant($entity),
                'ageAvenant' => $this->calculateAgeAvenant($entity),
                'typeAffaire' => $this->getAvenantTypeAffaire($entity),
                'periodeCouverture' => $this->getAvenantPeriodeCouverture($entity),
                'clientDescription' => 'N/A',
                'risqueDescription' => 'N/A',
                'risqueCode' => 'N/A',
                'titrePrincipal' => ($entity->getReferencePolice() ?? 'N/A'),
            ];
        }

        return [
            // Indicateurs de base de l'avenant
            'dureeCouverture' => $this->calculateDureeCouvertureAvenant($entity),
            'joursRestants' => $this->calculateJoursRestantsAvenant($entity),
            'ageAvenant' => $this->calculateAgeAvenant($entity),
            'typeAffaire' => $this->getAvenantTypeAffaire($entity),
            'periodeCouverture' => $this->getAvenantPeriodeCouverture($entity),
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($cotation),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($cotation),
            'risqueCode' => $cotation->getPiste()?->getRisque()?->getCode() ?? 'N/A',
            'titrePrincipal' => ($entity->getReferencePolice() ?? 'N/A') . ' • ' . ($cotation->getPiste()?->getClient()?->getNom() ?? 'N/A'),

            // Indicateurs hérités de la Cotation parente
            'contextePiste' => $this->calculationHelper->getCotationContextePiste($cotation),
            'indemnisationDue' => round($this->calculationHelper->getCotationIndemnisationDue($cotation), 2),
            'indemnisationVersee' => round($this->calculationHelper->getCotationIndemnisationVersee($cotation), 2),
            'indemnisationSolde' => round($this->calculationHelper->getCotationIndemnisationSolde($cotation), 2),
            'tauxSP' => $this->calculationHelper->getCotationTauxSP($cotation),
            'tauxSPInterpretation' => $this->calculationHelper->getCotationTauxSPInterpretation($cotation),
            'dateDernierReglement' => $this->calculationHelper->getCotationDateDernierReglement($cotation),
            'vitesseReglement' => $this->calculationHelper->getCotationVitesseReglement($cotation),
            'nombreTranches' => $this->calculationHelper->calculateNombreTranches($cotation),
            'montantMoyenTranche' => $this->calculationHelper->calculateMontantMoyenTranche($cotation),
            'primeTotale' => round($this->calculationHelper->getCotationMontantPrimePayableParClient($cotation), 2),
            'primePayee' => round($this->calculationHelper->getCotationMontantPrimePayableParClientPayee($cotation), 2),
            'primeSoldeDue' => round($this->calculationHelper->getCotationMontantPrimePayableParClient($cotation) - $this->calculationHelper->getCotationMontantPrimePayableParClientPayee($cotation), 2),
            'tauxCommission' => $this->calculationHelper->getCotationTauxSP($cotation), // Ancienne implémentation pour éviter régression
            'montantHT' => round($this->calculationHelper->getCotationMontantCommissionHt($cotation, -1, false), 2),
            'montantTTC' => round($this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false), 2),
            'detailCalcul' => "Basé sur la cotation associée",
            'taxeCourtierMontant' => round($this->calculationHelper->getCotationMontantTaxeCourtier($cotation, false), 2),
            'taxeAssureurMontant' => round($this->calculationHelper->getCotationMontantTaxeAssureur($cotation, false), 2),
            'montant_du' => round($this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false), 2),
            'montant_paye' => round($this->calculationHelper->getCotationMontantCommissionEncaissee($cotation), 2),
            'solde_restant_du' => round($this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false) - $this->calculationHelper->getCotationMontantCommissionEncaissee($cotation), 2),
            'taxeCourtierPayee' => round($this->calculationHelper->getCotationMontantTaxeCourtierPayee($cotation), 2),
            'taxeCourtierSolde' => round($this->calculationHelper->getCotationMontantTaxeCourtier($cotation, false) - $this->calculationHelper->getCotationMontantTaxeCourtierPayee($cotation), 2),
            'taxeAssureurPayee' => round($this->calculationHelper->getCotationMontantTaxeAssureurPayee($cotation), 2),
            'taxeAssureurSolde' => round($this->calculationHelper->getCotationMontantTaxeAssureur($cotation, false) - $this->calculationHelper->getCotationMontantTaxeAssureurPayee($cotation), 2),
            'montantPur' => round($this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false), 2),
            // CORRECTION : On s'assure qu'un partenaire existe avant de calculer la rétro-commission.
            'retroCommission' => $cotation->getPiste()?->getPartenaire() ? round($this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, []), 2) : 0.0,
            'retroCommissionReversee' => $cotation->getPiste()?->getPartenaire() ? round($this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, null), 2) : 0.0,
            'retroCommissionSolde' => $cotation->getPiste()?->getPartenaire() ? round(
                $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, []) -
                $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtierPayee($cotation, null),
                2
            ) : 0.0,
            'reserve' => round($this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false) - ($cotation->getPiste()?->getPartenaire() ? $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, []) : 0.0), 2),
        ];
    }

    private function calculateDureeCouvertureAvenant(Avenant $avenant): string
    {
        if (!$avenant->getStartingAt() || !$avenant->getEndingAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getStartingAt(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateJoursRestantsAvenant(Avenant $avenant): string
    {
        if (!$avenant->getEndingAt()) {
            return 'N/A';
        }
        if ($avenant->getEndingAt() < new DateTimeImmutable()) {
            return 'Expiré';
        }
        $jours = $this->serviceDates->daysEntre(new DateTimeImmutable(), $avenant->getEndingAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateAgeAvenant(Avenant $avenant): string
    {
        if (!$avenant->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($avenant->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getAvenantPeriodeCouverture(Avenant $avenant): string
    {
        if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
            return sprintf("Du %s au %s", $avenant->getStartingAt()->format('d/m/Y'), $avenant->getEndingAt()->format('d/m/Y'));
        }
        return 'Période incomplète';
    }

    private function getAvenantTypeAffaire(Avenant $avenant): string
    {
        $cotation = $avenant->getCotation();
        if (!$cotation) return "Indéterminé (Cotation manquante)";

        $piste = $cotation->getPiste();
        if (!$piste) return "Indéterminé (Piste manquante)";

        $client = $piste->getClient();
        $risque = $piste->getRisque();
        $startingAt = $avenant->getStartingAt();

        $missing = [];
        if (!$client) $missing[] = 'Client';
        if (!$risque) $missing[] = 'Risque';
        if (!$startingAt) $missing[] = 'Date d\'effet';

        if (!empty($missing)) return "Indéterminé (" . implode('/', $missing) . " manquant)";

        $count = $this->cotationRepository->createQueryBuilder('c')
            ->select('count(a.id)')
            ->join('c.piste', 'p')
            ->join('c.avenants', 'a')
            ->where('p.client = :client')->setParameter('client', $client)
            ->andWhere('p.risque = :risque')->setParameter('risque', $risque)
            ->andWhere('a.id != :currentAvenantId')->setParameter('currentAvenantId', $avenant->getId())
            ->andWhere('a.startingAt < :currentStartingAt')->setParameter('currentStartingAt', $startingAt)
            ->getQuery()->getSingleScalarResult();

        return ($count > 0) ? "Affaire existante" : "Nouvelle affaire";
    }

    public function getAvenantStatutRenouvellementString(?Avenant $avenant): ?string
    {
        if ($avenant === null || $avenant->getRenewalStatus() === null) {
            return "Non défini";
        }
        return match ($avenant->getRenewalStatus()) {
            Avenant::RENEWAL_STATUS_LOST => "Perdu",
            Avenant::RENEWAL_STATUS_ONCE_OFF => "Unique (sans renouvellement)",
            Avenant::RENEWAL_STATUS_RENEWED => "Renouvelé",
            Avenant::RENEWAL_STATUS_EXTENDED => "Prorogé",
            Avenant::RENEWAL_STATUS_RUNNING => "En cours",
            Avenant::RENEWAL_STATUS_RENEWING => "En renouvellement",
            Avenant::RENEWAL_STATUS_CANCELLED => "Annulé",
            default => "Inconnu",
        };
    }
}