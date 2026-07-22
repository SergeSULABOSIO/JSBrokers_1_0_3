import { Controller } from '@hotwired/stimulus';
import { formatNombre } from '../number-format.js';

/*
 * Aperçu en direct de la remise d'un coupon sur la page d'achat de tokens.
 * Au changement de paquet ou à l'application d'un code, interroge l'endpoint JSON
 * (admin.token.coupon_preview) — la MÊME logique que l'achat — et met à jour le
 * récapitulatif : prix du paquet, remise, montant à payer. Un code invalide
 * affiche un message sans bloquer la page (la validation ferme se fait à l'achat).
 */
export default class extends Controller {
    static targets = ['code', 'base', 'remise', 'final', 'remiseRow', 'error', 'recap'];
    static values = { url: String };

    connect() {
        // Aperçu initial (utile quand un code est prérempli depuis la vitrine).
        this.refresh();
    }

    /** Paquet sélectionné (radio coché) du formulaire d'achat. */
    selectedPack() {
        const checked = this.element.querySelector('input[name="token_purchase[pack]"]:checked');
        return checked ? checked.value : '';
    }

    async refresh() {
        if (!this.hasUrlValue) return;

        const pack = this.selectedPack();
        if (!pack) return;

        const code = this.hasCodeTarget ? (this.codeTarget.value || '').trim() : '';
        const url = `${this.urlValue}?pack=${encodeURIComponent(pack)}&code=${encodeURIComponent(code)}`;

        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            this.apply(data);
        } catch (_) {
            /* silencieux : on retentera au prochain changement */
        }
    }

    apply(data) {
        if (this.hasErrorTarget) {
            this.errorTarget.textContent = data.erreur || '';
            this.errorTarget.hidden = !data.erreur;
        }

        const base = Number(data.base) || 0;
        const final = Number(data.montantFinal);
        const remise = Number(data.remiseUsd) || 0;

        if (this.hasBaseTarget) this.baseTarget.textContent = this.money(base);
        // En cas d'erreur, on retombe sur le plein tarif (pas de remise appliquée).
        if (this.hasFinalTarget) this.finalTarget.textContent = this.money(isNaN(final) || data.erreur ? base : final);
        if (this.hasRemiseTarget) this.remiseTarget.textContent = '− ' + this.money(remise);
        if (this.hasRemiseRowTarget) this.remiseRowTarget.hidden = !(remise > 0 && !data.erreur);
        if (this.hasRecapTarget) this.recapTarget.hidden = false;
    }

    /** Montant écrit dans la notation de la langue active, comme le rendu serveur. */
    money(n) {
        return (formatNombre(n, 2) || formatNombre(0, 2)) + ' $';
    }
}
