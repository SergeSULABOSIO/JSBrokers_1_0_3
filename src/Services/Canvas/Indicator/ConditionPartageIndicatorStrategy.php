<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ConditionPartage;

class ConditionPartageIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === ConditionPartage::class;
    }

    public function calculate(object $entity): array
    {
        /** @var ConditionPartage $entity */
        $impact = $this->calculateConditionPartageImpact($entity);

        return [
            'descriptionRegle' => $this->getConditionPartageDescriptionRegle($entity),
            'nombreRisquesCibles' => $entity->getProduits()->count(),
            'porteeCondition' => $this->getConditionPartagePortee($entity),
            'totalAssiette' => round($impact['assiette'], 2),
            'totalRetroCommission' => round($impact['retroCommission'], 2),
            'nombreDossiersConcernes' => $impact['dossiers'],
            'formule_string' => $this->ConditionPartage_getFormuleString($entity),
            'critere_risque_string' => $this->ConditionPartage_getCritereRisqueString($entity),
            'unite_mesure_string' => $this->ConditionPartage_getUniteMesureString($entity),
            'contexteParent' => $this->ConditionPartage_getContexteParentString($entity),
        ];
    }

    private function getConditionPartageDescriptionRegle(ConditionPartage $condition): string
    {
        $taux = ($condition->getTaux() ?? 0) * 100;
        $formule = $this->ConditionPartage_getFormuleString($condition);
        $critere = $this->ConditionPartage_getCritereRisqueString($condition);
        $nbRisques = $condition->getProduits()->count();

        $description = "Appliquer " . $taux . "%";

        if ($formule !== "Sans seuil") {
            $seuil = $condition->getSeuil() ?? 0;
            $unite = $this->ConditionPartage_getUniteMesureString($condition);
            $description .= " si {$unite} {$formule} {$seuil}";
        }

        if ($critere !== "Aucun risque ciblé") {
            $description .= ", en se basant sur le critère '{$critere}' avec {$nbRisques} risque(s).";
        }

        return $description;
    }

    private function getConditionPartagePortee(ConditionPartage $condition): string
    {
        if ($condition->getPiste()) return 'Exceptionnelle (Piste)';
        if ($condition->getPartenaire()) return 'Générale (Partenaire)';
        return 'Non définie';
    }

    private function ConditionPartage_getFormuleString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getFormule()) {
            ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL => "Assiette >= Seuil",
            ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL => "Assiette < Seuil",
            ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL => "Sans seuil",
            default => "Inconnue",
        };
    }

    private function ConditionPartage_getCritereRisqueString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getCritereRisque()) {
            ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES => "Exclure risques ciblés",
            ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES => "Inclure risques ciblés",
            ConditionPartage::CRITERE_PAS_RISQUES_CIBLES => "Aucun risque ciblé",
            default => "Inconnu",
        };
    }

    private function ConditionPartage_getUniteMesureString(?ConditionPartage $condition): ?string
    {
        if ($condition === null) return null;
        return match ($condition->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => "Com. pure du risque",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => "Com. pure du client",
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => "Com. pure du partenaire",
            default => "Non définie",
        };
    }

    private function ConditionPartage_getContexteParentString(?ConditionPartage $condition): string
    {
        if ($condition === null) return 'N/A';
        if ($condition->getPiste()) return "Piste: " . $condition->getPiste()->getNom();
        if ($condition->getPartenaire()) return "Partenaire: " . $condition->getPartenaire()->getNom();
        return "Aucun parent défini";
    }

    private function calculateConditionPartageImpact(ConditionPartage $condition): array
    {
        $assiette = 0.0;
        $retroCommission = 0.0;
        $dossiers = 0;
        
        $cotations = [];
        if ($condition->getPiste()) {
            foreach ($condition->getPiste()->getCotations() as $cotation) {
                if ($this->calculationHelper->isCotationBound($cotation)) {
                    $cotations[] = $cotation;
                }
            }
        } elseif ($condition->getPartenaire()) {
            foreach ($condition->getPartenaire()->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->calculationHelper->isCotationBound($cotation)) {
                        $cotations[] = $cotation;
                    }
                }
            }
            foreach ($condition->getPartenaire()->getClients() as $client) {
                 foreach ($client->getPistes() as $piste) {
                    foreach ($piste->getCotations() as $cotation) {
                        if ($this->calculationHelper->isCotationBound($cotation)) {
                            $cotations[] = $cotation;
                        }
                    }
                }
            }
        }
        
        $cotations = array_unique($cotations, SORT_REGULAR);
        $precomputedSums = ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];

        foreach ($cotations as $cotation) {
            $applied = false;
            foreach ($cotation->getRevenus() as $revenu) {
                $montant = $this->calculationHelper->applyRevenuConditionsSpeciales($condition, $revenu, -1, $precomputedSums);
                if ($montant > 0) {
                    $retroCommission += $montant;
                    $assiette += $this->calculationHelper->getRevenuMontantPure($revenu, -1, true);
                    $applied = true;
                }
            }
            if ($applied) $dossiers++;
        }
        
        return [
            'assiette' => $assiette,
            'retroCommission' => $retroCommission,
            'dossiers' => $dossiers
        ];
    }
}