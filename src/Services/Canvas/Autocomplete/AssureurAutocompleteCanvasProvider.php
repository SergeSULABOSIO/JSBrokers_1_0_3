<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Assureur;
use App\Services\CanvasBuilder;

/**
 * Le libellé d'un Assureur dans un champ d'autocomplétion.
 *
 * ── CE QU'IL AFFICHAIT ──────────────────────────────────────────────────────────
 * Soixante et une lignes de `sprintf`, pour une carte de sept à dix-huit lignes : le nom,
 * puis `Email: N/A | Tél: N/A`, puis CINQ tuiles chiffrées empilées — prime, commission,
 * taxe courtier, taxe assureur, rétro. Le tout restait affiché dans le champ une fois le
 * choix fait, `render.option` et `render.item` étant identiques dans le bundle.
 *
 * ── CE QU'IL AFFICHE ────────────────────────────────────────────────────────────
 * Le nom, une ligne de contact s'il y en a une, et TROIS chiffres : ce qui est placé, ce
 * qui est rentré, ce qui reste. Une liste d'autocomplétion sert à choisir ; cinq chiffres
 * obligent à lire avant de choisir.
 *
 * Tout le « comment » vit dans {@see RenduOptionAutocomplete} — balisage, échappement,
 * troncature, format selon la langue, et le sort des champs vides.
 */
class AssureurAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(Assureur $assureur): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($assureur);

        return $this->rendu->libelle(
            titre: $assureur->getNom(),
            contact: [$assureur->getEmail(), $assureur->getTelephone()],
            chiffres: [
                Chiffre::montant('Prime', $assureur->primeTotale ?? null),
                Chiffre::montant('Comm. TTC', $assureur->montantTTC ?? null),
                Chiffre::solde('Comm. due', $assureur->solde_restant_du ?? null),
            ],
        );
    }
}
