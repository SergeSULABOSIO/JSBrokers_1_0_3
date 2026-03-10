<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Cotation;
use App\Entity\Tranche;
use App\Entity\Partenaire;
use App\Entity\Risque;
use App\Entity\ConditionPartage;
use App\Entity\RevenuPourCourtier;
use App\Entity\TypeRevenu;
use App\Entity\Chargement;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\Paiement;
use App\Entity\NotificationSinistre;
use App\Entity\OffreIndemnisationSinistre;
use App\Repository\NotificationSinistreRepository;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use Symfony\Contracts\Translation\TranslatorInterface;
use DateTimeImmutable;

class CotationIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private ServiceTaxes $serviceTaxes,
        private NotificationSinistreRepository $notificationSinistreRepository,
        private TaxeRepository $taxeRepository,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Cotation::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Cotation $entity */
        return [
            'clientDescription' => $this->getClientDescriptionFromCotation($entity),
            'risqueDescription' => $this->getRisqueDescriptionFromCotation($entity),
            'contextePiste' => $this->getCotationContextePiste($entity),
            'statutSouscription' => $this->calculateStatutSouscription($entity),
            'referencePolice' => $this->getCotationReferencePolice($entity),
            'periodeCouverture' => $this->getCotationPeriodeCouverture($entity),
            'indemnisationDue' => round($this->getCotationIndemnisationDue($entity), 2),
            'indemnisationVersee' => round($this->getCotationIndemnisationVersee($entity), 2),
            'indemnisationSolde' => round($this->getCotationIndemnisationSolde($entity), 2),
            'tauxSP' => $this->getCotationTauxSP($entity),
            'tauxSPInterpretation' => $this->getCotationTauxSPInterpretation($entity),
            'dateDernierReglement' => $this->getCotationDateDernierReglement($entity),
            'vitesseReglement' => $this->getCotationVitesseReglement($entity),
            'delaiDepuisCreation' => $this->calculateDelaiDepuisCreation($entity),
            'nombreTranches' => $this->calculateNombreTranches($entity),
            'montantMoyenTranche' => $this->calculateMontantMoyenTranche($entity),
            'primeTotale' => round($this->getCotationMontantPrimePayableParClient($entity), 2),
            'primePayee' => round($this->getCotationMontantPrimePayableParClientPayee($entity), 2),
            'primeSoldeDue' => round($this->getCotationMontantPrimePayableParClient($entity) - $this->getCotationMontantPrimePayableParClientPayee($entity), 2),
            'tauxCommission' => $this->getCotationTauxCommission($entity),
            'montantHT' => round($this->getCotationMontantCommissionHt($entity, -1, false), 2),
            'montantTTC' => round($this->getCotationMontantCommissionTtc($entity, -1, false), 2),
            'detailCalcul' => "Somme des revenus",
            'taxeCourtierMontant' => round($this->getCotationMontantTaxeCourtier($entity, false), 2),
            'taxeAssureurMontant' => round($this->getCotationMontantTaxeAssureur($entity, false), 2),
            'montant_du' => round($this->getCotationMontantCommissionTtc($entity, -1, false), 2),
            'montant_paye' => round($this->getCotationMontantCommissionEncaissee($entity), 2),
            'solde_restant_du' => round($this->getCotationMontantCommissionTtc($entity, -1, false) - $this->getCotationMontantCommissionEncaissee($entity), 2),
            'taxeCourtierPayee' => round($this->getCotationMontantTaxeCourtierPayee($entity), 2),
            'taxeCourtierSolde' => round($this->getCotationMontantTaxeCourtier($entity, false) - $this->getCotationMontantTaxeCourtierPayee($entity), 2),
            'taxeAssureurPayee' => round($this->getCotationMontantTaxeAssureurPayee($entity), 2),
            'taxeAssureurSolde' => round($this->getCotationMontantTaxeAssureur($entity, false) - $this->getCotationMontantTaxeAssureurPayee($entity), 2),
            'montantPur' => round($this->getCotationMontantCommissionPure($entity, -1, false), 2),
            'retroCommission' => round($this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
            'retroCommissionReversee' => round($this->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
            'retroCommissionSolde' => round($this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []) - $this->getCotationMontantRetrocommissionsPayableParCourtierPayee($entity, null), 2),
            'reserve' => round($this->getCotationMontantCommissionPure($entity, -1, false) - $this->getCotationMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

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

    private function getCotationContextePiste(Cotation $cotation): string
    {
        $piste = $cotation->getPiste();
        if (!$piste) {
            return "Cette cotation n'est rattachée à aucune piste.";
        }
        $pisteNom = $piste->getNom() ?? 'N/A';
        $clientNom = $piste->getClient() ? $piste->getClient()->getNom() : 'non défini';

        return sprintf("Piste '%s' pour le client '%s'", $pisteNom, $clientNom);
    }

    private function calculateStatutSouscription(Cotation $cotation): string
    {
        return $this->isCotationBound($cotation) ? 'Souscrite' : 'En attente';
    }

    private function isCotationBound(?Cotation $cotation): bool
    {
        return $cotation && !$cotation->getAvenants()->isEmpty();
    }

    private function getCotationReferencePolice(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) {
            return 'Nulle';
        }
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }

    private function getCotationPeriodeCouverture(Cotation $cotation): string
    {
        if ($cotation->getAvenants()->isEmpty()) {
            return 'Aucune';
        }
        $avenant = $cotation->getAvenants()->first();
        if ($avenant->getStartingAt() && $avenant->getEndingAt()) {
            return sprintf("Du %s au %s", $avenant->getStartingAt()->format('d/m/Y'), $avenant->getEndingAt()->format('d/m/Y'));
        }
        return 'Période incomplète';
    }

    private function getCotationClaims(Cotation $cotation): array
    {
        $ref = $this->getCotationReferencePolice($cotation);
        if ($ref === 'Nulle') return [];
        return $this->notificationSinistreRepository->findBy(['referencePolice' => $ref]);
    }

    private function getCotationIndemnisationDue(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensation($claim);
        }
        return $total;
    }

    private function getCotationIndemnisationVersee(Cotation $cotation): float
    {
        $claims = $this->getCotationClaims($cotation);
        $total = 0.0;
        foreach ($claims as $claim) {
            $total += $this->getNotificationSinistreCompensationVersee($claim);
        }
        return $total;
    }

    private function getCotationIndemnisationSolde(Cotation $cotation): float
    {
        return $this->getCotationIndemnisationDue($cotation) - $this->getCotationIndemnisationVersee($cotation);
    }

    private function getCotationTauxSP(Cotation $cotation): float
    {
        $prime = $this->getCotationMontantPrimePayableParClient($cotation);
        $sinistre = $this->getCotationIndemnisationDue($cotation);
        if ($prime > 0) {
            return round(($sinistre / $prime) * 100, 2);
        }
        return 0.0;
    }

    private function getCotationTauxSPInterpretation(Cotation $cotation): string
    {
        $taux = $this->getCotationTauxSP($cotation);
        $indemnisationDue = $this->getCotationIndemnisationDue($cotation);

        if ($indemnisationDue == 0) {
            return "Aucun sinistre indemnisable enregistré pour cette police.";
        }

        if ($taux == 0 && $indemnisationDue > 0) { 
            return "La prime étant nulle ou négative, le ratio est infini.";
        }

        return $this->calculationHelper->getInterpretationTauxSP($taux);
    }

    private function getCotationDateDernierReglement(Cotation $cotation): ?\DateTimeInterface
    {
        $claims = $this->getCotationClaims($cotation);
        $lastDate = null;
        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastDate === null || $date > $lastDate)) {
                $lastDate = $date;
            }
        }
        return $lastDate;
    }

    private function getCotationVitesseReglement(Cotation $cotation): string
    {
        $solde = $this->getCotationIndemnisationSolde($cotation);
        if ($solde > 0) return "Traitement encours";
        
        $claims = $this->getCotationClaims($cotation);
        if (empty($claims)) return "Aucun sinistre";

        $lastPaymentDate = null;
        $associatedClaim = null;

        foreach ($claims as $claim) {
            $date = $this->getNotificationSinistreDateDernierReglement($claim);
            if ($date && ($lastPaymentDate === null || $date > $lastPaymentDate)) {
                $lastPaymentDate = $date;
                $associatedClaim = $claim;
            }
        }

        if ($lastPaymentDate && $associatedClaim && $associatedClaim->getNotifiedAt()) {
            $days = $this->serviceDates->daysEntre($associatedClaim->getNotifiedAt(), $lastPaymentDate);
            return $days . " jour(s)";
        }
        
        return "N/A";
    }

    private function calculateDelaiDepuisCreation(Cotation $cotation): string
    {
        if (!$cotation->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($cotation->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateNombreTranches(Cotation $cotation): int
    {
        return $cotation->getTranches()->count();
    }

    private function calculateMontantMoyenTranche(Cotation $cotation): float
    {
        $nombreTranches = $this->calculateNombreTranches($cotation);
        if ($nombreTranches === 0) {
            return 0.0;
        }

        $primeTotale = 0.0;
        foreach ($cotation->getChargements() as $chargement) {
            $primeTotale += $chargement->getMontantFlatExceptionel() ?? 0;
        }

        if ($primeTotale > 0) {
            return round($primeTotale / $nombreTranches, 2);
        }

        return 0.0;
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

    private function getCotationTauxCommission(?Cotation $cotation): float
    {
        $prime = $this->getCotationMontantPrimePayableParClient($cotation);
        if ($prime > 0) {
            return round(($this->getCotationMontantCommissionHt($cotation, -1, false) / $prime) * 100, 2);
        }
        return 0.0;
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

    private function getCotationMontantTaxeCourtier(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), false);
    }

    private function getCotationMontantTaxeAssureur(?Cotation $cotation, bool $onlySharable): float
    {
        if (!$cotation) return 0;
        $net = $this->getCotationMontantCommissionHt($cotation, -1, $onlySharable);
        return $this->serviceTaxes->getMontantTaxe($net, $this->isIARD($cotation), true);
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
                $montant += $encaisse->getMontant();
            }
        }
        return $montant;
    }

    private function getCotationMontantTaxeCourtierPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, false);
            }
        }
        return $montant;
    }

    private function getTrancheMontantTaxePayee(?Tranche $tranche, bool $isTaxeAssureur): float
    {
        $montant = 0.0;
        if (!$tranche) {
            return $montant;
        }

        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());

                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
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

    private function getCotationMontantTaxeAssureurPayee(?Cotation $cotation): float
    {
        $montant = 0;
        if ($cotation) {
            foreach ($cotation->getTranches() as $tranche) {
                $montant += $this->getTrancheMontantTaxePayee($tranche, true);
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

    private function getCotationMontantRetrocommissionsPayableParCourtierPayee(?Cotation $cotation, ?Partenaire $partenaireCible): float
    {
        $montant = 0;
        if ($cotation != null) {
            $partenaire = $this->getCotationPartenaire($cotation);
            if ($partenaire) {
                if ($this->isSamePartenaire($partenaire, $partenaireCible)) {
                    foreach ($cotation->getTranches() as $tranche) {
                        $montant += $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($tranche, $partenaireCible);
                    }
                }
            }
        }
        return $montant;
    }

    private function getTrancheMontantRetrocommissionsPayableParCourtierPayee(?Tranche $tranche, ?Partenaire $partenaireCible = null): float
    {
        $montant = 0;
        if (!$tranche || $tranche->getArticles()->isEmpty()) {
            return 0.0;
        }

        if ($this->isSamePartenaire($this->getTranchePartenaire($tranche), $partenaireCible)) {
            foreach ($tranche->getArticles() as $article) {
                $note = $article->getNote();
                if (!$note) {
                    continue;
                }

                $montantPayableNote = $this->getNoteMontantPayable($note);
                $proportionPaiement = 0;
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->getNoteMontantPaye($note) / $montantPayableNote;
                }

                if ($note->getAddressedTo() == Note::TO_PARTENAIRE) {
                    $montant += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montant;
    }

    private function getTranchePartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->getCotationPartenaire($tranche->getCotation());
            }
        }
        return null;
    }

    private function getNotificationSinistreCompensation(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + ($offre->getMontantPayable() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreCompensationVersee(NotificationSinistre $sinistre): float
    {
        return array_reduce($sinistre->getOffreIndemnisationSinistres()->toArray(), function ($carry, OffreIndemnisationSinistre $offre) {
            return $carry + $this->getOffreIndemnisationCompensationVersee($offre);
        }, 0.0);
    }

    private function getOffreIndemnisationCompensationVersee(OffreIndemnisationSinistre $offre_indemnisation): float
    {
        return array_reduce($offre_indemnisation->getPaiements()->toArray(), function ($carry, Paiement $paiement) {
            return $carry + ($paiement->getMontant() ?? 0);
        }, 0.0);
    }

    private function getNotificationSinistreDateDernierReglement(NotificationSinistre $sinistre): ?\DateTimeInterface
    {
        $dateDernierReglement = null;
        foreach ($sinistre->getOffreIndemnisationSinistres() as $offre) {
            foreach ($offre->getPaiements() as $paiement) {
                if ($paiement->getPaidAt() && (!$dateDernierReglement || $paiement->getPaidAt() > $dateDernierReglement)) {
                    $dateDernierReglement = $paiement->getPaidAt();
                }
            }
        }
        return $dateDernierReglement;
    }
}