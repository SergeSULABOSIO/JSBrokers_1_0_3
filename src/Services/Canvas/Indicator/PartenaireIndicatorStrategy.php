<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Partenaire;
use App\Entity\ConditionPartage;

class PartenaireIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Partenaire::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Partenaire $entity */
        $stats = $this->calculationHelper->getIndicateursGlobaux($entity->getEntreprise(), false, ['partenaireCible' => $entity]);

        return [
            'nombrePistesApportees' => $entity->getPistes()->count(),
            'nombreClientsAssocies' => $entity->getClients()->count(),
            'nombrePolicesGenerees' => $this->countPartenairePolices($entity),
            'nombreConditionsPartage' => $entity->getConditionPartages()->count(),
            'partPourcentage' => round(($entity->getPart() ?? 0) * 100, 2),
            'conditionsPartageResume' => $this->getPartenaireConditionsPartageResume($entity),

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

    private function countPartenairePolices(Partenaire $partenaire): int
    {
        $count = 0;
        foreach ($partenaire->getPistes() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$cotation->getAvenants()->isEmpty()) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function getPartenaireConditionsPartageResume(Partenaire $partenaire): string
    {
        $conditions = $partenaire->getConditionPartages();
        if ($conditions->isEmpty()) {
            return "Aucune condition spécifique définie. Le taux par défaut de " . ($partenaire->getPart() * 100) . "% s'applique à l'ensemble du portefeuille.";
        }

        $resume = "Ce partenaire dispose de " . $conditions->count() . " condition(s) spécifique(s) qui modulent le calcul de sa rétro-commission.";
        
        foreach ($conditions as $condition) {
            $resume .= "\n\n• Condition : " . $condition->getNom();
            $resume .= "\n  Règle : " . $this->getConditionPartageDescriptionRegle($condition);
            
            if (!$condition->getProduits()->isEmpty()) {
                $risquesList = [];
                foreach ($condition->getProduits() as $risque) {
                    $risquesList[] = $risque->getNomComplet();
                }
                $resume .= "\n  Risques ciblés : " . implode(', ', $risquesList) . ".";
            }
        }
        return $resume;
    }

    private function getConditionPartageDescriptionRegle(ConditionPartage $condition): string
    {
        $taux = ($condition->getTaux() ?? 0) * 100;
        
        $formule = match ($condition->getFormule()) {
            ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => "Assiette >= Seuil",
            ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL => "Assiette < Seuil",
            ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL => "Sans seuil",
            default => "Inconnue",
        };

        $critere = match ($condition->getCritereRisque()) {
            ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES => "Exclure risques ciblés",
            ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES => "Inclure risques ciblés",
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES => "Aucun risque ciblé",
            default => "Inconnu",
        };

        $nbRisques = $condition->getProduits()->count();
        $description = "Appliquer " . $taux . "%";

        if ($formule !== "Sans seuil") {
            $seuil = $condition->getSeuil() ?? 0;
            $unite = match ($condition->getUniteMesure()) {
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => "Com. pure du risque",
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => "Com. pure du client",
                ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => "Com. pure du partenaire",
                default => "Non définie",
            };
            $description .= " si {$unite} {$formule} {$seuil}";
        }

        if ($critere !== "Aucun risque ciblé") {
            $description .= ", en se basant sur le critère '{$critere}' avec {$nbRisques} risque(s).";
        }

        return $description;
    }
}