import { Controller } from '@hotwired/stimulus';

/**
 * @class EntrepriseContextMenuController
 * @description Menu contextuel (clic droit) des cartes d'entreprise de la liste.
 * Posé sur le conteneur de la grille. Au clic droit SUR une carte d'entreprise
 * possédée (présence des actions Éditer / Supprimer), il affiche un menu avec
 * « Éditer {nom} » et « Supprimer {nom} ».
 *
 * Aucune logique métier n'est dupliquée : le menu se contente de déléguer aux
 * éléments déjà présents dans la carte —
 *   • Éditer   → clic sur le lien d'édition (navigation + barre de progression) ;
 *   • Supprimer → soumission du formulaire de suppression, intercepté par le
 *                 contrôleur confirm-action (boîte de confirmation + mot de passe).
 *
 * En dehors d'une carte actionnable, le menu natif du navigateur reste disponible.
 */
export default class extends Controller {
    static targets = ['menu', 'editLabel', 'deleteLabel'];

    connect() {
        this.current = null;
        this._previousFocus = null;

        this._onContextMenu = this._onContextMenu.bind(this);
        this._onDocPointerDown = this._onDocPointerDown.bind(this);
        this._onKeydown = this._onKeydown.bind(this);
        this._onScrollOrResize = this._onScrollOrResize.bind(this);

        this.element.addEventListener('contextmenu', this._onContextMenu);
    }

    disconnect() {
        this._close();
        this.element.removeEventListener('contextmenu', this._onContextMenu);
        this._removeTransientListeners();
    }

    // ── Ouverture ──────────────────────────────────────────────────────────────

    _onContextMenu(event) {
        this._close();

        const card = event.target.closest('.ent-card');
        // Hors d'une carte → on laisse le menu natif du navigateur.
        if (!card || !this.element.contains(card)) return;

        // « Aller dans l'espace de travail » est disponible pour toute carte
        // (le lien vers le tableau de bord existe aussi pour les entreprises où l'on est invité).
        const workspaceLink = card.querySelector('.ent-card-media');
        // Éditer / Supprimer ne sont disponibles que pour une entreprise possédée
        // (le bloc .ent-actions n'existe pas pour les entreprises où l'on est invité).
        const editLink = card.querySelector('.ent-actions a');
        const deleteForm = card.querySelector('.ent-actions form');
        if (!workspaceLink && !editLink && !deleteForm) return; // carte non actionnable → menu natif

        event.preventDefault();

        const nom = card.dataset.ecNom || "l'entreprise";
        this.current = { workspaceLink, editLink, deleteForm };

        if (this.hasEditLabelTarget) this.editLabelTarget.textContent = `Éditer ${nom}`;
        if (this.hasDeleteLabelTarget) this.deleteLabelTarget.textContent = `Supprimer ${nom}`;

        const menu = this.menuTarget;
        // Affiche/masque chaque option selon ce que la carte permet réellement.
        menu.querySelectorAll('[role="menuitem"]').forEach((item) => {
            const key = item.dataset.menuKey;
            const allowed = (key === 'workspace' && workspaceLink)
                || (key === 'edit' && editLink)
                || (key === 'delete' && deleteForm);
            item.style.display = allowed ? '' : 'none';
        });
        // Le séparateur n'a de sens que si l'option destructrice « Supprimer »
        // est précédée d'au moins une autre option.
        const sep = menu.querySelector('[data-menu-key="sep"]');
        if (sep) sep.style.display = (deleteForm && (workspaceLink || editLink)) ? '' : 'none';

        // Positionnement mesuré, borné au viewport.
        menu.style.visibility = 'hidden';
        menu.style.display = 'block';
        const left = Math.max(6, Math.min(event.clientX, window.innerWidth - menu.offsetWidth - 6));
        const top = Math.max(6, Math.min(event.clientY, window.innerHeight - menu.offsetHeight - 6));
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.style.visibility = '';

        // Focus sur le conteneur : aucune option présélectionnée à l'ouverture.
        this._previousFocus = document.activeElement;
        menu.focus();

        this._addTransientListeners();
    }

    // ── Actions ────────────────────────────────────────────────────────────────

    onItemClick(event) {
        const key = event.currentTarget.dataset.menuKey;
        const current = this.current;
        this._close();
        if (!current) return;

        if (key === 'workspace' && current.workspaceLink) {
            // Réutilise le lien vers le tableau de bord (navigation + barre de progression).
            current.workspaceLink.click();
        } else if (key === 'edit' && current.editLink) {
            // Réutilise le lien existant (navigation + barre de progression).
            current.editLink.click();
        } else if (key === 'delete' && current.deleteForm) {
            // Réutilise le formulaire existant : confirm-action intercepte le submit
            // et affiche la boîte de confirmation avec mot de passe.
            const form = current.deleteForm;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        }
    }

    // ── Fermeture ────────────────────────────────────────────────────────────

    _close() {
        if (!this.hasMenuTarget) return;
        const wasOpen = this.menuTarget.style.display !== 'none';
        this.menuTarget.style.display = 'none';
        this._removeTransientListeners();
        if (wasOpen && this._previousFocus && document.contains(this._previousFocus)) {
            this._previousFocus.focus();
        }
        this._previousFocus = null;
        this.current = null;
    }

    _addTransientListeners() {
        document.addEventListener('pointerdown', this._onDocPointerDown, true);
        document.addEventListener('keydown', this._onKeydown, true);
        window.addEventListener('scroll', this._onScrollOrResize, true);
        window.addEventListener('resize', this._onScrollOrResize);
    }

    _removeTransientListeners() {
        document.removeEventListener('pointerdown', this._onDocPointerDown, true);
        document.removeEventListener('keydown', this._onKeydown, true);
        window.removeEventListener('scroll', this._onScrollOrResize, true);
        window.removeEventListener('resize', this._onScrollOrResize);
    }

    _onDocPointerDown(event) {
        if (this.hasMenuTarget && this.menuTarget.contains(event.target)) return;
        this._close();
    }

    _onScrollOrResize() {
        this._close();
    }

    // ── Navigation clavier (Escape, flèches, Home/End, Enter/Espace) ──────────

    _onKeydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this._close();
            return;
        }

        const items = this._visibleItems();
        if (!items.length) return;
        const currentIndex = items.indexOf(document.activeElement);

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                items[(currentIndex + 1) % items.length].focus();
                break;
            case 'ArrowUp':
                event.preventDefault();
                items[currentIndex <= 0 ? items.length - 1 : currentIndex - 1].focus();
                break;
            case 'Home':
                event.preventDefault();
                items[0].focus();
                break;
            case 'End':
                event.preventDefault();
                items[items.length - 1].focus();
                break;
            case 'Enter':
            case ' ':
                if (currentIndex >= 0) {
                    event.preventDefault();
                    items[currentIndex].click();
                }
                break;
        }
    }

    _visibleItems() {
        if (!this.hasMenuTarget) return [];
        return Array.from(this.menuTarget.querySelectorAll('[role="menuitem"]'))
            .filter((item) => item.style.display !== 'none');
    }
}
