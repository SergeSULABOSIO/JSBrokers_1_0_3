<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Paiement;
use App\Entity\Cotation;
use App\Entity\Note;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class PaiementIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Paiement $entity */
        $type = 'N/A';
        $refPolice = 'N/A';
        $clientNom = 'N/A';
        $contexte = 'N/A';
        
        if ($offre = $entity->getOffreIndemnisationSinistre()) {
            $type = 'Indemnisation Sinistre';
            $sinistre = $offre->getNotificationSinistre();
            if ($sinistre) {
                $refPolice = $sinistre->getReferencePolice() ?? 'N/A';
                $clientNom = $sinistre->getAssure()?->getNom() ?? 'N/A';
                $contexte = "Sinistre " . $sinistre->getReferenceSinistre();
            }
        } elseif ($note = $entity->getNote()) {
            $type = $note->getType() === Note::TYPE_NOTE_DE_DEBIT ? 'Encaissement Note' : 'Décaissement Note';
            $contexte = "Note " . $note->getReference();
            
            $articles = $note->getArticles();
            if (!$articles->isEmpty()) {
                $firstArticle = $articles->first();
                if ($tranche = $firstArticle->getTranche()) {
                     $cotation = $tranche->getCotation();
                     $refPolice = $this->getCotationReferencePolice($cotation);
                     $clientNom = $cotation->getPiste()?->getClient()?->getNom() ?? 'N/A';
                } elseif ($revenu = $firstArticle->getRevenuFacture()) {
                     $cotation = $revenu->getCotation();
                     $refPolice = $this->getCotationReferencePolice($cotation);
                     $clientNom = $cotation->getPiste()?->getClient()?->getNom() ?? 'N/A';
                }
            }
        }

        return [
            'nomCompletAvecStatut' => $entity->getReference() . ' (' . $type . ') #' . $refPolice,
            'typePaiement' => $type,
            'referencePolice' => $refPolice,
            'clientNom' => $clientNom,
            'contexte' => $contexte,
            'montantPaiement' => $entity->getMontant() ?? 0.0,
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getCotationReferencePolice(?Cotation $cotation): string
    {
        if (!$cotation || $cotation->getAvenants()->isEmpty()) {
            return 'Nulle';
        }
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }
}