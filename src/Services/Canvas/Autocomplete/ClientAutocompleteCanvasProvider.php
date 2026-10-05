<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Client;
use App\Services\CanvasBuilder;

/**
 * Le libelle d'un Client dans un champ d'autocompletion.
 *
 * Cinq tuiles chiffrees et un `Email: N/A | Tel: N/A` sont devenus trois chiffres et une
 * ligne de contact qui n'existe que s'il y a un contact.
 *
 * ── POURQUOI « COMM. DUE » ET NON « SOLDE » ─────────────────────────────────────
 * « Solde » en rouge sous le nom d'un client ne dit pas QUI doit QUOI. `solde_restant_du`
 * est la commission que NOUS n'avons pas encore encaissee sur ses affaires -- pas sa
 * dette de prime. Deux mots de plus, et le doute disparait.
 */
class ClientAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(Client $client): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($client);

        return $this->rendu->libelle(
            titre: $client->getNom(),
            contact: [$client->getEmail(), $client->getTelephone()],
            chiffres: [
                Chiffre::montant('Prime', $client->primeTotale ?? null),
                Chiffre::montant('Comm. TTC', $client->montantTTC ?? null),
                Chiffre::solde('Comm. due', $client->solde_restant_du ?? null),
            ],
        );
    }
}
