<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Piste;
use App\Entity\Cotation;
use App\Entity\Risque;
use App\Entity\Tranche;
use App\Entity\Partenaire;
use App\Entity\RevenuPourCourtier;
use App\Entity\ConditionPartage;
use App\Entity\TypeRevenu;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class PisteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private ServiceTaxes $serviceTaxes,
        private TaxeRepository $taxeRepository
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

    // --- Méthodes privées déplacées depuis CalculationProvider ---

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
            if ($this->isCotationBound($cotation)) {
                return 'Transformée (Souscrite)';
            }
        }
        return 'En cours';
    }

    private function calculatePisteAge(Piste $piste): string
    {
        if (!$piste->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($piste->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function aggregateSubscribedCotationIndicator(Piste $piste, string $indicatorName): float
    {
        $total = 0.0;
        $precomputedSums = ['by_risque' => [], 'by_client' => [], 'by_partenaire' => []];

        foreach ($piste->getCotations() as $cotation) {
            if ($this->isCotationBound($cotation)) {
                $val = match ($indicatorName) {
                    'primeTotale' => $this->getCotationMontantPrimePayableParClient($cotation),
                    'primePayee' => $this->getCotationMontantPrimePayableParClientPayee($cotation),
                    'primeSoldeDue' => $this->getCotationMontantPrimePayableParClient($cotation) - $this->getCotationMontantPrimePayableParClientPayee($cotation),
                    'montantTTC' => $this->getCotationMontantCommissionTtc($cotation, -1, false),
                    'montant_paye' => $this->getCotationMontantCommissionEncaissee($cotation),
                    'solde_restant_du' => $this->getCotationMontantCommissionTtc($cotation, -1, false) - $this->getCotationMontantCommissionEncaissee($cotation),
                    'montantPur' => $this->getCotationMontantCommissionPure($cotation, -1, false),
                    'retroCommission' => $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, $precomputedSums),
                    'reserve' => $this->getCotationMontantCommissionPure($cotation, -1, false) - $this->getCotationMontantRetrocommissionsPayableParCourtier($cotation, null, -1, $precomputedSums),
                    default => 0.0,
                };
                $total += $val;
            }
        }
        return $total;
    }

    private function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && !$cotation->getAvenants()->isEmpty();
    }

    private function getCotationMontantPrimePayableParClient(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getChargements() as $chargement) {
                $montant += $chargement->getMontantFlatExceptionel();
            }
        }
        return $montant;
    }

    private function getCotationMontantPrimePayableParClientPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTranchePrimePayee($tranche);
            }
        }
        return $montant;
    }

    private function getTranchePrimePayee(Tranche $tranche): float
    {
        $montant = 0.0;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT) {
                $montantPayableNote = $this->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                    $montant += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montant;
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

    private function getCotationMontantCommissionTtc(?Cotation $cotation, ?int $addressedTo, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $comTTCAssureur = $this->getCotationMontantCommissionTtcPayableParAssureur($cotation, $onlySharable);
        $comTTCClient = $this->getCotationMontantCommissionTtcPayableParClient($cotation, $onlySharable);
        return round($comTTCAssureur + $comTTCClient, 2);
    }

    private function getCotationMontantCommissionTtcPayableParAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    private function getCotationMontantCommissionTtcPayableParClient(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $taxe = $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
        return $net + $taxe;
    }

    private function isIARD(?Cotation $cotation): bool
    {
        if ($cotation && $cotation->getPiste() && $cotation->getPiste()->getRisque()) {
            return $cotation->getPiste()->getRisque()->getBranche() == Risque::BRANCHE_IARD_OU_NON_VIE;
        }
        return false;
    }

    private function getCotationMontantCommissionEncaissee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantCommissionEncaissee($tranche);
            }
        }
        return $montant;
    }

    private function getTrancheMontantCommissionEncaissee(?Tranche $tranche): float
    {
        $montant = 0;
        if ($tranche) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if ($note && ($note->getAddressedTo() == Note::TO_ASSUREUR || $note->getAddressedTo() == Note::TO_CLIENT)) {
                    $montantPayableNote = $this->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                        $montant += $proportionPaiement * $article->getMontant();
                    }
                }
            }
        }
        return $montant;
    }

    private function getCotationMontantCommissionPure(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $comHT = $this->getCotationMontantCommissionHt($cotation, $addressedTo, $onlySharable);
        $taxeCourtier = $this->getCotationMontantTaxePayableParCourtier($cotation, $onlySharable);
        return $comHT - $taxeCourtier;
    }

    private function getCotationMontantTaxePayableParCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        return $this->getTotalNet($cotation, $onlySharable, false);
    }

    private function getTotalNet(?Cotation $cotation, bool $onlySharable, bool $isTaxAssureur): float
    {
        if (!$cotation) return 0.0;
        $isIARD = $this->isIARD($cotation);
        $net_payable_par_assureur = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_ASSUREUR, $onlySharable);
        $net_payable_par_client = $this->getCotationMontantCommissionHt($cotation, TypeRevenu::REDEVABLE_CLIENT, $onlySharable);
        $net_total = $net_payable_par_assureur + $net_payable_par_client;
        return $this->serviceTaxes->getMontantTaxe($net_total, $isIARD, $isTaxAssureur);
    }

    private function getCotationMontantCommissionHt(?Cotation $cotation, $addressedTo, bool $onlySharable): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                if ($onlySharable == true) {
                    if ($revenu->getTypeRevenu()->isShared() == $onlySharable) {
                        $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                    }
                } else {
                    $montant += $this->getRevenuMontantHtAddressedTo($addressedTo, $revenu);
                }
            }
        }
        return $montant;
    }

    private function getRevenuMontantHtAddressedTo($addressedTo, RevenuPourCourtier $revenu)
    {
        $montant = 0;
        if ($addressedTo != -1) {
            if ($revenu->getTypeRevenu()->getRedevable() == $addressedTo) {
                $montant += $this->getRevenuMontantHt($revenu);
            }
        } else {
            $montant += $this->getRevenuMontantHt($revenu);
        }
        return $montant;
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

    private function getCotationMontantChargementPrime(Cotation $cotation, TypeRevenu $typeRevenu)
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

    private function getCotationMontantRetrocommissionsPayableParCourtier(?Cotation $cotation, ?Partenaire $partenaireCible, $addressedTo, array $precomputedSums): float
    {
        if (!$cotation) {
            return 0.0;
        }
        $montant = 0.0;
        foreach ($cotation->getRevenus() as $revenu) {
            $montant += $this->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, $partenaireCible, $addressedTo, $precomputedSums);
        }
        return $montant;
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
            $assiette = $this->getRevenuMontantPure($revenu, $addressedTo, true);
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
}