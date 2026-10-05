<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\CompteBancaire;
use App\Services\CanvasBuilder;

/**
 * Le libelle d'un CompteBancaire dans un champ d'autocompletion.
 *
 * ── LE SOLDE D'UN COMPTE N'EST PAS UN RESTE DU ──────────────────────────────────
 * `soldeActuel` est rendu en `Chiffre::montant` et NON en `Chiffre::solde`. La nuance
 * n'est pas typographique : un `solde` se peint en rouge des qu'il est non nul, ce qui
 * ferait d'un compte approvisionne une alerte. Le genre dit ce que le lecteur doit
 * comprendre, et ici il n'y a rien a comprendre d'autre que le nombre.
 *
 * Les couleurs decoratives d'autrefois (vert pour les entrees, rouge pour les sorties)
 * partent pour la meme raison : un flux n'a pas d'etat.
 */
class CompteBancaireAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(CompteBancaire $compte): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($compte);

        return $this->rendu->libelle(
            titre: $compte->getNom(),
            contact: [$compte->getBanque(), $compte->getNumero()],
            chiffres: [
                Chiffre::montant('Entrées', $compte->totalEntrees ?? null),
                Chiffre::montant('Sorties', $compte->totalSorties ?? null),
                Chiffre::montant('Solde', $compte->soldeActuel ?? null),
            ],
        );
    }
}
