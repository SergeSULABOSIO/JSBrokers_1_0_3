<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Note;
use App\Entity\Paiement;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class NoteIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Note::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Note $entity */
        return [
            'typeString' => $this->getNoteTypeString($entity),
            'addressedToString' => $this->getNoteAddressedToString($entity),
            'montantTotal' => round($this->getNoteMontantPayable($entity), 2),
            'montantPaye' => round($this->getNoteMontantPaye($entity), 2),
            'solde' => round($this->getNoteSolde($entity), 2),
            'statutPaiement' => $this->getNoteStatutPaiementString($entity),
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getNoteTypeString(?Note $note): ?string
    {
        if ($note === null) return null;

        return match ($note->getType()) {
            Note::TYPE_NOTE_DE_DEBIT => 'Note de débit',
            Note::TYPE_NOTE_DE_CREDIT => 'Note de crédit',
            default => 'Inconnu',
        };
    }

    private function getNoteAddressedToString(?Note $note): ?string
    {
        if ($note === null) return null;

        return match ($note->getAddressedTo()) {
            Note::TO_CLIENT => 'Client',
            Note::TO_ASSUREUR => 'Assureur',
            Note::TO_PARTENAIRE => 'Intermédiaire',
            Note::TO_AUTORITE_FISCALE => 'Autorité Fiscale',
            default => 'Inconnu',
        };
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

    private function getNoteSolde(Note $note): float
    {
        return $this->getNoteMontantPayable($note) - $this->getNoteMontantPaye($note);
    }

    private function getNoteStatutPaiementString(?Note $note): ?string
    {
        if ($note === null) return null;

        $montantDu = $this->getNoteMontantPayable($note);
        $montantPaye = $this->getNoteMontantPaye($note);

        if ($montantDu == 0 && $montantPaye == 0) {
            return 'N/A';
        }
        if ($montantPaye >= $montantDu) {
            return 'Payée';
        }
        if ($montantPaye > 0 && $montantPaye < $montantDu) {
            return 'Partiel';
        }
        return 'Impayée';
    }
}