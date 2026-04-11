<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Client;
use App\Services\CanvasBuilder;

/**
 * Construit le rendu HTML pour l'entité Client dans les champs d'autocomplétion.
 */
class ClientAutocompleteCanvasProvider
{
    public function __construct(private CanvasBuilder $canvasBuilder)
    {
    }

    public function getChoiceLabel(Client $client): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($client);

        $nomClient = $client->getNom() ?? 'Client sans nom';
        $email = $client->getEmail() ?? 'N/A';
        $telephone = $client->getTelephone() ?? 'N/A';

        $primeTTC = $client->primeTotale ?? 0.0;
        $commissionTTC = $client->montantTTC ?? 0.0;
        $taxeCourtier = $client->taxeCourtierMontant ?? 0.0;
        $taxeAssureur = $client->taxeAssureurMontant ?? 0.0;
        $retroCommission = $client->retroCommission ?? 0.0;

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
            htmlspecialchars($nomClient),
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