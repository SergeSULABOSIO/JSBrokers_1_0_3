<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RevenuPourCourtier;
use App\Entity\Cotation;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Partenaire;
use App\Entity\ConditionPartage;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Taxe;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use Symfony\Contracts\Translation\TranslatorInterface;

class RevenuPourCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private TaxeRepository $taxeRepository,
        private ServiceTaxes $serviceTaxes
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RevenuPourCourtier $entity */
        $cotation = $entity->getCotation();
        $clientNom = $cotation?->getPiste()?->getClient()?->getNom() ?? 'N/A';
        $refPolice = $cotation ? $this->getCotationReferencePolice($cotation) : 'N/A';
        $nomComplet = sprintf("%s sur Police #%s", $entity->getNom(), $refPolice);

        return [
            'nomCompletAvecStatut' => $nomComplet,
            'referencePolice' => $refPolice,
            'clientNom' => $clientNom,
            'typeRevenuNom' => $entity->getTypeRevenu()?->getNom() ?? 'N/A',
            'clientDescription' => $this->getClientDescriptionFromCotation($entity->getCotation()),
            'risqueDescription' => $this->getRisqueDescriptionFromCotation($entity->getCotation()),
            'montantCalculeHT' => round($this->getRevenuMontantHt($entity), 2),
            'montantCalculeTTC' => round($this->getRevenuPourCourtierMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
            'montant_du' => round($this->getRevenuPourCourtierMontantDu($entity), 2),
            'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
            'solde_restant_du' => round($this->getRevenuPourCourtierSoldeRestantDu($entity), 2),
            'montantPur' => round($this->getRevenuMontantPur($entity), 2),
            'partPartenaire' => $this->getRevenuPartPartenaire($entity),
            'retroCommission' => round($this->getRevenuRetroCommission($entity), 2),
            'reserve' => round($this->getRevenuReserve($entity), 2),
            'retroCommissionReversee' => round($this->getRevenuRetroCommissionReversee($entity), 2),
            'retroCommissionSolde' => round($this->getRevenuRetroCommissionSolde($entity), 2),
            'taxeCourtierMontant' => round($this->getRevenuTaxeCourtierMontant($entity), 2),
            'taxeCourtierTaux' => $this->getRevenuTaxeCourtierTaux($entity),
            'taxeAssureurMontant' => round($this->getRevenuTaxeAssureurMontant($entity), 2),
            'taxeAssureurTaux' => $this->getRevenuTaxeAssureurTaux($entity),
            'estPartageable' => $this->getRevenuEstPartageable($entity),
            'taxeCourtierPayee' => round($this->getRevenuTaxeCourtierPayee($entity), 2),
            'taxeCourtierSolde' => round($this->getRevenuTaxeCourtierSolde($entity), 2),
            'taxeAssureurPayee' => round($this->getRevenuTaxeAssureurPayee($entity), 2),
            'taxeAssureurSolde' => round($this->getRevenuTaxeAssureurSolde($entity), 2),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getCotationReferencePolice(?Cotation $cotation): string
    {
        if (!$cotation || $cotation->getAvenants()->isEmpty()) {
            return 'Nulle';
        }
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }

    private function getClientDescriptionFromCotation(?Cotation $cotation): string
    {
        if (!$cotation || !$cotation->getPiste() || !$cotation->getPiste()->getClient()) {
            return 'N/A';
        }
        return $cotation->getPiste()->getClient()->getNom();
    }

    private function getRisqueDescriptionFromCotation(?Cotation $cotation): string
    {
        if (!$cotation || !$cotation->getPiste() || !$cotation->getPiste()->getRisque()) {
            return 'N/A';
        }
        return $cotation->getPiste()->getRisque()->getNomComplet();
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

    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    private function getRevenuPourCourtierMontantTTC(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        if ($montantHT === 0.0) {
            return 0.0;
        }
        $taxe = $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), true);
        return $montantHT + $taxe;
    }

    private function getRevenuPourCourtierMontantDu(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuPourCourtierMontantTTC($revenu);
    }

    private function getRevenuPourCourtierMontantPaye(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return round($montantPaye, 2);
    }

    private function getNoteMontantPayable(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getArticles() as $article) {
                $montant += $article->getMontant();
            }
        }
        return $montant;
    }

    private function getNoteMontantPaye(?Note $note): float
    {
        $montant = 0;
        if ($note) {
            foreach ($note->getPaiements() as $encaisse) {
                /** @var Paiement $paiement */
                $paiement = $encaisse;
                $montant += $paiement->getMontant();
            }
        }
        return $montant;
    }

    private function getRevenuPourCourtierSoldeRestantDu(RevenuPourCourtier $revenu): float
    {
        $montantDu = $this->getRevenuPourCourtierMontantDu($revenu);
        $montantPaye = $this->getRevenuPourCourtierMontantPaye($revenu);
        return round($montantDu - $montantPaye, 2);
    }

    private function getRevenuPourCourtierDescriptionCalcul(RevenuPourCourtier $revenu): string
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if (!$typeRevenu) {
            return "Type de revenu non défini";
        }

        if ($revenu->getTauxExceptionel() !== null && $revenu->getTauxExceptionel() != 0) {
            return "Taux exceptionnel de " . ($revenu->getTauxExceptionel() * 100) . "%";
        }
        if ($revenu->getMontantFlatExceptionel()) {
            return "Montant fixe exceptionnel de " . $revenu->getMontantFlatExceptionel();
        }
        if ($typeRevenu->getPourcentage() !== null && $typeRevenu->getPourcentage() != 0) {
            return "Taux par défaut de " . ($typeRevenu->getPourcentage() * 100) . "%";
        }
        if ($typeRevenu->getMontantflat()) {
            return "Montant fixe par défaut de " . $typeRevenu->getMontantflat();
        }
        if ($typeRevenu->isAppliquerPourcentageDuRisque() && $revenu->getCotation()?->getPiste()?->getRisque()) {
            $tauxRisque = $revenu->getCotation()->getPiste()->getRisque()->getPourcentageCommissionSpecifiqueHT();
            return "Taux du risque de " . ($tauxRisque * 100) . "%";
        }

        return "Logique de calcul non spécifiée";
    }

    private function getRevenuMontantPur(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $taxeCourtier = $this->serviceTaxes->getMontantTaxe($montantHT, $this->isIARD($revenu->getCotation()), false);
        return $montantHT - $taxeCourtier;
    }

    private function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        return $this->getPartenaireShareRateForRevenu($revenu) * 100;
    }

    private function getPartenaireShareRateForRevenu(RevenuPourCourtier $revenu): float
    {
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }
        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire) {
            return 0.0;
        }

        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            return $conditionsPartagePiste->first()->getTaux() ?? 0.0;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            return $conditionsPartagePartenaire->first()->getTaux() ?? 0.0;
        }

        return $partenaireAffaire->getPart() ?? 0.0;
    }

    private function getRevenuRetroCommission(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1, []);
    }

    private function getRevenuMontantRetrocommissionsPayableParCourtier(?RevenuPourCourtier $revenu, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        if (!$revenu || !$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }
        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $partenaireAffaire = $this->getCotationPartenaire($cotation);
        if (!$partenaireAffaire || !$this->isSamePartenaire($partenaireAffaire, $partenaireCible)) {
            return 0.0;
        }

        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            foreach ($conditionsPartagePiste as $condition) {
                $montant = $this->applyRevenuConditionsSpeciales($condition, $revenu, $addressedTo, $precomputedSums);
                if ($montant > 0) return $montant;
            }
            return 0.0;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            foreach ($conditionsPartagePartenaire as $condition) {
                $montant = $this->applyRevenuConditionsSpeciales($condition, $revenu, $addressedTo, $precomputedSums);
                if ($montant > 0) return $montant;
            }
            return 0.0;
        }

        if ($partenaireAffaire->getPart() > 0) {
            $assiette = $this->getRevenuMontantPure2($revenu, $addressedTo, true);
            return $assiette * $partenaireAffaire->getPart();
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

    private function isSamePartenaire(?Partenaire $partenaire, ?Partenaire $partenaireCible): bool
    {
        if ($partenaireCible == null) {
            return true;
        } else {
            return $partenaireCible == $partenaire;
        }
    }

    private function applyRevenuConditionsSpeciales(?ConditionPartage $conditionPartage, RevenuPourCourtier $revenu, $addressedTo, array $precomputedSums): float
    {
        $montant = 0;
        $assiette = $this->getRevenuMontantPure2($revenu, $addressedTo, true);
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

    private function getRevenuMontantPure2(?RevenuPourCourtier $revenu, $addressedTo, bool $onlySharable): float
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

    private function getRevenuReserve(RevenuPourCourtier $revenu): float
    {
        $montantPur = $this->getRevenuMontantPur($revenu);
        $retroCommission = $this->getRevenuRetroCommission($revenu);
        return $montantPur - $retroCommission;
    }

    private function getRevenuRetroCommissionReversee(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_PARTENAIRE) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuRetroCommissionSolde(RevenuPourCourtier $revenu): float
    {
        $retroCommissionDue = $this->getRevenuRetroCommission($revenu);
        $retroCommissionReversee = $this->getRevenuRetroCommissionReversee($revenu);
        return $retroCommissionDue - $retroCommissionReversee;
    }

    private function getRevenuTaxeCourtierMontant(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $isIARD = $this->isIARD($revenu->getCotation());
        return $this->serviceTaxes->getMontantTaxe($montantHT, $isIARD, false);
    }

    private function getRevenuTaxeCourtierTaux(RevenuPourCourtier $revenu): float
    {
        $isIARD = $this->isIARD($revenu->getCotation());
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER]);

        if (!$taxe) {
            return 0.0;
        }

        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getRevenuTaxeAssureurMontant(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->getRevenuMontantHt($revenu);
        $isIARD = $this->isIARD($revenu->getCotation());
        return $this->serviceTaxes->getMontantTaxe($montantHT, $isIARD, true);
    }

    private function getRevenuTaxeAssureurTaux(RevenuPourCourtier $revenu): float
    {
        $isIARD = $this->isIARD($revenu->getCotation());
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR]);

        if (!$taxe) {
            return 0.0;
        }

        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getRevenuEstPartageable(RevenuPourCourtier $revenu): string
    {
        if ($revenu->getTypeRevenu() && $revenu->getTypeRevenu()->isShared()) {
            return 'Oui';
        }
        return 'Non';
    }

    private function getRevenuTaxePayee(RevenuPourCourtier $revenu, bool $isTaxeAssureur): float
    {
        $montantPaye = 0.0;
        if (!$revenu) {
            return $montantPaye;
        }

        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());

                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                    }
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuTaxeCourtierPayee(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuTaxePayee($revenu, false);
    }

    private function getRevenuTaxeAssureurPayee(RevenuPourCourtier $revenu): float
    {
        return $this->getRevenuTaxePayee($revenu, true);
    }

    private function getRevenuTaxeCourtierSolde(RevenuPourCourtier $revenu): float
    {
        $montantTaxe = $this->getRevenuTaxeCourtierMontant($revenu);
        $montantPaye = $this->getRevenuTaxeCourtierPayee($revenu);
        return $montantTaxe - $montantPaye;
    }

    private function getRevenuTaxeAssureurSolde(RevenuPourCourtier $revenu): float
    {
        $montantTaxe = $this->getRevenuTaxeAssureurMontant($revenu);
        $montantPaye = $this->getRevenuTaxeAssureurPayee($revenu);
        return $montantTaxe - $montantPaye;
    }
}