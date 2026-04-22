<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\AutoriteFiscale;
use App\Services\CanvasBuilder;

/**
 * Construit le rendu HTML pour l'entité AutoriteFiscale dans les champs d'autocomplétion.
 */
class AutoriteFiscaleAutocompleteCanvasProvider
{
    public function __construct(private CanvasBuilder $canvasBuilder)
    {
    }

    public function getChoiceLabel(AutoriteFiscale $autorite): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($autorite);

        $nomAutorite = $autorite->getNom() ?? 'Autorité sans nom';
        $abreviation = $autorite->getAbreviation() ?? 'N/A';
        $taxe = $autorite->getTaxe();
        $taxeCode = $taxe?->getCode() ?? 'N/A';
        $tauxIARD = $taxe ? ($taxe->getTauxIARD() ?? 0.0) : 0.0;
        $tauxVIE = $taxe ? ($taxe->getTauxVIE() ?? 0.0) : 0.0;

        $taxeDue = $autorite->taxeDue ?? 0.0;
        $taxePayee = $autorite->taxePayee ?? 0.0;
        $solde = $autorite->taxeSolde ?? 0.0;

        return sprintf(
            '<div class="jsb-autocomplete-item" style="background-color: #ffffff;">
                <div class="jsb-autocomplete-title">%s (%s)</div>
                <div class="jsb-autocomplete-context">
                    <span>Taxe associée: <strong>%s (%s%% IARD / %s%% VIE)</strong></span>
                </div>
                <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(3, 1fr);">
                    <div><div><span class="jsb-indicator-label">Taxe Dûe</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Taxe Payée</span><span class="jsb-indicator-value text-success">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value text-danger">%s</span></div></div>
                </div>
            </div>',
            htmlspecialchars($nomAutorite),
            htmlspecialchars($abreviation),
            htmlspecialchars($taxeCode),
            number_format($tauxIARD, 2, ',', ' '),
            number_format($tauxVIE, 2, ',', ' '),
            number_format($taxeDue, 2, ',', ' '),
            number_format($taxePayee, 2, ',', ' '),
            number_format($solde, 2, ',', ' ')
        );
    }
}