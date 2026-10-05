<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Partenaire;
use App\Services\CanvasBuilder;

/**
 * Le libelle d'un Partenaire dans un champ d'autocompletion.
 *
 * Quatre tuiles sont devenues trois chiffres : ce qui lui est du, ce qui lui a ete
 * reverse, ce qui reste. La commission pure, qui ne le concerne pas directement, a quitte
 * la liste -- elle se lit sur sa fiche.
 */
class PartenaireAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(Partenaire $partenaire): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($partenaire);

        return $this->rendu->libelle(
            titre: $partenaire->getNom(),
            contact: [$partenaire->getEmail(), $partenaire->getTelephone()],
            chiffres: [
                Chiffre::montant('Rétro due', $partenaire->retroCommission ?? null),
                Chiffre::montant('Reversée', $partenaire->retroCommissionReversee ?? null),
                Chiffre::solde('Reste dû', $partenaire->retroCommissionSolde ?? null),
            ],
        );
    }
}
