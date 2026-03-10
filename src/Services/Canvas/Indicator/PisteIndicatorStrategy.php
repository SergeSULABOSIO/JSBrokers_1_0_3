<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Piste;
use App\Services\ServiceDates;
use DateTimeImmutable;

class PisteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Piste::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Piste $entity */
        return [
            'risqueCode' => $entity->getRisque()?->getCode() ?? 'N/A',
            'typeAvenantString' => $this->getPisteTypeAvenantString($entity),
            'renewalConditionString' => $this->getPisteRenewalConditionString($entity),
            'statutTransformation' => $this->getPisteStatutTransformation($entity),
            'nombreCotations' => $entity->getCotations()->count(),
            'agePiste' => $this->calculatePisteAge($entity),
            'primeTotale' => round($this->aggregateSubscribedCotationIndicator($entity, 'primeTotale'), 2),
            'primePayee' => round($this->aggregateSubscribedCotationIndicator($entity, 'primePayee'), 2),
            'primeSoldeDue' => round($this->aggregateSubscribedCotationIndicator($entity, 'primeSoldeDue'), 2),
            'montantTTC' => round($this->aggregateSubscribedCotationIndicator($entity, 'montantTTC'), 2),
            'montant_paye' => round($this->aggregateSubscribedCotationIndicator($entity, 'montant_paye'), 2),
            'solde_restant_du' => round($this->aggregateSubscribedCotationIndicator($entity, 'solde_restant_du'), 2),
            'montantPur' => round($this->aggregateSubscribedCotationIndicator($entity, 'montantPur'), 2),
            'retroCommission' => round($this->aggregateSubscribedCotationIndicator($entity, 'retroCommission'), 2),
            'reserve' => round($this->aggregateSubscribedCotationIndicator($entity, 'reserve'), 2),
        ];
    }

    private function getPisteTypeAvenantString(Piste $piste): string
    {
        return match ($piste->getTypeAvenant()) {
            Piste::AVENANT_SOUSCRIPTION => 'Souscription',
            Piste::AVENANT_INCORPORATION => 'Incorporation',
            Piste::AVENANT_PROROGATION => 'Prorogation',
            Piste::AVENANT_ANNULATION => 'Annulation',
            Piste::AVENANT_RENOUVELLEMENT => 'Renouvellement',
            Piste::AVENANT_RESILIATION => 'Résiliation',
            default => 'Non défini',
        };
    }

    private function getPisteRenewalConditionString(Piste $piste): string
    {
        return match ($piste->getRenewalCondition()) {
            Piste::RENEWAL_CONDITION_RENEWABLE => 'À terme renouvelable',
            Piste::RENEWAL_CONDITION_ADJUSTABLE_AT_EXPIRY => 'Ajustable à l\'échéance',
            Piste::RENEWAL_CONDITION_ONCE_OFF_AND_EXTENDABLE => 'Temporaire (Non renouvelable)',
            default => 'Non défini',
        };
    }

    private function getPisteStatutTransformation(Piste $piste): string
    {
        foreach ($piste->getCotations() as $cotation) {
            if ($this->calculationHelper->isCotationBound($cotation)) {
                return 'Transformée (Souscrite)';
            }
        }
        return 'En cours';
    }

    private function calculatePisteAge(Piste $piste): string
    {
        if (!$piste->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($piste->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function aggregateSubscribedCotationIndicator(Piste $piste, string $indicatorName): float
    {
        $total = 0.0;
        $precomputedSums = ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];

        foreach ($piste->getCotations() as $cotation) {
            if ($this->calculationHelper->isCotationBound($cotation)) {
                $val = match ($indicatorName) {
                    'primeTotale' => $this->calculationHelper->getCotationMontantPrimePayableParClient($cotation),
                    'primePayee' => $this->calculationHelper->getCotationMontantPrimePayableParClientPayee($cotation),
                    'primeSoldeDue' => $this->calculationHelper->getCotationMontantPrimePayableParClient($cotation) - $this->calculationHelper->getCotationMontantPrimePayableParClientPayee($cotation),
                    'montantTTC' => $this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false),
                    'montant_paye' => $this->calculationHelper->getCotationMontantCommissionEncaissee($cotation),
                    'solde_restant_du' => $this->calculationHelper->getCotationMontantCommissionTtc($cotation, -1, false) - $this->calculationHelper->getCotationMontantCommissionEncaissee($cotation),
                    'montantPur' => $this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false),
                    'retroCommission' => $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, $precomputedSums),
                    'reserve' => $this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false) - $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, $precomputedSums),
                    default => 0.0,
                };
                $total += $val;
            }
        }
        return $total;
    }
}