<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Partenaire;
use App\Services\CanvasBuilder;

/**
 * Construit le rendu HTML pour l'entité Partenaire dans les champs d'autocomplétion.
 */
class PartenaireAutocompleteCanvasProvider
{
    public function __construct(private CanvasBuilder $canvasBuilder)
    {
    }

    public function getChoiceLabel(Partenaire $partenaire): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($partenaire);

        $nomPartenaire = $partenaire->getNom() ?? 'Partenaire sans nom';
        $email = $partenaire->getEmail() ?? 'N/A';
        $part = ($partenaire->getPart() ?? 0) * 100;

        $commissionPure = $partenaire->montantPur ?? 0.0;
        $retroDue = $partenaire->retroCommission ?? 0.0;
        $retroPayee = $partenaire->retroCommissionReversee ?? 0.0;
        $solde = $partenaire->retroCommissionSolde ?? 0.0;

        return sprintf(
            '<div class="jsb-autocomplete-item" style="background-color: #ffffff;">
                <div class="jsb-autocomplete-title">%s</div>
                <div class="jsb-autocomplete-context">
                    <span>Email: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>Part: <strong>%s%%</strong></span>
                </div>
                <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(4, 1fr);">
                    <div><div><span class="jsb-indicator-label">Com. Pure</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Rétro. Dûe</span><span class="jsb-indicator-value">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Rétro. Payée</span><span class="jsb-indicator-value text-success">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Solde</span><span class="jsb-indicator-value text-danger">%s</span></div></div>
                </div>
            </div>',
            htmlspecialchars($nomPartenaire),
            htmlspecialchars($email),
            number_format($part, 2, ',', ' '),
            number_format($commissionPure, 2, ',', ' '),
            number_format($retroDue, 2, ',', ' '),
            number_format($retroPayee, 2, ',', ' '),
            number_format($solde, 2, ',', ' ')
        );
    }
}