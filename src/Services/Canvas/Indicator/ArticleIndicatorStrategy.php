<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Article;
use Symfony\Contracts\Translation\TranslatorInterface;

class ArticleIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Article::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Article $entity */
        return [
            'natureArticle' => $this->getNatureArticle($entity),
            'elementLie' => $this->getElementLie($entity),
            'montantArticle' => round($this->calculateMontantArticle($entity) ?? 0, 2),
            'pourcentageNote' => round($this->calculatePourcentageNote($entity), 2),
            'statutNoteParent' => $this->getStatutNoteParent($entity),
        ];
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
        if ($article->getRevenuFacture() !== null) {
            return $article->getRevenuFacture()->getNom() ?? 'Revenu sans nom';
        }
        if ($article->getTranche() !== null) {
            return $article->getTranche()->getNom() ?? 'Tranche sans nom';
        }
        return 'N/A';
    }

    private function calculateMontantArticle(Article $article): float
    {
        $revenu = $article->getNote()?->getRevenuFacture();
        $quantity = $article->getQuantite() ?? 1;
        $tranche = $article->getTranche();
        return ($revenu && $tranche) ? (($revenu->montantCalculeTTC ?? 0) * $quantity * (($tranche->tauxTranche ?? 0)/100)) : 0;
    }

    private function calculatePourcentageNote(Article $article): float
    {
        $note = $article->getNote();
        if (!$note) {
            return 0.0;
        }

        $montantArticle = $article->getMontant() ?? 0.0;
        if ($montantArticle == 0) {
            return 0.0;
        }

        $totalNote = 0.0;
        foreach ($note->getArticles() as $art) {
            $totalNote += $art->getMontant() ?? 0.0;
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
}