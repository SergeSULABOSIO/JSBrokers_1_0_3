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
            'beneficiaireNom' => $this->getConditionPartageBeneficiaire($entity),
            'estPourAgent' => $entity->estPourAgent(),
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
        $taux = ($condition->getTaux() ?? 0);
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
        // L'agent d'abord : une condition interne est rattachée à des pistes, mais ce
        // n'est pas une « exceptionnelle » — elle est PARTAGÉE entre ses affaires.
        if ($condition->estPourAgent()) {
            $nb = $condition->getPistesAffectees()->count();

            return sprintf('Agent interne (%d affaire%s)', $nb, $nb > 1 ? 's' : '');
        }
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
        if ($condition->estPourAgent()) return "Agent: " . $condition->getAgent()->getNom();
        if ($condition->getPiste()) return "Piste: " . $condition->getPiste()->getNom();
        if ($condition->getPartenaire()) return "Partenaire: " . $condition->getPartenaire()->getNom();
        return "Aucun parent défini";
    }

    /** Qui touche, en clair — la colonne que la liste affiche sous le nom de la condition. */
    private function getConditionPartageBeneficiaire(ConditionPartage $condition): string
    {
        $beneficiaire = $condition->getBeneficiaire();
        if ($beneficiaire === null) {
            return 'Aucun bénéficiaire';
        }

        return $condition->estPourAgent()
            ? sprintf('%s (agent interne)', $beneficiaire->getNom())
            : sprintf('%s (partenaire)', $beneficiaire->getNom());
    }

    private function calculateConditionPartageImpact(ConditionPartage $condition): array
    {
        // Condition d'AGENT : un circuit à part, parce que son assiette l'est aussi (ce qui
        // reste au cabinet après les partenaires, et non la commission partageable). La
        // réutiliser ici serait un raccourci qui donnerait un chiffre faux.
        if ($condition->estPourAgent()) {
            return $this->impactConditionAgent($condition);
        }

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

        foreach ($cotations as $cotation) {
            $applied = false;
            foreach ($cotation->getRevenus() as $revenu) {
                $montant = $this->calculationHelper->applyRevenuConditionsSpeciales($condition, $revenu, -1);
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

    /**
     * Impact d'une condition au profit d'un AGENT INTERNE : on parcourt les affaires
     * auxquelles elle est RATTACHÉE (jamais celles que l'agent gère), et on ne compte que
     * celles où c'est bien ELLE qui l'emporte — un agent peut porter deux conditions
     * applicables, seule la première sert.
     *
     * @return array{assiette: float, retroCommission: float, dossiers: int}
     */
    private function impactConditionAgent(ConditionPartage $condition): array
    {
        $agent = $condition->getAgent();
        $assiette = 0.0;
        $retroCommission = 0.0;
        $dossiers = 0;

        foreach ($condition->getPistesAffectees() as $piste) {
            foreach ($piste->getCotations() as $cotation) {
                if (!$this->calculationHelper->isCotationBound($cotation)) {
                    continue;
                }
                $retenues = $this->calculationHelper->getCotationConditionsAgent($cotation, $agent);
                if (($retenues[$agent?->getId()] ?? null) !== $condition) {
                    continue;
                }
                $montant = $this->calculationHelper->getCotationMontantRetroAgent($cotation, $agent);
                if ($montant <= 0.0) {
                    continue;
                }
                $retroCommission += $montant;
                $assiette += $this->calculationHelper->getCotationAssietteRetroAgent($cotation);
                ++$dossiers;
            }
        }

        return [
            'assiette' => $assiette,
            'retroCommission' => $retroCommission,
            'dossiers' => $dossiers,
        ];
    }
}