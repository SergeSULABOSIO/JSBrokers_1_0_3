<?php

namespace App\Services\Canvas\Indicator;

use App\Service\Partage\RattachementDuPartage;
use App\Entity\Piste;
use App\Services\Search\PisteTransformationScope;
use App\Services\ServiceDates;
use DateTimeImmutable;

class PisteIndicatorStrategy implements IndicatorCalculationStrategyInterface
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
        return $entityClassName === Piste::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Piste $entity */
        return [
            // LE VOYANT DE LA LIGNE, et le drapeau des actions de partage : une seule
            // valeur pour une seule information — deux champs finiraient par se
            // contredire. `null` pour une affaire du cabinet seul, qui est le cas normal.
            // LE VOYANT DU PARTAGE et les deux drapeaux de ses actions, en UNE traversée.
            // Un seul voyant nomme les deux familles : une affaire peut être APPORTÉE par
            // un partenaire et TRAVAILLÉE par un agent, et deux colonnes auraient été vides
            // l'une comme l'autre sur la plupart des lignes.
            ...$this->rattachement->indicateurs($entity),
            'risqueCode' => $entity->getRisque()?->getCode() ?? 'N/A',
            'typeAvenantString' => $this->getPisteTypeAvenantString($entity),
            'renewalConditionString' => $this->getPisteRenewalConditionString($entity),
            'statutTransformation' => $this->getPisteStatutTransformation($entity),
            ...$this->etapeDuPipe($entity),
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

    /** Source unique : la table vit sur l'entité, avec les codes qu'elle traduit. */
    private function getPisteTypeAvenantString(Piste $piste): string
    {
        return Piste::libelleTypeAvenant($piste->getTypeAvenant());
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

    /**
     * L'ÉTAPE DU PIPE : la même règle que le chip et que la colonne du Kanban.
     *
     * Trois faits, lus sur les cotations — souscrite, prime chiffrée, au moins une — et
     * rangés par PisteTransformationScope::etapeDe(). Le seuil de la prime est celui des
     * prédicats SQL (SEUIL_PRIME_CHIFFREE) : une piste ne peut pas être « Offre chiffrée »
     * dans sa ligne et « En cotation » dans le filtre.
     *
     * @return array{etapePipe: string, etapePipeLibelle: string, etapePipeNiveau: string}
     */
    private function etapeDuPipe(Piste $piste): array
    {
        $transformee = false;
        $chiffree = false;
        foreach ($piste->getCotations() as $cotation) {
            $transformee = $transformee || $this->calculationHelper->isCotationBound($cotation);
            $chiffree = $chiffree || PisteTransformationScope::primeChiffree(
                $this->calculationHelper->getCotationMontantPrimePayableParClient($cotation)
            );
        }
        $etape = PisteTransformationScope::etapeDe($transformee, $chiffree, !$piste->getCotations()->isEmpty());

        return [
            'etapePipe' => $etape,
            'etapePipeLibelle' => PisteTransformationScope::ETAPES[$etape]['singulier'],
            // Le suffixe de classe du marqueur coloré (`.jsb-state-badge--etape-…`).
            'etapePipeNiveau' => 'etape-' . $etape,
        ];
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
                    'retroCommission' => $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1),
                    'reserve' => $this->calculationHelper->getCotationMontantCommissionPure($cotation, -1, false) - $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1),
                    default => 0.0,
                };
                $total += $val;
            }
        }
        return $total;
    }
}