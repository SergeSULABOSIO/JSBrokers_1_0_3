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
        $ligne_principale = $entity->getReference() ?? 'N/A';
        $ligne_secondaire = 'Paiement non-associé';
        $montantPaiement = $entity->getMontant() ?? 0.0;

        if ($offre = $entity->getOffreIndemnisationSinistre()) {
            $sinistre = $offre->getNotificationSinistre();
            $ligne_principale .= ' • Indemnisation Sinistre';
            if ($sinistre) {
                $ligne_principale .= ' • Assuré: ' . ($sinistre->getAssure()?->getNom() ?? 'N/A');
                $datePaiement = $entity->getPaidAt() ? $entity->getPaidAt()->format('d/m/Y') : 'N/A';
                $ligne_secondaire = sprintf(
                    "Payé le %s • Sinistre %s",
                    $datePaiement,
                    $sinistre->getReferenceSinistre() ?? 'N/A'
                );
            }
        } elseif ($note = $entity->getNote()) {
            $typeNote = $this->getNoteTypeString($note);
            $destinataire = $this->getNoteAddressedToString($note);
            $ligne_principale .= ' • ' . $typeNote . ' • ' . $destinataire;

            $datePaiement = $entity->getPaidAt() ? $entity->getPaidAt()->format('d/m/Y') : 'N/A';
            $refNote = $note->getReference() ?? 'N/A';
            $nomNote = $note->getNom() ?? $note->getDescription() ?? 'N/A';
            // Nettoyage pour l'affichage sur une seule ligne
            $nomNote = strip_tags(str_replace(["\r", "\n"], ' ', $nomNote));
            $nomNote = mb_substr($nomNote, 0, 50) . (mb_strlen($nomNote) > 50 ? '...' : '');

            $ligne_secondaire = sprintf(
                "Payé le %s • Note %s (%s)",
                $datePaiement,
                $refNote,
                $nomNote
            );
        }

        return [
            'ligne_principale' => $ligne_principale,
            'ligne_secondaire' => $ligne_secondaire,
            'montantPaiement' => $entity->getMontant() ?? 0.0,
        ];
    }

    // --- Méthodes privées déplacées depuis CalculationProvider ---

    private function getNoteTypeString(?Note $note): string
    {
        if ($note === null) return 'N/A';

        return match ($note->getType()) {
            Note::TYPE_NOTE_DE_DEBIT => 'Note de débit',
            Note::TYPE_NOTE_DE_CREDIT => 'Note de crédit',
            default => 'Inconnu',
        };
    }

    private function getNoteAddressedToString(?Note $note): string
    {
        if ($note === null) return 'N/A';

        return match ($note->getAddressedTo()) {
            Note::TO_CLIENT => 'Client: ' . ($note->getClient()?->getNom() ?? 'N/A'),
            Note::TO_ASSUREUR => 'Assureur: ' . ($note->getAssureur()?->getNom() ?? 'N/A'),
            Note::TO_PARTENAIRE => 'Intermédiaire: ' . ($note->getPartenaire()?->getNom() ?? 'N/A'),
            Note::TO_AUTORITE_FISCALE => 'Autorité: ' . ($note->getAutoritefiscale()?->getAbreviation() ?? $note->getAutoritefiscale()?->getNom() ?? 'N/A'),
            default => 'Inconnu',
        };
    }

    private function getCotationReferencePolice(?Cotation $cotation): string
    {
        if (!$cotation || $cotation->getAvenants()->isEmpty()) {
            return 'Nulle';
        }
        return $cotation->getAvenants()->first()->getReferencePolice() ?? 'Nulle';
    }
}