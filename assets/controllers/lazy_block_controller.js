import { Controller } from '@hotwired/stimulus';

/**
 * Charge à la demande le contenu d'un bloc (fetch → innerHTML) et, en option,
 * le rafraîchit à intervalle régulier sans squelette (rechargement silencieux).
 *
 * Valeurs :
 *   - url         : point d'entrée renvoyant le HTML du bloc (obligatoire)
 *   - interval    : période d'auto-rafraîchissement en ms (0 = aucun)
 *   - statusId    : id d'un élément où écrire « Dernière mise à jour à HH:MM »
 *   - skipInitial : ne pas charger au démarrage (contenu déjà rendu côté serveur)
 *
 * Si le bloc est dans un <details>, le chargement est différé à sa première
 * ouverture ET l'auto-rafraîchissement n'est actif QUE tant qu'il est ouvert
 * (économie de bande passante : un bloc replié ne consomme rien).
 */
export default class extends Controller {
    static values = { url: String, interval: Number, statusId: String, skipInitial: Boolean };

    connect() {
        if (!this.urlValue) return;
        this._details = this.element.closest('details');
        if (this._details) {
            this._toggleHandler = () => this._onToggle();
            this._details.addEventListener('toggle', this._toggleHandler);
        }
        if (!this._details || this._details.open) {
            this._boot();
        }
    }

    disconnect() {
        this._stopTimer();
        if (this._details && this._toggleHandler) {
            this._details.removeEventListener('toggle', this._toggleHandler);
        }
    }

    _onToggle() {
        if (this._details.open) {
            if (!this._loaded) this._boot();
            else this._startTimer();
        } else {
            this._stopTimer();
        }
    }

    _boot() {
        this._loaded = true;
        // skipInitial : le contenu est déjà rendu côté serveur (toujours visible
        // dès le chargement) ; on n'effectue que les rafraîchissements silencieux.
        if (!this.skipInitialValue) {
            this._load(true);
        }
        this._startTimer();
    }

    _startTimer() {
        if (this._timer || this.intervalValue <= 0) return;
        if (this._details && !this._details.open) return;
        this._timer = setInterval(() => this._load(false), this.intervalValue);
    }

    _stopTimer() {
        if (this._timer) { clearInterval(this._timer); this._timer = null; }
    }

    _pad(n) { return n < 10 ? '0' + n : '' + n; }

    _setStatus() {
        if (!this.statusIdValue) return;
        const el = document.getElementById(this.statusIdValue);
        if (!el) return;
        const now = new Date();
        el.textContent = 'Dernière mise à jour à ' + this._pad(now.getHours()) + ':' + this._pad(now.getMinutes());
    }

    _load(initial) {
        if (initial) {
            this.element.innerHTML = '<div class="db-skeleton-block" style="min-height:80px;margin:.5rem 0;"></div>';
        }
        fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.ok ? r.text() : Promise.reject('HTTP ' + r.status))
            .then(html => { this.element.innerHTML = html; this._setStatus(); })
            .catch(err => {
                console.warn('[lazy-block] Failed to load:', this.urlValue, err);
                if (initial) {
                    this.element.innerHTML = '<p class="text-muted small p-3 text-center" style="color:#6c757d;">Erreur de chargement du bloc</p>';
                }
            });
    }
}
