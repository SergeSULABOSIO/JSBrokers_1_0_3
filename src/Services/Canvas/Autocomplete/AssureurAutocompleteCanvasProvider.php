<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Assureur;
use App\Services\CanvasBuilder;

/**
 * Construit le rendu HTML pour l'entité Assureur dans les champs d'autocomplétion.
 */
class AssureurAutocompleteCanvasProvider
{
    public function __construct(private CanvasBuilder $canvasBuilder)
    {
    }

    public function getChoiceLabel(Assureur $assureur): string
    {
        // 1. Hydratation de l'entité avec les valeurs calculées
        $this->canvasBuilder->loadAllCalculatedValues($assureur);

        // 2. Extraction des données pour l'affichage
        $nomAssureur = $assureur->getNom() ?? 'Assureur sans nom';
        $email = $assureur->getEmail() ?? 'N/A';
        $telephone = $assureur->getTelephone() ?? 'N/A';

        // Utilisation des propriétés hydratées par AssureurIndicatorStrategy
        $primeTTC = $assureur->primeTotale ?? 0.0;
        $commissionTTC = $assureur->montantTTC ?? 0.0;
        $taxeCourtier = $assureur->taxeCourtierMontant ?? 0.0;
        $taxeAssureur = $assureur->taxeAssureurMontant ?? 0.0;
        $retroCommission = $assureur->retroCommission ?? 0.0;

        // 3. Construction du HTML
        return sprintf(
            '<div class="jsb-autocomplete-item" style="background-color: #ffffff;">
                <div class="jsb-autocomplete-title">%s</div>
                <div class="jsb-autocomplete-context">
                    <span>Email: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Tél: <strong>%s</strong></span>
                </div>
                <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(5, 1fr);">
                    <div><div><span class="jsb-indicator-label">Prime TTC</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Com. TTC</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Taxe Courtier</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Taxe Assureur</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Rétro com.</span><span class="jsb-indicator-value">%s</span></div></div>
                </div>
            </div>',
            htmlspecialchars($nomAssureur),
            htmlspecialchars($email),
            htmlspecialchars($telephone),
            number_format($primeTTC, 2, ',', ' '),
            number_format($commissionTTC, 2, ',', ' '),
            number_format($taxeCourtier, 2, ',', ' '),
            number_format($taxeAssureur, 2, ',', ' '),
            number_format($retroCommission, 2, ',', ' ')
        );
    }
}