<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Tranche;
use App\Entity\Taxe;
use App\Entity\Note;
use App\Repository\TaxeRepository;
use App\Services\ServiceDates;
use DateTimeImmutable;

class TrancheIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TaxeRepository $taxeRepository,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Tranche::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Tranche $entity */
        $cotation = $entity->getCotation();
        
        $isBound = $this->calculationHelper->isCotationBound($cotation);
        $statutSuffix = $isBound ? '(Police)' : '(Projet)';
        
        $nomComplet = $entity->getNom() . ' ' . $statutSuffix;
        if ($isBound) {
            $refPolice = $this->calculationHelper->getCotationReferencePolice($cotation);
            if ($refPolice && $refPolice !== 'Nulle') {
                $nomComplet .= ' #' . $refPolice;
            }
        }

        return [
            'nomCompletAvecStatut' => $nomComplet,
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($cotation),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($cotation),
            'ageTranche' => $this->calculateTrancheAge($entity),
            'joursRestantsAvantEcheance' => $this->calculateTrancheJoursRestants($entity),
            'contexteParent' => $cotation ? (string) $cotation : 'N/A',
            'pourcentageAffiche' => $this->getTrancheTauxDisplay($entity),
            'clientNom' => $cotation?->getPiste()?->getClient()?->getNom() ?? 'N/A',
            'cotationNom' => $cotation?->getNom() ?? 'N/A',
            'referencePolice' => $cotation ? $this->calculationHelper->getCotationReferencePolice($cotation) : 'N/A',
            'periodeCouverture' => $cotation ? $this->calculationHelper->getCotationPeriodeCouverture($cotation) : 'N/A',
            'assureurNom' => $cotation?->getAssureur()?->getNom() ?? 'N/A',
            'primeTranche' => round($this->getTranchePrime($entity), 2),
            'primePayee' => round($this->calculationHelper->getTranchePrimePayee($entity), 2),
            'primeSoldeDue' => round($this->getTranchePrime($entity) - $this->calculationHelper->getTranchePrimePayee($entity), 2),
            'tauxTranche' => $this->getTrancheTauxDisplay($entity),
            'montantCalculeHT' => round($this->getTrancheMontantHT($entity), 2),
            'montantCalculeTTC' => round($this->getTrancheMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getTrancheDescriptionCalcul($entity),
            'taxeCourtierMontant' => round($this->getTrancheTaxeCourtierMontant($entity), 2),
            'taxeCourtierTaux' => $this->getTrancheTaxeCourtierTaux($entity),
            'taxeAssureurMontant' => round($this->getTrancheTaxeAssureurMontant($entity), 2),
            'taxeAssureurTaux' => $this->getTrancheTaxeAssureurTaux($entity),
            'montant_du' => round($this->getTrancheMontantTTC($entity), 2),
            'montant_paye' => round($this->calculationHelper->getTrancheMontantCommissionEncaissee($entity), 2),
            'solde_restant_du' => round($this->getTrancheMontantTTC($entity) - $this->calculationHelper->getTrancheMontantCommissionEncaissee($entity), 2),
            'taxeCourtierPayee' => round($this->calculationHelper->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeCourtierSolde' => round($this->getTrancheTaxeCourtierMontant($entity) - $this->calculationHelper->getTrancheMontantTaxePayee($entity, false), 2),
            'taxeAssureurPayee' => round($this->calculationHelper->getTrancheMontantTaxePayee($entity, true), 2),
            'taxeAssureurSolde' => round($this->getTrancheTaxeAssureurMontant($entity) - $this->calculationHelper->getTrancheMontantTaxePayee($entity, true), 2),
            'estPartageable' => $this->getTrancheEstPartageable($entity),
            'montantPur' => round($this->getTrancheMontantPur($entity), 2),
            'partPartenaire' => $this->getTranchePartPartenaire($entity),
            'retroCommission' => round($this->getTrancheRetroCommission($entity), 2),
            'retroCommissionReversee' => round($this->calculationHelper->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            'retroCommissionSolde' => round($this->getTrancheRetroCommission($entity) - $this->calculationHelper->getTrancheMontantRetrocommissionsPayableParCourtierPayee($entity), 2),
            'reserve' => round($this->getTrancheMontantPur($entity) - $this->getTrancheRetroCommission($entity), 2),
            'statutPaiement' => $this->getTrancheStatutPaiement($entity),
            'tauxAvancement' => $this->getTrancheTauxAvancement($entity),
            'resteAPayer' => round($this->getTranchePrime($entity) - $this->calculationHelper->getTranchePrimePayee($entity), 2),
            'retardPaiement' => $this->getTrancheRetardPaiement($entity),
            'dateDernierEncaissement' => $this->getTrancheDateDernierEncaissement($entity),
        ];
    }

    private function calculateTrancheAge(Tranche $tranche): string
    {
        if (!$tranche->getCreatedAt()) return 'N/A';
        $jours = $this->serviceDates->daysEntre($tranche->getCreatedAt(), new DateTimeImmutable()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheJoursRestants(Tranche $tranche): string
    {
        if (!$tranche->getEcheanceAt()) return 'N/A';
        $now = new DateTimeImmutable();
        if ($tranche->getEcheanceAt() < $now) return 'Échue';
        $jours = $this->serviceDates->daysEntre($now, $tranche->getEcheanceAt()) ?? 0;
        return $jours . ' jour(s)';
    }

    private function calculateTrancheTauxFactor(Tranche $tranche): float
    {
        if ($tranche->getPourcentage() !== null && $tranche->getPourcentage() > 0) {
            $valeur = $tranche->getPourcentage();
            return ($valeur > 1) ? $valeur / 100 : $valeur;
        }

        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            $cotation = $tranche->getCotation();
            if ($cotation) {
                $primeTotale = $this->calculationHelper->getCotationMontantPrimePayableParClient($cotation);
                if ($primeTotale > 0) return $tranche->getMontantFlat() / $primeTotale;
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
            return "Basé sur le taux défini de " . $this->getTrancheTauxDisplay($tranche) . "%";
        }
        if ($tranche->getMontantFlat() !== null && $tranche->getMontantFlat() > 0) {
            return "Calculé : Montant fixe (" . $tranche->getMontantFlat() . ") / Prime Totale";
        }
        return "Taux non défini (0%)";
    }

    private function getTranchePrime(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $primeTotale = $this->calculationHelper->getCotationMontantPrimePayableParClient($tranche->getCotation());
        return $primeTotale * $taux;
    }

    private function getTrancheMontantHT(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationHT = $this->calculationHelper->getCotationMontantCommissionHt($tranche->getCotation(), -1, false);
        return $cotationHT * $taux;
    }

    private function getTrancheMontantTTC(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTTC = $this->calculationHelper->getCotationMontantCommissionTtc($tranche->getCotation(), -1, false);
        return $cotationTTC * $taux;
    }

    private function getTrancheTaxeCourtierMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->calculationHelper->getCotationMontantTaxeCourtier($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeAssureurMontant(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationTaxe = $this->calculationHelper->getCotationMontantTaxeAssureur($tranche->getCotation(), false);
        return $cotationTaxe * $taux;
    }

    private function getTrancheTaxeCourtierTaux(Tranche $tranche): float
    {
        $entreprise = $tranche->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_COURTIER, 'entreprise' => $entreprise]);
        if (!$taxe) return 0.0;
        $isIARD = $this->calculationHelper->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getTrancheTaxeAssureurTaux(Tranche $tranche): float
    {
        $entreprise = $tranche->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        
        $taxe = $this->taxeRepository->findOneBy(['redevable' => Taxe::REDEVABLE_ASSUREUR, 'entreprise' => $entreprise]);
        if (!$taxe) return 0.0;
        $isIARD = $this->calculationHelper->isIARD($tranche->getCotation());
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
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
        $cotationPure = $this->calculationHelper->getCotationMontantCommissionPure($tranche->getCotation(), -1, false);
        return $cotationPure * $taux;
    }

    private function getTranchePartPartenaire(Tranche $tranche): float
    {
        $partenaire = $this->calculationHelper->getCotationPartenaire($tranche->getCotation());
        return $partenaire ? ($partenaire->getPart() * 100) : 0.0;
    }

    private function getTrancheRetroCommission(Tranche $tranche): float
    {
        $taux = $this->calculateTrancheTauxFactor($tranche);
        $cotationRetro = $this->calculationHelper->getCotationMontantRetrocommissionsPayableParCourtier($tranche->getCotation(), null, -1, []);
        return $cotationRetro * $taux;
    }

    private function getTrancheStatutPaiement(Tranche $tranche): string
    {
        $prime = $this->getTranchePrime($tranche);
        $paye = $this->calculationHelper->getTranchePrimePayee($tranche);

        if ($prime <= 0) return 'N/A';
        if ($paye >= $prime) return 'Payée';
        if ($paye > 0) return 'Partiellement payée';
        return 'Non payée';
    }

    private function getTrancheTauxAvancement(Tranche $tranche): float
    {
        $prime = $this->getTranchePrime($tranche);
        if ($prime <= 0) return 0.0;
        return round(($this->calculationHelper->getTranchePrimePayee($tranche) / $prime) * 100, 2);
    }

    private function getTrancheRetardPaiement(Tranche $tranche): string
    {
        $solde = $this->getTranchePrime($tranche) - $this->calculationHelper->getTranchePrimePayee($tranche);
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
}