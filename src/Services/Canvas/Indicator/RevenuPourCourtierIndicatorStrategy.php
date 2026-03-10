<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RevenuPourCourtier;
use App\Entity\Note;
use App\Entity\Paiement;
use App\Entity\Taxe;
use App\Repository\TaxeRepository;

class RevenuPourCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper,
        private TaxeRepository $taxeRepository
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
        $refPolice = $cotation ? $this->calculationHelper->getCotationReferencePolice($cotation) : 'N/A';
        $nomComplet = sprintf("%s sur Police #%s", $entity->getNom(), $refPolice);

        return [
            'nomCompletAvecStatut' => $nomComplet,
            'referencePolice' => $refPolice,
            'clientNom' => $clientNom,
            'typeRevenuNom' => $entity->getTypeRevenu()?->getNom() ?? 'N/A',
            'clientDescription' => $this->calculationHelper->getClientDescriptionFromCotation($entity->getCotation()),
            'risqueDescription' => $this->calculationHelper->getRisqueDescriptionFromCotation($entity->getCotation()),
            'montantCalculeHT' => round($this->calculationHelper->getRevenuMontantHt($entity), 2),
            'montantCalculeTTC' => round($this->getRevenuPourCourtierMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
            'montant_du' => round($this->getRevenuPourCourtierMontantTTC($entity), 2),
            'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
            'solde_restant_du' => round($this->getRevenuPourCourtierMontantTTC($entity) - $this->getRevenuPourCourtierMontantPaye($entity), 2),
            'montantPur' => round($this->getRevenuMontantPur($entity), 2),
            'partPartenaire' => $this->getRevenuPartPartenaire($entity),
            'retroCommission' => round($this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
            'reserve' => round($this->getRevenuMontantPur($entity) - $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
            'retroCommissionReversee' => round($this->getRevenuRetroCommissionReversee($entity), 2),
            'retroCommissionSolde' => round($this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []) - $this->getRevenuRetroCommissionReversee($entity), 2),
            'taxeCourtierMontant' => round($this->getRevenuTaxeMontant($entity, false), 2),
            'taxeCourtierTaux' => $this->getRevenuTaxeTaux($entity, Taxe::REDEVABLE_COURTIER),
            'taxeAssureurMontant' => round($this->getRevenuTaxeMontant($entity, true), 2),
            'taxeAssureurTaux' => $this->getRevenuTaxeTaux($entity, Taxe::REDEVABLE_ASSUREUR),
            'estPartageable' => ($entity->getTypeRevenu() && $entity->getTypeRevenu()->isShared()) ? 'Oui' : 'Non',
            'taxeCourtierPayee' => round($this->getRevenuTaxePayee($entity, false), 2),
            'taxeCourtierSolde' => round($this->getRevenuTaxeMontant($entity, false) - $this->getRevenuTaxePayee($entity, false), 2),
            'taxeAssureurPayee' => round($this->getRevenuTaxePayee($entity, true), 2),
            'taxeAssureurSolde' => round($this->getRevenuTaxeMontant($entity, true) - $this->getRevenuTaxePayee($entity, true), 2),
        ];
    }

    private function getRevenuPourCourtierMontantTTC(RevenuPourCourtier $revenu): float
    {
        $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
        if ($montantHT === 0.0) return 0.0;
        
        // Simulating getMontantTaxe for TTC
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation());
        $taxe = $this->getRevenuTaxeMontant($revenu, true); // Taxe Assureur applies to TTC pricing
        return $montantHT + $taxe;
    }

    private function getRevenuPourCourtierMontantPaye(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note) {
                $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuPourCourtierDescriptionCalcul(RevenuPourCourtier $revenu): string
    {
        $typeRevenu = $revenu->getTypeRevenu();
        if (!$typeRevenu) return "Type de revenu non défini";

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
        $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
        $taxeCourtier = $this->getRevenuTaxeMontant($revenu, false);
        return $montantHT - $taxeCourtier;
    }

    private function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        $partenaireAffaire = $this->calculationHelper->getCotationPartenaire($revenu->getCotation());
        if (!$partenaireAffaire) return 0.0;

        $cotation = $revenu->getCotation();
        $conditionsPartagePiste = $cotation->getPiste()->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            return ($conditionsPartagePiste->first()->getTaux() ?? 0.0) * 100;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            return ($conditionsPartagePartenaire->first()->getTaux() ?? 0.0) * 100;
        }

        return ($partenaireAffaire->getPart() ?? 0.0) * 100;
    }

    private function getRevenuRetroCommissionReversee(RevenuPourCourtier $revenu): float
    {
        $montantPaye = 0.0;
        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_PARTENAIRE) {
                $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                if ($montantPayableNote > 0) {
                    $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                    $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                }
            }
        }
        return $montantPaye;
    }

    private function getRevenuTaxeMontant(RevenuPourCourtier $revenu, bool $isTaxeAssureur): float
    {
        $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
        $taux = $this->getRevenuTaxeTaux($revenu, $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER) / 100;
        return $montantHT * $taux;
    }

    private function getRevenuTaxeTaux(RevenuPourCourtier $revenu, string $redevable): float
    {
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation());
        $taxe = $this->taxeRepository->findOneBy(['redevable' => $redevable]);
        if (!$taxe) return 0.0;
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return ($rate ?? 0.0) * 100;
    }

    private function getRevenuTaxePayee(RevenuPourCourtier $revenu, bool $isTaxeAssureur): float
    {
        $montantPaye = 0.0;
        $targetRedevable = $isTaxeAssureur ? Taxe::REDEVABLE_ASSUREUR : Taxe::REDEVABLE_COURTIER;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $this->taxeRepository->find($article->getIdPoste());
                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                        $montantPaye += $proportionPaiement * ($article->getMontant() ?? 0);
                    }
                }
            }
        }
        return $montantPaye;
    }
}