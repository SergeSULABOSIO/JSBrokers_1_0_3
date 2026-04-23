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
        $montantHT = $this->calculationHelper->getRevenuMontantHt($entity);
        $taxeCourtier = $this->calculationHelper->getRevenuMontantTaxeCourtier($entity);

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
            'montantCalculeTTC' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'descriptionCalcul' => $this->getRevenuPourCourtierDescriptionCalcul($entity),
            'montant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity), 2),
            'montant_paye' => round($this->getRevenuPourCourtierMontantPaye($entity), 2),
            'solde_restant_du' => round($this->calculationHelper->getRevenuMontantTTC($entity) - $this->getRevenuPourCourtierMontantPaye($entity), 2),            
            'montantPur' => round($this->calculationHelper->getRevenuMontantPure($entity), 2),
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
            'montantCalculeHT' => $montantHT,
            'partPartenaire' => round($this->getRevenuPartPartenaire($entity) * 100, 2), // CORRECTION: On multiplie par 100 pour l'affichage
            'montantRetrocommission' => $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($entity, null, -1, []),
            'reserve' => $this->getReserveCourtier($entity),
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

    private function getReserveCourtier(RevenuPourCourtier $revenu): float
    {
        $montantPur = $this->calculationHelper->getRevenuMontantPure($revenu);
        $retrocommission = $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1, []);
        return $montantPur - $retrocommission;
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

    /**
     * Calcule le taux de rétrocommission (part du partenaire) pour un revenu donné.
     * Cette méthode est publique pour être réutilisable par le Helper.
     *
     * @param RevenuPourCourtier $revenu
     * @return float Le taux de partage sous forme de facteur (ex: 0.35).
     */
    public function getRevenuPartPartenaire(RevenuPourCourtier $revenu): float
    {
        // Si le revenu n'est pas partageable, le taux est 0.
        if (!$revenu->getTypeRevenu() || !$revenu->getTypeRevenu()->isShared()) {
            return 0.0;
        }

        $cotation = $revenu->getCotation();
        if (!$cotation || !$cotation->getPiste()) {
            return 0.0;
        }

        $piste = $cotation->getPiste();
        $partenaire = $this->calculationHelper->getCotationPartenaire($cotation);

        // S'il n'y a pas de partenaire associé à l'affaire, pas de partage.
        if (!$partenaire) {
            return 0.0;
        }

        // On vérifie d'abord s'il y a des conditions de partage exceptionnelles sur la piste.
        if (!$piste->getConditionsPartageExceptionnelles()->isEmpty()) {
            foreach ($piste->getConditionsPartageExceptionnelles() as $condition) {
                // On vérifie si la condition s'applique à ce risque.
                $critereRisque = $condition->getCritereRisque();
                $produitsCibles = $condition->getProduits();
                $risqueActuel = $piste->getRisque();

                $isApplicable = false;
                if ($critereRisque === $condition::CRITERE_PAS_RISQUES_CIBLES) {
                    $isApplicable = true;
                } elseif ($critereRisque === $condition::CRITERE_INCLURE_TOUS_CES_RISQUES && $produitsCibles->contains($risqueActuel)) {
                    $isApplicable = true;
                } elseif ($critereRisque === $condition::CRITERE_EXCLURE_TOUS_CES_RISQUES && !$produitsCibles->contains($risqueActuel)) {
                    $isApplicable = true;
                }

                if ($isApplicable) {
                    // La première condition applicable trouvée détermine le taux.
                    return $condition->getTaux() ?? 0.0;
                }
            }
        }

        // S'il n'y a pas de condition exceptionnelle applicable, on utilise le taux par défaut du partenaire.
        return $partenaire->getPart() ?? 0.0;
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