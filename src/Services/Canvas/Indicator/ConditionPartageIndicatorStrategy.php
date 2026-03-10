<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\ConditionPartage;
use App\Entity\Cotation;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\RevenuPourCourtier;
use App\Entity\Chargement;
use App\Entity\TypeRevenu;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use Symfony\Contracts\Translation\TranslatorInterface;

class ConditionPartageIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private ServiceTaxes $serviceTaxes
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
            'nombreRisquesCibles' => $this->countConditionPartageRisquesCibles($entity),
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

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getConditionPartageDescriptionRegle(ConditionPartage $condition): string
    {
        $taux = ($condition->getTaux() ?? 0) * 100;
        $formule = $this->ConditionPartage_getFormuleString($condition);
        $critere = $this->ConditionPartage_getCritereRisqueString($condition);
        $nbRisques = $this->countConditionPartageRisquesCibles($condition);

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

    private function countConditionPartageRisquesCibles(ConditionPartage $condition): int
    {
        return $condition->getProduits()->count();
    }

    private function getConditionPartagePortee(ConditionPartage $condition): string
    {
        if ($condition->getPiste()) {
            return 'Exceptionnelle (Piste)';
        }
        if ($condition->getPartenaire()) {
            return 'Générale (Partenaire)';
        }
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
        
        if ($condition->getPiste()) {
            return "Piste: " . $condition->getPiste()->getNom();
        }
        if ($condition->getPartenaire()) {
            return "Partenaire: " . $condition->getPartenaire()->getNom();
        }
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
                if ($this->isCotationBound($cotation)) {
                    $cotations[] = $cotation;
                }
            }
        } elseif ($condition->getPartenaire()) {
            foreach ($condition->getPartenaire()->getPistes() as $piste) {
                foreach ($piste->getCotations() as $cotation) {
                    if ($this->isCotationBound($cotation)) {
                        $cotations[] = $cotation;
                    }
                }
            }
            foreach ($condition->getPartenaire()->getClients() as $client) {
                 foreach ($client->getPistes() as $piste) {
                    foreach ($piste->getCotations() as $cotation) {
                        if ($this->isCotationBound($cotation)) {
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
                $montant = $this->applyRevenuConditionsSpeciales($condition, $revenu, -1, $precomputedSums);
                if ($montant > 0) {
                    $retroCommission += $montant;
                    $assiette += $this->getRevenuMontantPure($revenu, -1, true);
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

    private function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && !$cotation->getAvenants()->isEmpty();
    }

    private function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo, array $precomputedSums): float
    {
        $montant = 0;
        $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
        $piste = $revenu->getCotation()->getPiste();
        if (!$piste) return 0.0;

        $uniteMesure = match ($conditionPartage->getUniteMesure()) {
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_RISQUE => $precomputedSums['by_risque'][$piste->getRisque()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_CLIENT => $precomputedSums['by_client'][$piste->getClient()->getId()] ?? 0.0,
            ConditionPartage::UNITE_SOMME_COMMISSION_PURE_PARTENAIRE => $precomputedSums['by_partenaire'][($this->getCotationPartenaire($revenu->getCotation()))?->getId()] ?? 0.0,
            default => 0.0,
        };

        $formule = $conditionPartage->getFormule();
        $seuil = $conditionPartage->getSeuil();
        $risque = $revenu->getCotation()->getPiste()->getRisque();

        switch ($formule) {
            case ConditionPartage::FORMULE_NE_SAPPLIQUE_PAS_SEUIL:
                return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
            case ConditionPartage::FORMULE_ASSIETTE_INFERIEURE_AU_SEUIL:
                if ($uniteMesure < $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }
            case ConditionPartage::FORMULE_ASSIETTE_AU_MOINS_EGALE_AU_SEUIL:
                if ($uniteMesure >= $seuil) {
                    return $this->calculerRetroCommission($risque, $conditionPartage, $assiette);
                } else {
                    return 0;
                }
        }
        return $montant;
    }

    private function getRevenuMontantPure(?RevenuPourCourtier $revenu, $addressedTo, bool $onlySharable): float
    {
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                return $this->calculateCommissionPure($revenu, $onlySharable);
            }
            return 0;
        } else {
            return $this->calculateCommissionPure($revenu, $onlySharable);
        }
    }

    private function calculateCommissionPure(RevenuPourCourtier $revenu, bool $onlySharable)
    {
        $taxeCourtier = 0;
        $taxeAssureur = false;
        $comNette = 0;
        $isIARD = $this->isIARD($revenu->getCotation());
        $commissionPure = 0;

        if ($onlySharable == true) {
            if ($revenu->getTypeRevenu()->isShared() == true) {
                $comNette = $this->getRevenuMontantHt($revenu);
                $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
                $commissionPure = $comNette - $taxeCourtier;
            }
        } else {
            $comNette = $this->getRevenuMontantHt($revenu);
            $taxeCourtier = $this->serviceTaxes->getMontantTaxe($comNette, $isIARD, $taxeAssureur);
            $commissionPure = $comNette - $taxeCourtier;
        }
        return $commissionPure;
    }

    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    private function getRevenuMontantHt(?RevenuPourCourtier $revenu): float
    {
        $montant = 0;
        if ($revenu) {
            $typeRevenu = $revenu->getTypeRevenu();
            if ($typeRevenu) {
                $cotation = $revenu->getCotation();
                $montantChargementPrime = $this->getCotationMontantChargementPrime($cotation, $typeRevenu);

                if ($typeRevenu->isAppliquerPourcentageDuRisque()) {
                    $risque = $this->getCotationRisque($cotation);
                    if ($risque) {
                        $montant += $montantChargementPrime * $risque->getPourcentageCommissionSpecifiqueHT();
                    }
                } else {
                    if ($revenu->getTauxExceptionel() != 0) {
                        $montant += $montantChargementPrime * $revenu->getTauxExceptionel();
                    } elseif ($revenu->getMontantFlatExceptionel() != 0) {
                        $montant += $revenu->getMontantFlatExceptionel();
                    } elseif ($typeRevenu->getPourcentage() != 0) {
                        $montant += $montantChargementPrime * $typeRevenu->getPourcentage();
                    } elseif ($typeRevenu->getMontantflat() != 0) {
                        $montant += $typeRevenu->getMontantflat();
                    }
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantChargementPrime(?Cotation $cotation, ?TypeRevenu $typeRevenu)
    {
        $montantChargementCible = 0;
        if ($cotation != null && $typeRevenu != null) {
            foreach ($cotation->getChargements() as $loading) {
                if ($loading->getType() == $typeRevenu->getTypeChargement()) {
                    $montantChargementCible = $loading->getMontantFlatExceptionel();
                }
            }
        }
        return $montantChargementCible;
    }

    private function getCotationRisque(?Cotation $cotation)
    {
        if ($cotation && $cotation->getPiste()) {
            return $cotation->getPiste()->getRisque();
        }
        return null;
    }

    private function calculerRetroCommission(?Risque $risque, ?ConditionPartage $conditionPartage, $assiette): float
    {
        if (!$conditionPartage || !$risque) {
            return 0.0;
        }

        $taux = $conditionPartage->getTaux();
        $produitsCible = $conditionPartage->getProduits();

        switch ($conditionPartage->getCritereRisque()) {
            case ConditionPartage::CRITERE_EXCLURE_TOUS_CES_RISQUES:
                if (!$produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_INCLURE_TOUS_CES_RISQUES:
                if ($produitsCible->contains($risque)) {
                    return $assiette * $taux;
                }
                return 0.0;

            case ConditionPartage::CRITERE_PAS_RISQUES_CIBLES:
                return $assiette * $taux;
        }
        return 0.0;
    }

    private function getCotationPartenaire(?Cotation $cotation)
    {
        if ($cotation?->getPiste()) {
            if (!$cotation->getPiste()->getPartenaires()->isEmpty()) {
                return $cotation->getPiste()->getPartenaires()->first();
            }

            $client = $cotation->getPiste()->getClient();
            if ($client && !$client->getPartenaires()->isEmpty()) {
                return $client->getPartenaires()->first();
            }
        }
        return null;
    }
}