<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\CompteBancaire;
use App\Entity\Note;

class CompteBancaireIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function supports(string $entityClassName): bool
    {
        return $entityClassName === CompteBancaire::class;
    }

    public function calculate(object $entity): array
    {
        /** @var CompteBancaire $entity */
        $stats = $this->calculateCompteBancaireStats($entity);
        return [
            'soldeActuel' => round($stats['solde'], 2),
            'totalEntrees' => round($stats['entrees'], 2),
            'totalSorties' => round($stats['sorties'], 2),
            'nombreTransactions' => $stats['count'],
            'moyenneTransaction' => round($stats['average'], 2),
            'dateDerniereTransaction' => $stats['lastDate'],
        ];
    }

    private function calculateCompteBancaireStats(CompteBancaire $compte): array
    {
        $entrees = 0.0;
        $sorties = 0.0;
        $count = 0;
        $lastDate = null;

        foreach ($compte->getPaiements() as $paiement) {
            $montant = $paiement->getMontant() ?? 0.0;
            $isEntree = false;

            if ($paiement->getOffreIndemnisationSinistre()) {
                $isEntree = false;
            } elseif ($note = $paiement->getNote()) {
                $isEntree = ($note->getType() === Note::TYPE_NOTE_DE_DEBIT);
            }

            if ($isEntree) {
                $entrees += $montant;
            } else {
                $sorties += $montant;
            }

            $count++;
            if ($paiement->getPaidAt() && (!$lastDate || $paiement->getPaidAt() > $lastDate)) {
                $lastDate = $paiement->getPaidAt();
            }
        }

        $solde = $entrees - $sorties;
        $average = $count > 0 ? ($entrees + $sorties) / $count : 0.0;

        return [
            'solde' => $solde,
            'entrees' => $entrees,
            'sorties' => $sorties,
            'count' => $count,
            'average' => $average,
            'lastDate' => $lastDate,
        ];
    }
}