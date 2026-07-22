import { Controller } from '@hotwired/stimulus';
import { formatInstant } from '../datetime-format.js';

/*
 * Affiche le solde de tokens en quasi temps réel : interroge périodiquement
 * l'endpoint JSON du solde, au retour de focus sur l'onglet et à l'instant même
 * du prochain renouvellement. Met à jour les chiffres (gratuit / prépayé /
 * total), la barre de progression de l'allocation gratuite et la date du
 * prochain renouvellement.
 *
 * L'horloge du SERVEUR fait autorité : le navigateur ne fait que redemander le
 * solde au bon moment, c'est le serveur qui décide si la fenêtre est renouvelée.
 */
export default class extends Controller {
    static targets = ['free', 'paid', 'total', 'bar', 'renewal'];
    static values = {
        url: String,
        interval: { type: Number, default: 30000 },
        allowance: { type: Number, default: 1000 },
    };

    connect() {
        this.renewalTimer = null;
        this.renewalAt = null;
        this.onFocus = () => this.refresh();
        window.addEventListener('focus', this.onFocus);
        this.timer = window.setInterval(() => this.refresh(), this.intervalValue);
        // Échéance déjà connue du rendu serveur : on l'arme sans attendre un tick.
        if (this.hasRenewalTarget) {
            this.scheduleRenewalRefresh(this.renewalTarget.getAttribute('datetime'));
        }
    }

    disconnect() {
        window.removeEventListener('focus', this.onFocus);
        if (this.timer) window.clearInterval(this.timer);
        this.clearRenewalTimer();
    }

    async refresh() {
        if (!this.hasUrlValue) return;
        try {
            const res = await fetch(this.urlValue, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            this.apply(data);
        } catch (_) {
            /* silencieux : on réessaiera au prochain tick */
        }
    }

    apply(data) {
        if (this.hasFreeTarget) this.freeTarget.textContent = this.fmt(data.free);
        if (this.hasPaidTarget) this.paidTarget.textContent = this.fmt(data.paid);
        if (this.hasTotalTarget) this.totalTarget.textContent = this.fmt(data.total);

        if (this.hasBarTarget) {
            const allowance = data.allowance || this.allowanceValue || 1;
            const pct = Math.max(0, Math.min(100, Math.round((data.free / allowance) * 100)));
            this.barTarget.style.width = pct + '%';
            this.barTarget.setAttribute('aria-valuenow', String(pct));
        }

        if (this.hasRenewalTarget && data.nextRenewalAt) {
            const texte = formatInstant(data.nextRenewalAt);
            if (texte) {
                this.renewalTarget.setAttribute('datetime', data.nextRenewalAt);
                this.renewalTarget.textContent = texte;
            }
            this.scheduleRenewalRefresh(data.nextRenewalAt);
        }
    }

    /**
     * Programme une interrogation du serveur juste après l'échéance annoncée,
     * pour que le solde se recharge à l'instant promis et non au tick suivant.
     * Délais aberrants ignorés (échéance déjà passée : le polling suffit ;
     * au-delà de 24 h : hors de portée d'un setTimeout utile).
     */
    scheduleRenewalRefresh(iso) {
        const echeance = new Date(iso);
        if (isNaN(echeance.getTime())) return;

        const delai = echeance.getTime() - Date.now() + 2000; // marge pour l'horloge serveur
        if (delai <= 0 || delai > 86400000) return;
        if (this.renewalAt === echeance.getTime()) return; // déjà programmé

        this.clearRenewalTimer();
        this.renewalAt = echeance.getTime();
        this.renewalTimer = window.setTimeout(() => {
            this.renewalTimer = null;
            this.renewalAt = null;
            this.refresh();
        }, delai);
    }

    clearRenewalTimer() {
        if (this.renewalTimer) window.clearTimeout(this.renewalTimer);
        this.renewalTimer = null;
        this.renewalAt = null;
    }

    fmt(n) {
        return new Intl.NumberFormat().format(Number(n) || 0);
    }
}
