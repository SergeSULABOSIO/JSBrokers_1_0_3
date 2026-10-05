<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\AutoriteFiscale;
use App\Services\CanvasBuilder;

/**
 * Le libelle d'une AutoriteFiscale dans un champ d'autocompletion.
 *
 * Le triplet « du / paye / reste » EST la grammaire de cette entite : elle n'a que ces
 * trois indicateurs, et ils disent exactement ce qu'on vient chercher.
 *
 * L'abreviation accompagne le nom au lieu d'occuper une ligne : c'est elle qui distingue
 * deux autorites aux noms voisins.
 */
class AutoriteFiscaleAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(AutoriteFiscale $autorite): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($autorite);

        return $this->rendu->libelle(
            titre: $autorite->getNom(),
            suffixe: $autorite->getAbreviation(),
            chiffres: [
                Chiffre::montant('Taxe due', $autorite->taxeDue ?? null),
                Chiffre::montant('Payée', $autorite->taxePayee ?? null),
                Chiffre::solde('Reste dû', $autorite->taxeSolde ?? null),
            ],
        );
    }
}
