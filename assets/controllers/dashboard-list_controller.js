import { Controller } from '@hotwired/stimulus';

/**
 * Bloc « liste » du tableau de bord console, calqué sur les blocs liste du
 * tableau de bord courtiers : un champ de recherche statique filtre les lignes
 * côté client, et seul le contenu de la liste (fragment) est rechargé toutes
 * les N millisecondes. Le critère de recherche est ré-appliqué après chaque
 * rafraîchissement, et l'horodatage « Dernière mise à jour » est mis à jour.
 *
 * Cibles  : search (champ de filtre), list (conteneur des lignes).
 * Valeurs : url (fragment), interval (ms, 0 = aucun), statusId (id horodatage).
 * Les lignes filtrables portent l'attribut data-row.
 */
export default class extends Controller {
    static targets = ['search', 'list', 'field'];
    static values = { url: String, interval: Number, statusId: String };

    connect() {
        // Garantit un champ vide au chargement, quoi que tente l'auto-remplissage
        // du navigateur / gestionnaire de mots de passe.
        if (this.hasSearchTarget) this.searchTarget.value = '';
        this._applyFilter();
        this._setStatus();

        // L'auto-rafraîchissement n'est actif QUE pendant que le bloc (<details>)
        // est ouvert : on démarre/arrête le minuteur au gré des bascules afin de
        // ne consommer aucune bande passante quand le bloc est replié.
        this._details = this.element.closest('details');
        if (this._details) {
            this._toggleHandler = () => {
                if (this._details.open) { this.refresh(); this._startTimer(); }
                else { this._stopTimer(); }
            };
            this._details.addEventListener('toggle', this._toggleHandler);
        }
        // Au connect, le contenu vient d'être chargé (bloc ouvert) et est frais :
        // on lance le minuteur sans refetch immédiat.
        this._startTimer();
    }

    disconnect() {
        this._stopTimer();
        if (this._details && this._toggleHandler) {
            this._details.removeEventListener('toggle', this._toggleHandler);
        }
    }

    _startTimer() {
        if (this._timer || this.intervalValue <= 0) return;
        if (this._details && !this._details.open) return;
        this._timer = setInterval(() => this.refresh(), this.intervalValue);
    }

    _stopTimer() {
        if (this._timer) { clearInterval(this._timer); this._timer = null; }
    }

    // Le champ est en lecture seule par défaut (anti auto-remplissage) ; on le
    // rend modifiable dès que l'utilisateur lui donne le focus pour filtrer.
    unlock() {
        if (this.hasSearchTarget) this.searchTarget.removeAttribute('readonly');
    }

    search() { this._applyFilter(); }

    refresh() {
        if (!this.urlValue || !this.hasListTarget) return;
        fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.ok ? r.text() : Promise.reject('HTTP ' + r.status))
            .then(html => { this.listTarget.innerHTML = html; this._applyFilter(); this._setStatus(); })
            .catch(err => console.warn('[dashboard-list] refresh failed:', this.urlValue, err));
    }

    _applyFilter() {
        if (!this.hasListTarget) return;
        const q = this.hasSearchTarget ? this.searchTarget.value.trim().toLowerCase() : '';
        // Champ ciblé : 'all' (ou absence de sélecteur) = toute la ligne ;
        // sinon on ne compare que la/les cellule(s) marquée(s) data-field=<champ>.
        const field = this.hasFieldTarget ? this.fieldTarget.value : 'all';
        this.listTarget.querySelectorAll('[data-row]').forEach(row => {
            if (!q) { row.style.display = ''; return; }
            let haystack;
            if (field && field !== 'all') {
                const cells = row.querySelectorAll('[data-field="' + field + '"]');
                haystack = cells.length
                    ? Array.from(cells).map(c => c.textContent).join(' ')
                    : row.textContent;
            } else {
                haystack = row.textContent;
            }
            row.style.display = haystack.toLowerCase().includes(q) ? '' : 'none';
        });
    }

    _pad(n) { return n < 10 ? '0' + n : '' + n; }

    _setStatus() {
        if (!this.statusIdValue) return;
        const el = document.getElementById(this.statusIdValue);
        if (!el) return;
        const now = new Date();
        el.textContent = 'Dernière mise à jour à ' + this._pad(now.getHours()) + ':' + this._pad(now.getMinutes());
    }
}
