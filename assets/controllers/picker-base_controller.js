import { Controller } from '@hotwired/stimulus';

/**
 * Socle COMMUN des pickers autonomes (overlay .jsb-picker-* inséré dans le DOM par le
 * cerveau) : focus initial et restitution, fermeture (✕ / backdrop / Échap), délégation
 * des clics, filtrage + compteur + surlignage, barre de progression, zone d'erreur et
 * convention d'événements vers le cerveau. Chaque picker concret (portefeuille-picker,
 * client-picker) hérite de ce socle et n'implémente que ses actions métier via le
 * point d'extension _onActionClick().
 */
export default class extends Controller {
    /** Nom du contrôleur rapporté au cerveau (source des événements). */
    static pickerName = 'PICKER';

    connect() {
        this.nomControleur = this.constructor.pickerName;

        // Restitution du focus à la fermeture (WCAG / Nielsen 3) : l'élément actif au
        // moment de l'insertion est le déclencheur (bouton de toolbar, item de menu…).
        this.previousFocus = document.activeElement;

        // Fermeture au clavier (Échap) — sortie d'urgence.
        this.boundKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this.boundKeydown);

        // Délégation des clics (fermeture, actions métier).
        this.boundClick = (e) => this._onClick(e);
        this.element.addEventListener('click', this.boundClick);

        // Recherche : filtrage des lignes (input + keyup, robustesse navigateurs).
        const searchInput = this.element.querySelector('[data-picker-search]');
        if (searchInput) {
            const handler = () => this._filter(searchInput.value);
            searchInput.addEventListener('input', handler);
            searchInput.addEventListener('keyup', handler);
        }

        // Focus initial : le champ de recherche, sinon la boîte elle-même.
        const focusTarget = searchInput || this.element.querySelector('[role="dialog"]');
        if (focusTarget) focusTarget.focus();
    }

    disconnect() {
        if (this.boundKeydown) {
            document.removeEventListener('keydown', this.boundKeydown);
            this.boundKeydown = null;
        }
    }

    /** Ferme le picker (retrait du DOM) et restaure le focus sur le déclencheur. */
    close() {
        const previousFocus = this.previousFocus;
        this.element.remove(); // déclenche disconnect() → nettoyage de l'écouteur Échap
        if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) {
            previousFocus.focus();
        }
    }

    _onClick(event) {
        // Fermeture : bouton dédié ou clic sur l'arrière-plan (overlay).
        if (event.target.closest('[data-picker-close]') || event.target.hasAttribute('data-picker-overlay')) {
            this.close();
            return;
        }
        this._onActionClick(event);
    }

    /** Point d'extension : les actions métier du picker concret (ajout, retrait…). */
    _onActionClick(event) { // eslint-disable-line no-unused-vars
    }

    /** Affiche (texte) ou masque (null) la zone d'erreur inline du picker. */
    _showError(text) {
        const zone = this.element.querySelector('[data-picker-error]');
        if (!zone) return;
        zone.textContent = text || '';
        zone.hidden = !text;
    }

    _progress(active) {
        const bar = this.element.querySelector('[data-picker-progress]');
        if (bar) bar.classList.toggle('is-active', !!active);
    }

    /** Filtre les lignes sur data-search + met à jour le compteur « affiché(s) ». */
    _filter(query) {
        const raw = (query || '').trim();
        const q = raw.toLowerCase();
        let visible = 0;
        this.element.querySelectorAll('[data-picker-row]').forEach((row) => {
            const match = q === '' || (row.dataset.search || '').includes(q);
            row.style.display = match ? '' : 'none';
            if (match) {
                visible++;
                // Surligne le mot-clé dans les libellés des lignes affichées.
                row.querySelectorAll('.jsb-picker-client-nom, .jsb-picker-client-mail').forEach((el) => {
                    if (el.dataset.orig === undefined) el.dataset.orig = el.textContent;
                    this._highlightInto(el, el.dataset.orig, raw);
                });
            }
        });
        const empty = this.element.querySelector('[data-picker-empty]');
        if (empty) empty.hidden = visible !== 0;
        const shown = this.element.querySelector('[data-picker-count-shown]');
        if (shown) shown.textContent = visible;
    }

    /**
     * Réécrit le contenu de `el` à partir du texte original, en enveloppant chaque
     * occurrence de `query` dans un <mark> (via des nœuds DOM : aucun risque d'injection).
     */
    _highlightInto(el, original, query) {
        const q = (query || '').trim();
        if (!q) { el.textContent = original; return; }

        el.textContent = '';
        const lower = original.toLowerCase();
        const qlower = q.toLowerCase();
        let i = 0;
        let idx;
        while ((idx = lower.indexOf(qlower, i)) !== -1) {
            if (idx > i) el.appendChild(document.createTextNode(original.slice(i, idx)));
            const mark = document.createElement('mark');
            mark.className = 'jsb-picker-hl';
            mark.textContent = original.slice(idx, idx + q.length);
            el.appendChild(mark);
            i = idx + q.length;
        }
        if (i < original.length) el.appendChild(document.createTextNode(original.slice(i)));
    }

    /** Émet un événement à destination du cerveau (même convention que les autres contrôleurs). */
    _notifyCerveau(type, payload = {}) {
        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() },
        }));
    }
}
