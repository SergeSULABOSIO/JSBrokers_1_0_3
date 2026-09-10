import { Controller } from '@hotwired/stimulus';

/**
 * Demande à TOUS les blocs de se recharger avec de nouveaux paramètres de requête.
 * `detail.params` : un objet `{ nom: valeur }` fusionné dans l'URL de chaque bloc.
 */
export const RELOAD_EVENT = 'app:lazy-block.reload';

/** Un bloc a fini — qu'il ait rechargé, échoué, ou seulement mis son URL à jour. */
export const LOADED_EVENT = 'app:lazy-block.loaded';

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
        // ⚠ LE RECHARGEMENT VIENT DE L'EXTÉRIEUR, ET IL N'Y AVAIT AUCUN MOYEN DE LE
        // DEMANDER. Ni méthode publique, ni action, et `urlValue` n'a pas de callback de
        // changement : la modifier ne déclenchait rien. Un réglage qui vaut pour TOUS les
        // blocs — l'exercice comptable — n'avait donc aucune prise sur eux.
        this._reloadHandler = (event) => this._surDemandeDeRechargement(event);
        document.addEventListener(RELOAD_EVENT, this._reloadHandler);
        if (!this._details || this._details.open) {
            this._boot();
        }
    }

    disconnect() {
        this._stopTimer();
        if (this._details && this._toggleHandler) {
            this._details.removeEventListener('toggle', this._toggleHandler);
        }
        if (this._reloadHandler) {
            document.removeEventListener(RELOAD_EVENT, this._reloadHandler);
            this._reloadHandler = null;
        }
    }

    /**
     * Un réglage global a changé : on réécrit l'URL, et on recharge si le bloc est visible.
     *
     * ⚠ L'URL SE MET À JOUR MÊME QUAND ON NE RECHARGE PAS. Un bloc replié ne consomme rien
     * — c'est tout l'intérêt du `<details>` —, mais s'il gardait l'ancienne URL, l'ouvrir
     * plus tard afficherait l'exercice précédent sans que rien ne le signale.
     *
     * ⚠ ET IL RÉPOND TOUJOURS. Celui qui a demandé le rechargement compte les réponses pour
     * avancer sa barre de progression : un bloc qui se tait la laisserait inachevée.
     */
    _surDemandeDeRechargement(event) {
        if (!this.urlValue) return;

        const params = (event.detail && event.detail.params) || {};
        this.urlValue = this._avecParametres(this.urlValue, params);

        const visible = !this._details || this._details.open;
        if (!visible || !this._loaded) {
            this._annoncerFin(true);
            return;
        }

        this._load(true, () => this._annoncerFin(true), () => this._annoncerFin(false));
    }

    /**
     * L'URL, ses paramètres fusionnés.
     *
     * ⚠ FUSIONNÉS, ET NON CONCATÉNÉS : deux bascules d'affilée ajouteraient sinon deux fois
     * le même paramètre, et c'est la PREMIÈRE valeur que PHP retient — l'écran se figerait
     * sur le premier exercice choisi.
     */
    _avecParametres(url, params) {
        const cible = new URL(url, window.location.origin);
        for (const [cle, valeur] of Object.entries(params)) {
            if (valeur === null || valeur === undefined || valeur === '') {
                cible.searchParams.delete(cle);
            } else {
                cible.searchParams.set(cle, String(valeur));
            }
        }

        return cible.pathname + cible.search;
    }

    _annoncerFin(ok) {
        document.dispatchEvent(new CustomEvent(LOADED_EVENT, {
            detail: { url: this.urlValue, ok },
        }));
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

    _load(initial, onSuccess, onError) {
        if (initial) {
            this.element.innerHTML = '<div class="db-skeleton-block" style="min-height:80px;margin:.5rem 0;"></div>';
        }
        fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.ok ? r.text() : Promise.reject('HTTP ' + r.status))
            .then(html => {
                this.element.innerHTML = html;
                this._setStatus();
                if (onSuccess) onSuccess();
            })
            .catch(err => {
                console.warn('[lazy-block] Failed to load:', this.urlValue, err);
                if (initial) {
                    this.element.innerHTML = '<p class="text-muted small p-3 text-center" style="color:#6c757d;">Erreur de chargement du bloc</p>';
                }
                if (onError) onError();
            });
    }
}
