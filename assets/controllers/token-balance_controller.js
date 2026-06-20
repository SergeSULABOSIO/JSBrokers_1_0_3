import { Controller } from '@hotwired/stimulus';

/*
 * Affiche le solde de tokens en quasi temps réel : interroge périodiquement
 * l'endpoint JSON du solde et au retour de focus sur l'onglet. Met à jour les
 * chiffres (gratuit / prépayé / total), la barre de progression de l'allocation
 * gratuite et la date du prochain renouvellement.
 */
export default class extends Controller {
    static targets = ['free', 'paid', 'total', 'bar', 'renewal'];
    static values = {
        url: String,
        interval: { type: Number, default: 30000 },
        allowance: { type: Number, default: 1000 },
    };

    connect() {
        this.onFocus = () => this.refresh();
        window.addEventListener('focus', this.onFocus);
        this.timer = window.setInterval(() => this.refresh(), this.intervalValue);
    }

    disconnect() {
        window.removeEventListener('focus', this.onFocus);
        if (this.timer) window.clearInterval(this.timer);
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
            const d = new Date(data.nextRenewalAt);
            if (!isNaN(d)) {
                this.renewalTarget.textContent = d.toLocaleString();
            }
        }
    }

    fmt(n) {
        return new Intl.NumberFormat().format(Number(n) || 0);
    }
}
