<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Risque;

class RisqueIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Risque::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Risque $entity */
        $stats = $this->calculationHelper->getIndicateursGlobaux($entity->getEntreprise(), false, ['risqueCible' => $entity]);

        return [
            'brancheString' => $this->getRisqueBrancheString($entity),
            'nombrePistes' => $entity->getPistes()->count(),
            'nombreSinistres' => $entity->getNotificationSinistres()->count(),
            'nombrePolices' => $this->countRisquePolices($entity),

            // Mapping des stats globales vers les attributs de l'entité
            'primeTotale' => round($stats['prime_totale'], 2),
            'primePayee' => round($stats['prime_totale_payee'], 2),
            'primeSoldeDue' => round($stats['prime_totale_solde'], 2),
            'tauxCommission' => round($stats['taux_de_commission'], 2),
            'montantHT' => round($stats['commission_nette'], 2),
            'montantTTC' => round($stats['commission_totale'], 2),
            'detailCalcul' => "Agrégation portefeuille",
            
            'taxeCourtierMontant' => round($stats['taxe_courtier'], 2),
            'taxeAssureurMontant' => round($stats['taxe_assureur'], 2),
            
            'montant_du' => round($stats['commission_totale'], 2),
            'montant_paye' => round($stats['commission_totale_encaissee'], 2),
            'solde_restant_du' => round($stats['commission_totale_solde'], 2),
            
            'taxeCourtierPayee' => round($stats['taxe_courtier_payee'], 2),
            'taxeCourtierSolde' => round($stats['taxe_courtier_solde'], 2),
            'taxeAssureurPayee' => round($stats['taxe_assureur_payee'], 2),
            'taxeAssureurSolde' => round($stats['taxe_assureur_solde'], 2),
            
            'montantPur' => round($stats['commission_pure'], 2),
            'retroCommission' => round($stats['retro_commission_partenaire'], 2),
            'retroCommissionReversee' => round($stats['retro_commission_partenaire_payee'], 2),
            'retroCommissionSolde' => round($stats['retro_commission_partenaire_solde'], 2),
            'reserve' => round($stats['reserve'], 2),

            // Sinistralité
            'indemnisationDue' => round($stats['sinistre_payable'], 2),
            'indemnisationVersee' => round($stats['sinistre_paye'], 2),
            'indemnisationSolde' => round($stats['sinistre_solde'], 2),
            'tauxSP' => round($stats['taux_sinistralite'], 2),
            'tauxSPInterpretation' => $this->calculationHelper->getInterpretationTauxSP($stats['taux_sinistralite']),
        ];
    }

    private function getRisqueBrancheString(?Risque $risque): ?string
    {
        if ($risque === null) return null;

        return match ($risque->getBranche()) {
            Risque::BRANCHE_IARD_OU_NON_VIE => 'IARD / Non-Vie',
            Risque::BRANCHE_VIE => 'Vie',
            default => 'Inconnue',
        };
    }

    private function countRisquePolices(Risque $risque): int
    {
        $count = 0;
        foreach ($risque->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }
}