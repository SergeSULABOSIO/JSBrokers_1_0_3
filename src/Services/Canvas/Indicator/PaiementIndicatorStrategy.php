<?php

namespace App\Services\Canvas\Indicator;

use App\Entity\Paiement;
use App\Services\ServiceDates;
use Symfony\Contracts\Translation\TranslatorInterface;

class PaiementIndicatorStrategy implements IndicatorCalculationStrategyInterface
{
    public function __construct(
        // NOUVEAU : Injection des services nécessaires
        private ServiceDates $serviceDates,
        private TranslatorInterface $translator,
        private IndicatorCalculationHelper $calculationHelper
    ) {
    }

    public function supports(string $entityClassName): bool
    {
        return $entityClassName === Paiement::class;
    }

    public function calculate(object $entity): array
    {
        /** @var Paiement $entity */
        return [
            'typePaiement' => $this->calculationHelper->getPaiementTypePaiement($entity),
            'contexte' => $this->calculationHelper->getPaiementContexte($entity),
            'referencePolice' => $this->calculationHelper->getPaiementReferencePolice($entity),
            'clientNom' => $this->calculationHelper->getPaiementClientNom($entity),
            'montantPaiement' => $this->calculationHelper->getPaiementMontantPaiement($entity),
            'soldeRestantDu' => $this->getSoldeRestantDu($entity),
            'ligne_principale' => $this->getLignePrincipale($entity),
            'ligne_secondaire' => $this->getLigneSecondaire($entity),
        ];
    }

    private function getSoldeRestantDu(Paiement $paiement): ?float
    {
        $note = $paiement->getNote();
        if (!$note) return null;
        $total = $this->calculationHelper->getNoteMontantTotal($note);
        $paye  = $this->calculationHelper->getNoteMontantPaye($note);
        return round($total - $paye, 2);
    }

    // NOUVEAU : Méthode pour générer la ligne principale
    private function getLignePrincipale(Paiement $paiement): string
    {
        if ($note = $paiement->getNote()) {
            // On récupère le nom court du destinataire (ex: 'ARCA' pour l'autorité fiscale).
            $destinataire = $this->calculationHelper->getNoteAddressedToString($note);

            // On adapte le préfixe en fonction du type de note.
            if ($note->getType() === \App\Entity\Note::TYPE_NOTE_DE_DEBIT) {
                return sprintf("Paiement de %s", $destinataire ?? 'N/A');
            } elseif ($note->getType() === \App\Entity\Note::TYPE_NOTE_DE_CREDIT) {
                return sprintf("Paiement vers %s", $destinataire ?? 'N/A');
            }
        }

        // Fallback : Si le paiement n'est pas lié à une note (ex: indemnisation sinistre),
        // on garde l'ancien comportement qui affiche le nom du client de la police.
        $clientNom = $this->calculationHelper->getPaiementClientNom($paiement);
        return sprintf("Paiement de %s", $clientNom ?? 'N/A');
    }

    // NOUVEAU : Méthode pour générer la ligne secondaire
    private function getLigneSecondaire(Paiement $paiement): string
    {
        $reference = $paiement->getReference() ?? 'N/A';
        $secondaryText = sprintf("Réf. %s", $reference);

        if ($note = $paiement->getNote()) {
            $nomNote = $note->getNom() ?? 'Note sans nom';
            $secondaryText .= sprintf(" * %s", $nomNote);
        }

        return $secondaryText;
    }
}