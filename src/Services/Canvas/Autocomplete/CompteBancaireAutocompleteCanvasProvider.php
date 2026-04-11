<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\CompteBancaire;
use App\Services\CanvasBuilder;

/**
 * Construit le rendu HTML pour l'entité CompteBancaire dans les champs d'autocomplétion.
 */
class CompteBancaireAutocompleteCanvasProvider
{
    public function __construct(private CanvasBuilder $canvasBuilder)
    {
    }

    public function getChoiceLabel(CompteBancaire $compte): string
    {
        // 1. Hydratation de l'entité avec les valeurs calculées (solde, totaux, etc.)
        $this->canvasBuilder->loadAllCalculatedValues($compte);

        // 2. Extraction des données pour l'affichage
        $nomCompte = $compte->getNom() ?? 'Compte sans nom';
        $nomEntreprise = $compte->getEntreprise()?->getNom() ?? 'N/A';
        $iban = $compte->getNumero() ?? 'N/A';
        $swift = $compte->getCodeSwift() ?? 'N/A';

        // Utilisation des propriétés hydratées par le CanvasBuilder
        $totalEntrees = $compte->totalEntrees ?? 0.0;
        $totalSorties = $compte->totalSorties ?? 0.0;
        $soldeActuel = $compte->soldeActuel ?? 0.0;

        // 3. Construction du HTML
        return sprintf(
            '<div class="jsb-autocomplete-item" style="background-color: #ffffff;">
                <div class="jsb-autocomplete-title">%s</div>
                <div class="jsb-autocomplete-context">
                    <span>Entreprise: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>IBAN: <strong>%s</strong></span>
                    <span class="jsb-context-separator">|</span>
                    <span>SWIFT: <strong>%s</strong></span>
                </div>
                <div class="jsb-autocomplete-indicators" style="grid-template-columns: repeat(3, 1fr);">
                    <div><div><span class="jsb-indicator-label">Total Entrées</span><span class="jsb-indicator-value text-success">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Total Sorties</span><span class="jsb-indicator-value text-danger">%s</span></div></div>
                    <div><div><span class="jsb-indicator-label">Solde Actuel</span><span class="jsb-indicator-value text-cobalt">%s</span></div></div>
                </div>
            </div>',
            htmlspecialchars($nomCompte),
            htmlspecialchars($nomEntreprise),
            htmlspecialchars($iban),
            htmlspecialchars($swift),
            number_format($totalEntrees, 2, ',', ' '),
            number_format($totalSorties, 2, ',', ' '),
            number_format($soldeActuel, 2, ',', ' ')
        );
    }
}