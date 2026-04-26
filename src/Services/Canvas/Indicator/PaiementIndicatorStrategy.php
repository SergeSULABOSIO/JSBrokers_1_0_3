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
            'typePaiement' => $this->calculationHelper->getPaiementTypePaiement($entity), // Déjà existant
            'contexte' => $this->calculationHelper->getPaiementContexte($entity), // Déjà existant
            'referencePolice' => $this->calculationHelper->getPaiementReferencePolice($entity), // Déjà existant
            'clientNom' => $this->calculationHelper->getPaiementClientNom($entity), // Déjà existant
            'montantPaiement' => $this->calculationHelper->getPaiementMontantPaiement($entity), // Déjà existant
            // NOUVEAU : Logique pour les lignes d'affichage
            'ligne_principale' => $this->getLignePrincipale($entity),
            'ligne_secondaire' => $this->getLigneSecondaire($entity),
        ];
    }

    // NOUVEAU : Méthode pour générer la ligne principale
    private function getLignePrincipale(Paiement $paiement): string
    {
        if ($note = $paiement->getNote()) {
            // On récupère le nom court du destinataire (ex: 'ARCA' pour l'autorité fiscale).
            $destinataire = $this->calculationHelper->getNoteAddressedToString($note);
            $nomNote = $note->getNom();

            // On adapte le préfixe en fonction du type de note.
            if ($note->getType() === \App\Entity\Note::TYPE_NOTE_DE_DEBIT) {
                return sprintf("Paiement de %s - %s", $destinataire ?? 'N/A', $nomNote ?? 'N/A');
            } elseif ($note->getType() === \App\Entity\Note::TYPE_NOTE_DE_CREDIT) {
                return sprintf("Paiement vers %s - %s", $destinataire ?? 'N/A', $nomNote ?? 'N/A');
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
        return sprintf("Réf. %s", $paiement->getReference() ?? 'N/A');
    }
}