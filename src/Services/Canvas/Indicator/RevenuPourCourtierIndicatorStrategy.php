<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\RevenuPourCourtier;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Repository\TaxeRepository;
use Doctrine\ORM\EntityManagerInterface;

class RevenuPourCourtierIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private IndicatorCalculationHelper $calculationHelper,
        private TaxeRepository $taxeRepository,
        private EntityManagerInterface $em
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === RevenuPourCourtier::class;
    }

    public function calculate(object $entity): array
    {
        /** @var RevenuPourCourtier $entity */

        // On s'assure que l'entité et sa cotation sont chargées (Proxies Doctrine)
        $this->em->initializeObject($entity);
        if ($entity->getCotation()) {
            $this->em->initializeObject($entity->getCotation());
        }

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
            'montantCalculeTTC' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
            'montant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
            'solde_restant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity) - $this->getRevenuPourCourtierMontantPaye($entity), 2),            
            'montantPur' => round($this->calculationHelper->getRevenuMontantPure($entity), 2),
            'partPartenaire' => $this->getRevenuPartPartenaire($entity),
            'retroCommission' => round($this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),            
            'reserve' => round($this->calculationHelper->getRevenuMontantPure($entity) - $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []), 2),
            'retroCommissionReversee' => round($this->getRevenuRetroCommissionReversee($entity), 2),
            'retroCommissionSolde' => round($this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []) - $this->getRevenuRetroCommissionReversee($entity), 2),
            'taxeCourtierMontant' => round($this->calculationHelper->getRevenuMontantTaxeCourtier($entity), 2),
            'taxeCourtierTaux' => $this->getTaxeTaux($entity, Taxe::REDEVABLE_COURTIER),
            'taxeAssureurMontant' => round($this->calculationHelper->getRevenuMontantTaxeAssureur($entity), 2),
            'taxeAssureurTaux' => $this->getTaxeTaux($entity, Taxe::REDEVABLE_ASSUREUR),
            'estPartageable' => ($entity->getTypeRevenu() && $entity->getTypeRevenu()->isShared()) ? 'Oui' : 'Non',
            'taxeCourtierPayee' => round($this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_COURTIER), 2),
            'taxeCourtierSolde' => round($this->calculationHelper->getRevenuMontantTaxeCourtier($entity) - $this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_COURTIER), 2),
            'taxeAssureurPayee' => round($this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_ASSUREUR), 2),
            'taxeAssureurSolde' => round($this->calculationHelper->getRevenuMontantTaxeAssureur($entity) - $this->getRevenuTaxePayee($entity, Taxe::REDEVABLE_ASSUREUR), 2),
        ];
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
                    
                    // Utilisation centralisée
                    $montantArticle = $this->calculationHelper->getArticleMontant($article);
                    $montantPaye += $proportionPaiement * $montantArticle;
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

    public function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        $partenaireAffaire = $this->calculationHelper->getCotationPartenaire($revenu->getCotation());
        if (!$partenaireAffaire) return 0.0;

        $conditionsPartagePiste = $revenu->getCotation()?->getPiste()?->getConditionsPartageExceptionnelles();
        if (!$conditionsPartagePiste->isEmpty()) {
            // CORRECTION : On retourne le taux brut (ex: 0.15) et non un pourcentage.
            return $conditionsPartagePiste->first()->getTaux() ?? 0.0;
        }

        $conditionsPartagePartenaire = $partenaireAffaire->getConditionPartages();
        if (!$conditionsPartagePartenaire->isEmpty()) {
            // CORRECTION : On retourne le taux brut (ex: 0.15) et non un pourcentage.
            return $conditionsPartagePartenaire->first()->getTaux() ?? 0.0;
        }

        // CORRECTION : On retourne le taux brut (ex: 0.15) et non un pourcentage.
        return $partenaireAffaire->getPart() ?? 0.0;
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
                    
                    // Calcul robuste
                    $montantArticle = $this->calculationHelper->getArticleMontant($article);
                    $montantPaye += $proportionPaiement * $montantArticle;
                }
            }
        }
        return $montantPaye;
    }

    private function getTaxeTaux(RevenuPourCourtier $revenu, int $redevable): float
    {
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation()); // ex: true
        
        $entreprise = $revenu->getTypeRevenu()?->getEntreprise();
        // Fallback si le type de revenu n'a pas d'entreprise (ex: création dynamique)
        if (!$entreprise) $entreprise = $revenu->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        $taxe = $this->taxeRepository->findOneBy(['redevable' => $redevable, 'entreprise' => $entreprise]);
        if (!$taxe) return 0.0;
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        return (float)($rate ?? 0.0);
    }

    private function getRevenuTaxePayee(RevenuPourCourtier $revenu, int $targetRedevable): float
    {
        $montantPaye = 0.0;

        foreach ($revenu->getArticles() as $article) {
            $note = $article->getNote();
            if ($note && $note->getAddressedTo() === Note::TO_AUTORITE_FISCALE) {
                $taxe = $note->getAutoritefiscale()?->getTaxe();
                if ($taxe && $taxe->getRedevable() === $targetRedevable) {
                    $montantPayableNote = $this->calculationHelper->getNoteMontantPayable($note);
                    if ($montantPayableNote > 0) {
                        $proportionPaiement = $this->calculationHelper->getNoteMontantPaye($note) / $montantPayableNote;
                        
                        $montantArticle = $this->calculationHelper->getArticleMontant($article);

                        $montantPaye += $proportionPaiement * $montantArticle;
                    }
                }
            }
        }
        return $montantPaye;
    }
}