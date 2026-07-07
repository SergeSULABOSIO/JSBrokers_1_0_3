import { Controller } from '@hotwired/stimulus';

/**
 * Picker de PORTEFEUILLE cible pour un client (actions « Affecter à un portefeuille » /
 * « Transférer vers un autre portefeuille » de la rubrique Clients).
 *
 * Le HTML (_portefeuille_picker.html.twig) est chargé et inséré dans le DOM par le
 * cerveau (handleClientPortefeuillePickerRequest) ; ce contrôleur s'auto-connecte à
 * l'insertion et porte tout le comportement : focus initial, fermeture (✕ / backdrop /
 * Échap) avec restitution du focus, filtrage + compteur, action PUT d'affectation.
 * C'est l'extraction du comportement picker de collection_controller (openPicker &
 * co.), découplée du widget collection : au succès, on notifie le cerveau
 * (client:portefeuille.updated) qui affiche la notification et rafraîchit la liste.
 */
export default class extends Controller {
    static values = {
        clientNom: String,
    };

    connect() {
        this.nomControleur = 'PORTEFEUILLE-PICKER';

        // Restitution du focus à la fermeture (WCAG / Nielsen 3) : l'élément actif au
        // moment de l'insertion est le déclencheur (bouton de toolbar, item de menu…).
        this.previousFocus = document.activeElement;

        // Fermeture au clavier (Échap) — sortie d'urgence.
        this.boundKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this.boundKeydown);

        // Délégation des clics (fermeture, affectation).
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
        const affectBtn = event.target.closest('[data-picker-affect]');
        if (affectBtn) this._affect(affectBtn);
    }

    /**
     * Affecte/transfère le client vers le portefeuille de la ligne (PUT). Le choix dans
     * le picker VAUT confirmation : succès → notification du cerveau (message serveur
     * détaillé) + fermeture ; erreur → message inline dans le picker (Nielsen 9).
     */
    async _affect(button) {
        const url = button.dataset.affectUrl;
        if (!url || this.affectRunning) return;
        this.affectRunning = true;

        button.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(url, {
                method: 'PUT',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur: ${response.status}`);

            // Le cerveau affiche la notification et rafraîchit la liste active
            // (hasPortefeuille et la ligne secondaire se recalculent au refresh).
            this._notifyCerveau('client:portefeuille.updated', {
                message: data.message || 'Portefeuille mis à jour.',
            });
            this.close();
        } catch (error) {
            button.disabled = false;
            this._showError(error.message || "Affectation impossible. Réessayez ou contactez le propriétaire de l'espace.");
        } finally {
            this.affectRunning = false;
            this._progress(false);
        }
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
                // Surligne le mot-clé dans le nom et le gestionnaire des lignes affichées.
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
