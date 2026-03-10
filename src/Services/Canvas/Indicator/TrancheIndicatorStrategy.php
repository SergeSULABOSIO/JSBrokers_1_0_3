<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Tranche;
use App\Entity\Cotation;
use App\Entity\Taxe;
use App\Entity\Partenaire;
use App\Entity\RevenuPourCourtier;
use App\Entity\ConditionPartage;
use App\Entity\Risque;
use App\Entity\TypeRevenu;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use App\Services\ServiceTaxes;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

class TrancheIndicatorStrategy implements IndicatorCalculationStrategyInterface
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
        return $entityClassName === Tranche::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Tranche $entity */
        $cotation = $entity->getCotation(); // Récupérer la cotation parente pour réutiliser sa logique.
        
        $isBound = $cotation && $this->isCotationBound($cotation);
        $statutSuffix = $isBound ? '(Police)' : '(Projet)';
        
        $nomComplet = $entity->getNom() . ' ' . $statutSuffix;
        if ($isBound) {
            $refPolice = $this->getCotationReferencePolice($cotation);
            if ($refPolice && $refPolice !== 'Nulle') {
                $nomComplet .= ' #' . $refPolice;
            }
        }

        return [
            'nomCompletAvecStatut' => $nomComplet,
            'clientDescription' => $this->getClientDescriptionFromCotation($cotation),
            'risqueDescription' => $this->getRisqueDescriptionFromCotation($cotation),
            'ageTranche' => $this->calculateTrancheAge($entity),
            'joursRestantsAvantEcheance' => $this->calculateTrancheJoursRestants($entity),
            'contexteParent' => $this->getTrancheContexteParent($entity),
            'pourcentageAffiche' => $this->getTrancheTauxDisplay($entity),
            'clientNom' => $entity->getCotation()?->getPiste()?->getClient()?->getNom() ?? 'N/A',
            'cotationNom' => $entity->getCotation()?->getNom() ?? 'N/A',
            'referencePolice' => $cotation ? $this->getCotationReferencePolice($cotation) : 'N/A',
            'periodeCouverture' => $cotation ? $this->getCotationPeriodeCouverture($cotation) : 'N/A',
            'assureurNom' => $cotation?->getAssureur()?->getNom() ?? 'N/A',
            'primeTranche' => round($this->getTranchePrime($entity), 2),
            'primePayee' => round($this->getTranchePrimePayee($entity), 2),
            'primeSoldeDue' => round($this->getTranchePrimeSoldeDue($entity), 2),
            'tauxTranche' => $this->getTrancheTauxDisplay($entity),
            'montantCalculeHT' => round($this->getTrancheMontantHT($entity), 2),
            'montantCalculeTTC' => round($this->getTrancheMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getTrancheDescriptionCalcul($entity),
            'taxeCourtierMontant' => round($this->getTrancheTaxeCourtierMontant($entity), 2),
            'taxeCourtierTaux' => $this->getTrancheTaxeCourtierTaux($entity),
            'taxeAssureurMontant' => round($this->getTrancheTaxeAssureurMontant($entity), 2),
            'taxeAssureurTaux' => $this->getTrancheTaxeAssureurTaux($entity),
            'montant_du' => round($this->getTrancheMontantTTC($entity), 2),
            'montant_paye' => round($this->getTrancheMontantCommissionEncaissee($entity), 2),
            'solde_restant_du' => round($this->getTrancheMontantTTC($entity) - $this->getTrancheMontantCommissionEncaissee($entity), 2),
            'taxeCourtierPayee' => round($this->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeCourtierSolde' => round($this->getTrancheTaxeCourtierMontant($entity) - $this->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeAssureurPayee' => round($this->getTrancheMontantTaxePayee($entity, true), 2),
            'taxeAssureurSolde' => round($this->getTrancheTaxeAssureurMontant($entity) - $this->getTrancheMontantTaxePayee($entity, true), 2),
            'estPartageable' => $this->getTrancheEstPartageable($entity),
            'montantPur' => round($this->getTrancheMontantPur($entity), 2),
            'partPartenaire' => $this->getTranchePartPartenaire($entity),
            'retroCommission' => round($this->getTrancheRetroCommission($entity), 2),
            'retroCommissionReversee' => round($this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            'retroCommissionSolde' => round($this->getTrancheRetroCommission($entity) - $this->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            'reserve' => round($this->getTrancheReserve($entity), 2),
            'statutPaiement' => $this->getTrancheStatutPaiement($entity),
            'tauxAvancement' => $this->getTrancheTauxAvancement($entity),
            'resteAPayer' => round($this->getTranchePrimeSoldeDue($entity), 2),
            'retardPaiement' => $this->getTrancheRetardPaiement($entity),
            'dateDernierEncaissement' => $this->getTrancheDateDernierEncaissement($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

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

    private function calculateTrancheAge(Tranche $tranche): string
    {
        if (!$tranche->getCreatedAt()) {
            return 'N/A';
        }
        $jours = $this->serviceDates->daysEntre($tranche->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheJoursRestants(Tranche $tranche): string
    {
        if (!$tranche->getEcheanceAt()) {
            return 'N/A';
        }
        $now = new DateTimeImmutable();
        if ($tranche->getEcheanceAt() < $now) {
            return 'Échue';
        }
        $jours = $this->serviceDates->daysEntre($now, $tranche->getEcheanceAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function getTrancheContexteParent(Tranche $tranche): string
    {
        return $tranche->getCotation() ? (string) $tranche->getCotation() : 'N/A';
    }

    private function calculateTrancheTauxFactor(Tranche $tranche): float
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            $valeur = $tranche->getPourcentage();
            if ($valeur > 1) {
                return $valeur / 100;
            }
            return $valeur;
        }

        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            $cotation = $tranche->getCotation();
            if ($cotation) {
                $primeTotale = $this->getCotationMontantPrimePayableParClient($cotation);
                if ($primeTotale > 0) {
                    return $tranche->getMontantFlat() / $primeTotale;
                }
            }
        }

        return 0.0;
    }

    private function getTrancheTauxDisplay(Tranche $tranche): float
    {
        return $this->calculateTrancheTauxFactor($tranche) * 100;
    }

    private function getTrancheDescriptionCalcul(Tranche $tranche): string
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            $tauxAffiche = $this->getTrancheTauxDisplay($tranche);
            return "Basé sur le taux défini de " . $tauxAffiche . "%";
        }
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            return "Calculé : Montant fixe (" . $tranche->getMontantFlat() . ") / Prime Totale";
        }
        return "Taux non défini (0%)";
    }

    private function getTranchePrime(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $primeTotale = $this->getCotationMontantPrimePayableParClient($tranche->getCotation());
        return $primeTotale * $taux;
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

    private function getTranchePrimeSoldeDue(Tranche $tranche): float
    {
        return $this->getTranchePrime($tranche) - $this->getTranchePrimePayee($tranche);
    }

    private function getTrancheMontantHT(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationHT = $this->getCotationMontantCommissionHt($tranche->getCotation(), -1, false);
        return $cotationHT * $taux;
    }

    private function getTrancheMontantTTC(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTTC = $this->getCotationMontantCommissionTtc($tranche->getCotation(), -1, false);
        return $cotationTTC * $taux;
    }

    private function getTrancheTaxeCourtierMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->getCotationMontantTaxeCourtier($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeAssureurMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->getCotationMontantTaxeAssureur($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeCourtierTaux(Tranche $tranche): float
    {
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER]);
        if (!$taxe) return 0.0;
        $isIARD = $this->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getTrancheTaxeAssureurTaux(Tranche $tranche): float
    {
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR]);
        if (!$taxe) return 0.0;
        $isIARD = $this->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
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

    private function getTrancheEstPartageable(Tranche $tranche): string
    {
        $cotation = $tranche->getCotation();
        if ($cotation) {
            foreach ($cotation->getRevenus() as $revenu) {
                if ($revenu->getTypeRevenu() && $revenu->getTypeRevenu()->isShared()) {
                    return 'Oui';
                }
            }
        }
        return 'Non';
    }

    private function getTrancheMontantPur(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationPure = $this->getCotationMontantCommissionPure($tranche->getCotation(), -1, false);
        return $cotationPure * $taux;
    }

    private function getTranchePartPartenaire(Tranche $tranche): float
    {
        $partenaire = $this->getCotationPartenaire($tranche->getCotation());
        return $partenaire ? ($partenaire->getPart() * 100) : 0.0;
    }

    private function getTrancheRetroCommission(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationRetro = $this->getCotationMontantRetrocommissionsPayableParCourtier($tranche->getCotation(), null, -1, []);
        return $cotationRetro * $taux;
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

    private function getTrancheReserve(Tranche $tranche): float
    {
        return $this->getTrancheMontantPur($tranche) - $this->getTrancheRetroCommission($tranche);
    }

    private function getTrancheStatutPaiement(Tranche $tranche): string
    {
        $prime = $this->getTranchePrime($tranche);
        $paye = $this->getTranchePrimePayee($tranche);

        if ($prime <= 0) return 'N/A';
        if ($paye >= $prime) return 'Payée';
        if ($paye > 0) return 'Partiellement payée';
        return 'Non payée';
    }

    private function getTrancheTauxAvancement(Tranche $tranche): float
    {
        $prime = $this->getTranchePrime($tranche);
        if ($prime <= 0) return 0.0;
        return round(($this->getTranchePrimePayee($tranche) / $prime) * 100, 2);
    }

    private function getTrancheRetardPaiement(Tranche $tranche): string
    {
        $solde = $this->getTranchePrimeSoldeDue($tranche);
        if ($solde <= 0) return 'Non';

        $echeance = $tranche->getEcheanceAt();
        if (!$echeance) return 'N/A';

        $now = new DateTimeImmutable();
        if ($echeance < $now) {
            $jours = $this->serviceDates->daysEntre($echeance, $now);
            return "Oui (" . $jours . " jours)";
        }
        return 'Non';
    }

    private function getTrancheDateDernierEncaissement(Tranche $tranche): ?\DateTimeInterface
    {
        $lastDate = null;
        foreach ($tranche->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_CLIENT) {
                foreach ($note->getPaiements() as $paiement) {
                    if ($paiement->getPaidAt() && (!$lastDate || $paiement->getPaidAt() > $lastDate)) {
                        $lastDate = $paiement->getPaidAt();
                    }
                }
            }
        }
        return $lastDate;
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
        if ($cotation) {
            if ($cotation->getPiste()) {
                return $cotation->getPiste()->getRisque();
            }
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

    private function isSamePartenaire(?Partenaire $partenaire, ?Partenaire $partenaireCible): bool
    {
        if ($partenaireCible == null) {
            return true;
        } else {
            if ($partenaireCible != $partenaire) {
                return false;
            } else {
                return true;
            }
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

    private function getTranchePartenaire(?Tranche $tranche)
    {
        if ($tranche != null) {
            if ($tranche->getCotation() != null) {
                return $this->getCotationPartenaire($tranche->getCotation());
            }
        }

        return null;
    }
}