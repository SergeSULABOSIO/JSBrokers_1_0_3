<?php

namespace App\Services\Canvas\Autocomplete;

use App\Entity\Bordereau;
use App\Services\CanvasBuilder;

/**
 * Le libellé d'un Bordereau dans un champ d'autocomplétion.
 *
 * ── TROIS CHIFFRES QUI SE SOUSTRAIENT VRAIMENT ──────────────────────────────────
 * L'ancien rendu affichait « Montant TTC », « Encaissé » et « Solde » côte à côte. Trois
 * nombres alignés invitent à soustraire — et la soustraction ne tombait pas juste :
 * `solde` vaut `comTtcPayableNow − montantEncaisse` (BordereauIndicatorStrategy, l. 44),
 * alors que le premier chiffre montré était `montantCommissionTTC`, la somme de TOUS les
 * avenants du bordereau (l. 37).
 *
 * On montre donc `comTtcPayableNow` sous « Exigible » : *Exigible − Encaissé = Reste dû*
 * est désormais vrai à l'écran. Le total de commission de tous les avenants reste lisible
 * sur la fiche du bordereau, où il n'induit personne en erreur.
 *
 * ── LE STATUT A ÉTÉ RETIRÉ, ET CE N'EST PAS UN OUBLI ────────────────────────────
 * L'ancien rendu annonçait « Payé » un bordereau seulement FACTURÉ. Deux machines à états
 * cohabitent sur la colonne `statut` — un cycle de paiement (0-5) et un cycle d'analyse
 * (10-15) — et `STATUT_PAYE` comme `STATUT_FACTURE` valent tous deux **3**. Le provider
 * lisait la valeur APRÈS que `loadAllCalculatedValues()` l'ait écrasée par un état
 * d'analyse, puis la comparait aux constantes de paiement.
 *
 * Un libellé « nom + contact + trois chiffres » n'a pas de place pour un statut : le
 * retirer ne coûte rien et cesse d'exposer un défaut qui appartient à l'entité. La
 * collision de constantes, elle, reste à trancher ailleurs.
 *
 * ── LA DEVISE AUSSI ─────────────────────────────────────────────────────────────
 * `getCodeMonnaieAffichage()` était concaténé trois fois à des sommes brutes. Or
 * `Bordereau` n'a aucun champ de devise et aucune conversion n'a lieu : le code affirmait
 * une unité que le calcul n'établit pas. Sept des huit rendus n'en affichaient d'ailleurs
 * aucune.
 */
class BordereauAutocompleteCanvasProvider
{
    public function __construct(
        private CanvasBuilder $canvasBuilder,
        private RenduOptionAutocomplete $rendu,
    ) {
    }

    public function getChoiceLabel(Bordereau $bordereau): string
    {
        $this->canvasBuilder->loadAllCalculatedValues($bordereau);

        $periode = $this->periode($bordereau);

        return $this->rendu->libelle(
            titre: $bordereau->getNom(),
            suffixe: $bordereau->getReference(),
            contact: [$bordereau->getAssureur()?->getNom(), $periode],
            chiffres: [
                Chiffre::montant('Exigible', $bordereau->comTtcPayableNow ?? null),
                Chiffre::montant('Encaissé', $bordereau->montantEncaisse ?? null),
                Chiffre::solde('Reste dû', $bordereau->solde ?? null),
            ],
        );
    }

    /**
     * La période, ou rien.
     *
     * Une seule des deux bornes ne dit pas une période : mieux vaut se taire que d'écrire
     * « 01/04/2026 - » et laisser l'utilisateur deviner la seconde.
     */
    private function periode(Bordereau $bordereau): ?string
    {
        $debut = $bordereau->getPeriodeDebut();
        $fin = $bordereau->getPeriodeFin();

        if ($debut === null || $fin === null) {
            return null;
        }

        return $debut->format('d/m/Y') . ' – ' . $fin->format('d/m/Y');
    }
}
