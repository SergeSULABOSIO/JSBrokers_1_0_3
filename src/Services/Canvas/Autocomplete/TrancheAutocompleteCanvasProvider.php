<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Tranche;
use App\Services\CanvasBuilder;

/**
 * Le libellé d'une Tranche dans un champ d'autocomplétion.
 *
 * ── QUINZE MÉTRIQUES POUR CHOISIR UNE ÉCHÉANCE ──────────────────────────────────
 * L'ancien rendu, écrit directement dans le FormType, affichait cinq colonnes de trois
 * chiffres : prime, commission, rétro, taxe courtier, taxe assureur — chacune avec son
 * dû, son payé et son solde. Quinze nombres pour départager deux échéances, quand la
 * question posée est « laquelle facturer ? ».
 *
 * Trois chiffres y répondent : ce qui est dû, ce qui est rentré, ce qui reste.
 *
 * ── LE TAUX RESTE COLLÉ AU NOM, ET C'EST LUI QUI DISTINGUE ──────────────────────
 * Deux tranches d'une même cotation portent souvent le même libellé (« 1ère tranche »,
 * « 2ème tranche »). Ce qui les sépare à l'œil, c'est leur taux — pas leurs montants, qui
 * se ressemblent. Il accompagne donc le titre plutôt que de descendre parmi les chiffres.
 *
 * ── « COMM. TTC » ET « COMM. DUE », ET NON L'INVERSE ────────────────────────────
 * `montant_du` est bien un montant TTC : `TrancheIndicatorStrategy` lui donne la même
 * valeur qu'à `montantCalculeTTC` (l. 122 et 128). L'appeler « Comm. due » aurait fait du
 * même mot un total ici et un solde ailleurs — c'est la dispersion que ce lot referme.
 */
class TrancheAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(Tranche $tranche): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($tranche);

        $cotation = $tranche->getCotation();
        $avenant = ($cotation && !$cotation->getAvenants()->isEmpty())
            ? $cotation->getAvenants()->first()
            : null;

        return $this->rendu->libelle(
            titre: $tranche->getNom(),
            suffixe: $this->taux($tranche),
            contact: [
                $avenant?->getReferencePolice(),
                $cotation?->getPiste()?->getClient()?->getNom(),
            ],
            chiffres: [
                Chiffre::montant('Comm. TTC', $tranche->montant_du ?? null),
                Chiffre::montant('Encaissée', $tranche->montant_paye ?? null),
                Chiffre::solde('Comm. due', $tranche->solde_restant_du ?? null),
            ],
        );
    }

    /**
     * Le taux, déjà en POINTS.
     *
     * `tauxTranche` sort de la stratégie en pourcentage — la convention unique du projet
     * depuis le 25/07/2026. Le multiplier par cent le rendrait cent fois trop grand.
     */
    private function taux(Tranche $tranche): ?string
    {
        $taux = $tranche->tauxTranche ?? null;

        return $taux === null ? null : rtrim(rtrim(number_format((float) $taux, 2, ',', ' '), '0'), ',') . ' %';
    }
}
