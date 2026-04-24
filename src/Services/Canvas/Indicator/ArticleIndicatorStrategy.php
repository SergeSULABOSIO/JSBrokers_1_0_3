<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Article;
use App\Entity\Note;
use App\Entity\Taxe;
use App\Entity\RevenuPourCourtier;
use App\Entity\Tranche;
use App\Repository\TaxeRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

class ArticleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper,
        private TaxeRepository $taxeRepository
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Article $entity */
        $montantArticle = round($this->calculateMontantArticle($entity) ?? 0, 2);
        $quantite = $entity->getQuantite() ?? 1.0;
        // On s'assure de ne pas diviser par zéro.
        $valeurUnitaire = ($quantite != 0) ? $montantArticle / $quantite : 0.0;

        return [
            'natureArticle' => $this->getNatureArticle($entity),
            'elementLie' => $this->getElementLie($entity),
            'montantArticle' => $montantArticle,
            'pourcentageNote' => round($this->calculatePourcentageNote($entity), 2),
            'statutNoteParent' => $this->getStatutNoteParent($entity),
            // NOUVEAU : Calcul de la description contextuelle
            'description' => $this->getDynamicDescription($entity),
            // NOUVEAU : Calcul de la valeur unitaire
            'valeurUnitaire' => $valeurUnitaire,
        ];
    }

    /**
     * NOUVEAU : Construit une description brève pour l'article en fonction du contexte de la note.
     */
    private function getDynamicDescription(Article $article): string
    {
        $note = $article->getNote();
        if (!$note) {
            return 'Article non lié.';
        }

        $revenu = $article->getRevenuFacture();
        if (!$revenu) {
            return 'Revenu non défini.';
        }

        switch ($note->getAddressedTo()) {
            case Note::TO_CLIENT:
            case Note::TO_ASSUREUR:
                return "Commission de courtage sur " . ($revenu->getNom() ?? 'revenu');
            case Note::TO_PARTENAIRE:
                return "Rétro-commission à " . ($note->getPartenaire()?->getNom() ?? 'partenaire');
            case Note::TO_AUTORITE_FISCALE:
                return "Taxe due à " . ($note->getAutoritefiscale()?->getNom() ?? 'autorité fiscale');
            default:
                return $revenu->getNom() ?? 'Détail de l\'article';
        }
    }

    private function getNatureArticle(Article $article): string
    {
        if ($article->getRevenuFacture() !== null) {
            return 'Commission / Revenu';
        }
        if ($article->getTranche() !== null) {
            return 'Prime / Tranche';
        }
        return 'Article Libre';
    }

    private function getElementLie(Article $article): string
    {
        $revenu = $article->getRevenuFacture();
        $tranche = $article->getTranche();

        // On récupère la cotation depuis le revenu ou la tranche pour trouver la police.
        $cotation = $revenu?->getCotation() ?? $tranche?->getCotation();
        $policeRef = 'Police N/A';
        if ($cotation && !$cotation->getAvenants()->isEmpty()) {
            $avenant = $cotation->getAvenants()->first();
            $policeRef = $avenant->getReferencePolice() ?? 'Police N/A';
        }

        // On s'assure que la tranche est hydratée pour avoir son taux.
        if ($tranche) {
            $this->hydrateTranche($tranche);
        }

        if ($revenu !== null) {
            $nomRevenu = htmlspecialchars($revenu->getNom() ?? 'Revenu sans nom');
            // Si une tranche est également liée, on construit la description détaillée.
            if ($tranche !== null) {
                $nomTranche = htmlspecialchars($tranche->getNom() ?? 'Tranche sans nom');
                $tauxTranche = number_format($tranche->tauxTranche ?? 0.0, 2, ',', ' ');
                $quantite = number_format($article->getQuantite() ?? 1.0, 2, ',', ' ');

                // Format : "Police 123 - Commission Ordinaire (1ère Tranche @ 50,00% x 1,00)"
                return sprintf('%s - %s (%s @%s%% x %s)', $policeRef, $nomRevenu, $nomTranche, $tauxTranche, $quantite);
            }
            // Format : "Police 123 - Commission Ordinaire"
            return sprintf('%s - %s', $policeRef, $nomRevenu);
        }
        if ($tranche !== null) { // Cas où l'on facture une prime directement, sans passer par un revenu.
            // Format : "Police 123 - Prime / 1ère Tranche"
            return sprintf('%s - Prime / %s', $policeRef, htmlspecialchars($tranche->getNom() ?? 'Tranche sans nom'));
        }
        return 'N/A';
    }

    private function calculateMontantArticle(Article $article): float
    {
        $revenu = $article->getRevenuFacture(); // Accès direct à la relation revenuFacture
        $tranche = $article->getTranche();
        $quantity = $article->getQuantite() ?? 1;

        // --- HYDRATATION FORCÉE DU REVENU ---
        // On s'assure que les indicateurs du revenu sont calculés pour l'affichage dans le formulaire
        if ($revenu) {
            $this->hydrateRevenu($revenu);
        }

        // --- HYDRATATION FORCÉE DE LA TRANCHE ---
        // On s'assure que les indicateurs de la tranche sont calculés
        if ($tranche) {
            $this->hydrateTranche($tranche);
        }

        // Utilisation du helper pour le calcul final (garantit la cohérence avec le total de la note)
        return $this->calculationHelper->getArticleMontant($article);
    }

    private function hydrateRevenu(RevenuPourCourtier $revenu): void
    {
        // --- WARM UP (Réchauffement) DES PROXIES ---
        // On accède aux propriétés clés pour forcer Doctrine à charger les objets liés s'ils sont encore en Proxy
        // Cela évite les calculs à 0.00 si le contrôleur n'a pas fait d'Eager Loading.
        $revenu->getTypeRevenu()?->getNom(); 
        $revenu->getCotation()?->getChargements()->count(); // Charge la collection de chargements pour le calcul HT
        $revenu->getCotation()?->getPiste()?->getRisque()?->getId(); // Charge la piste et le risque pour les taxes

        // Calcul des valeurs financières de base
        $montantHT = $this->calculationHelper->getRevenuMontantHt($revenu);
        // CORRECTION : On s'assure que le taux est un facteur (ex: 0.02) avant de multiplier.
        $taxeTaux = $this->getTaxeTaux($revenu, Taxe::REDEVABLE_COURTIER);
        $taxeMontant = $montantHT * $taxeTaux;
        
        // Hydratation des propriétés publiques (utilisées par le champ autocomplete et l'affichage)
        $revenu->montantCalculeHT = $montantHT;
        $revenu->taxeCourtierMontant = $taxeMontant;
        
        // CORRECTION : Utilisation du helper pour garantir que le TTC inclut bien la taxe assureur correcte
        $revenu->montantCalculeTTC = $this->calculationHelper->getRevenuMontantTTC($revenu);
        
        $revenu->montantPur = $montantHT - $taxeMontant;
        $revenu->reserve = $revenu->montantPur - $this->calculationHelper->getRevenuMontantRetrocommissionsPayableParCourtier($revenu, null, -1, []);
    }

    private function hydrateTranche(Tranche $tranche): void
    {
        // --- WARM UP DES PROXIES ---
        $tranche->getCotation()?->getChargements()->count(); // Nécessaire pour le calcul de la prime totale
        $tranche->getCotation()?->getPiste()?->getInvite()?->getEntreprise(); // Nécessaire pour trouver la bonne Taxe

        // CORRECTION : Utilisation du helper pour garantir le bon facteur de taux
        $tranche->tauxTranche = $this->calculationHelper->getTrancheTauxFactor($tranche) * 100;
    }

    private function calculatePourcentageNote(Article $article): float
    {
        $note = $article->getNote();
        if (!$note) {
            return 0.0;
        }

        $montantArticle = $article->montantArticle ?? 0.0; // Utilisation de la propriété calculée
        if ($montantArticle == 0) {
            return 0.0;
        }

        $totalNote = 0.0;
        foreach ($note->getArticles() as $art) {
            $totalNote += $art->montantArticle ?? 0.0; // Utilisation de la propriété calculée
        }

        if ($totalNote == 0) {
            return 0.0;
        }

        return ($montantArticle / $totalNote) * 100;
    }

    private function getStatutNoteParent(Article $article): string
    {
        $note = $article->getNote();
        if (!$note) {
            return 'Orphelin';
        }

        return $note->isValidated() ? 'Validée' : 'Brouillon';
    }

    // --- Méthodes utilitaires simplifiées pour l'hydratation interne ---
    private function getTaxeMontantAssureur(RevenuPourCourtier $revenu): float
    {
        $ht = $this->calculationHelper->getRevenuMontantHt($revenu);
        $taux = $this->getTaxeTaux($revenu, Taxe::REDEVABLE_ASSUREUR);
        return $ht * ($taux / 100);
    }

    private function getTaxeTaux(RevenuPourCourtier $revenu, int $redevable): float
    {
        $isIARD = $this->calculationHelper->isIARD($revenu->getCotation()); // ex: true
        
        $entreprise = $revenu->getTypeRevenu()?->getEntreprise();
        // Fallback
        if (!$entreprise) $entreprise = $revenu->getCotation()?->getPiste()?->getInvite()?->getEntreprise();
        
        $taxe = $this->taxeRepository->findOneBy(['redevable' => $redevable, 'entreprise' => $entreprise]);
        if (!$taxe) return 0.0;
        $rate = $isIARD ? $taxe->getTauxIARD() : $taxe->getTauxVIE();
        // CORRECTION : La BDD stocke le taux en facteur (ex: 0.16). On retourne cette valeur directement.
        return (float)($rate ?? 0.0);
    }
}