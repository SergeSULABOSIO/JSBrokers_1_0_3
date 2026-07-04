<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Bordereau;
use App\Services\CanvasBuilder;
use App\Services\ServiceMonnaies;

/**
 * Construit le rendu HTML pour l'entité Bordereau dans les champs d'autocomplétion.
 */
class BordereauAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private ServiceMonnaies $serviceMonnaies
    ) {
    }

    public function getChoiceLabel(Bordereau $bordereau): string
    {
        // 1. Hydratation de l'entité avec les valeurs calculées (solde, totaux, etc.)
        $this->canvasBuilder->loadAllCalculatedValues($bordereau);

        // 2. Extraction des données pour l'affichage
        $nomBordereau = $bordereau->getNom() ?? 'Bordereau sans nom';
        $reference = $bordereau->getReference() ?? 'N/A';
        $assureurNom = $bordereau->getAssureur()?->getNom() ?? 'N/A';
        $periodeDebut = $bordereau->getPeriodeDebut()?->format('d/m/Y') ?? 'N/A';
        $periodeFin = $bordereau->getPeriodeFin()?->format('d/m/Y') ?? 'N/A';

        // Utilisation des propriétés hydratées par le CanvasBuilder
        $montantCommissionTTC = $bordereau->montantCommissionTTC ?? 0.0;
        $montantEncaisse = $bordereau->montantEncaisse ?? 0.0;
        $solde = $bordereau->solde ?? 0.0;
        $statut = $bordereau->getStatut() ?? Bordereau::STATUT_A_VERIFIER; // Default to a known status

        // Déterminer la classe CSS pour le solde
        $soldeClass = 'text-success';
        if ($solde > 0.01) {
            $soldeClass = 'text-danger';
        } elseif ($solde < -0.01) {
            $soldeClass = 'text-warning'; // Overpaid or credit
        }

        // Déterminer le texte du statut
        $statutText = match ($statut) {
            Bordereau::STATUT_A_VERIFIER => 'À vérifier',
            Bordereau::STATUT_CONTESTE => 'Contesté',
            Bordereau::STATUT_VALIDE => 'Validé',
            Bordereau::STATUT_PAYE => 'Payé',
            Bordereau::STATUT_PARTIELLEMENT_PAYE => 'Partiellement Payé',
            Bordereau::STATUT_ANNULE => 'Annulé',
            default => 'Inconnu',
        };

        // 3. Construction du HTML
        return sprintf(
            '<div class="jsb-autocomplete-item">
                <div class="jsb-autocomplete-title">%s <span class="jsb-autocomplete-title-suffix">(Réf: %s)</span></div>
                <div class="jsb-autocomplete-context">
                    <span>Assureur: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Période: <strong>%s - %s</strong></span>
                </div>
                <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(3, 1fr);">
                    <div><div><span class="jsb-indicator-label">Montant TTC</span><span class="jsb-indicator-value">%s %s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Encaissé</span><span class="jsb-indicator-value">%s %s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value %s">%s %s</span></div></div>
                </div>
                <div class="jsb-autocomplete-context mt-2">
                    <span>Statut: <strong>%s</strong></span>
                </div>
            </div>',
            htmlspecialchars($nomBordereau),
            htmlspecialchars($reference),
            htmlspecialchars($assureurNom),
            htmlspecialchars($periodeDebut),
            htmlspecialchars($periodeFin),
            number_format($montantCommissionTTC, 2, ',', ' '),
            htmlspecialchars($this->serviceMonnaies->getCodeMonnaieAffichage()),
            number_format($montantEncaisse, 2, ',', ' '),
            htmlspecialchars($this->serviceMonnaies->getCodeMonnaieAffichage()),
            $soldeClass,
            number_format($solde, 2, ',', ' '),
            htmlspecialchars($this->serviceMonnaies->getCodeMonnaieAffichage()),
            htmlspecialchars($statutText)
        );
    }
}