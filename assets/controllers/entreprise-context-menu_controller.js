import { Controller } from '@hotwired/stimulus';
import { MenuContextuel } from './menu-contextuel.js';

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
 *
 * Ouverture, clavier et fermetures : la coquille commune (menu-contextuel.js, chantier 3).
 */
export default class extends Controller {
    static targets = ['menu', 'editLabel', 'deleteLabel'];
    // Libellés traduits (FR/EN) fournis par le template, avec le placeholder
    // %name% remplacé par le nom de l'entreprise. Évite tout texte codé en dur.
    static values = { editTemplate: String, deleteTemplate: String };

    connect() {
        this.current = null;
        this.coquille = new MenuContextuel(this.menuTarget, { surFermeture: () => { this.current = null; } });

        this._onContextMenu = this._onContextMenu.bind(this);
        this.element.addEventListener('contextmenu', this._onContextMenu);
    }

    disconnect() {
        this._close();
        this.element.removeEventListener('contextmenu', this._onContextMenu);
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
        // Le raccourci vers le GUIDE DE DÉMARRAGE n'existe sur la carte que tant qu'il
        // reste quelque chose à configurer : l'option disparaît donc d'elle-même une fois
        // le cabinet en ordre, sans condition à maintenir ici.
        const demarrageLink = card.querySelector('.ent-demarrage-link');
        // `:not()` écarte le raccourci ci-dessus : sans cela, « Éditer » aurait pointé
        // vers le guide dès que celui-ci est présent, puisqu'il vient en premier.
        const editLink = card.querySelector('.ent-actions a:not(.ent-demarrage-link):not(.ent-archive-link)');
        // L'archive comptable, à télécharger avant toute suppression (pièces à conserver dix ans, OHADA).
        const archiveLink = card.querySelector('.ent-archive-form');
        const deleteForm = card.querySelector('.ent-actions form:not(.ent-archive-form)');
        if (!workspaceLink && !demarrageLink && !editLink && !deleteForm) return; // carte non actionnable → menu natif

        event.preventDefault();

        const nom = card.dataset.ecNom || "l'entreprise";

        if (this.hasEditLabelTarget) this.editLabelTarget.textContent = this._labelFor('edit', nom);
        if (this.hasDeleteLabelTarget) this.deleteLabelTarget.textContent = this._labelFor('delete', nom);

        const menu = this.menuTarget;
        // Affiche/masque chaque option selon ce que la carte permet réellement.
        menu.querySelectorAll('[role="menuitem"]').forEach((item) => {
            const key = item.dataset.menuKey;
            const allowed = (key === 'workspace' && workspaceLink)
                || (key === 'demarrage' && demarrageLink)
                || (key === 'edit' && editLink)
                || (key === 'archive' && archiveLink)
                || (key === 'delete' && deleteForm);
            item.style.display = allowed ? '' : 'none';
        });
        // Le séparateur n'a de sens que si l'option destructrice « Supprimer »
        // est précédée d'au moins une autre option.
        const sep = menu.querySelector('[data-menu-key="sep"]');
        if (sep) sep.style.display = (deleteForm && (workspaceLink || demarrageLink || editLink)) ? '' : 'none';

        this.coquille.ouvrir({ point: { x: event.clientX, y: event.clientY } });
        // Après l'ouverture : la coquille vide `current` à chaque fermeture.
        this.current = { workspaceLink, demarrageLink, editLink, archiveLink, deleteForm };
    }

    // Libellé localisé « Éditer/Supprimer {nom} » : on injecte le nom dans le
    // gabarit traduit fourni par le template (placeholder %name%). Repli sobre
    // sur le seul nom si le gabarit n'est pas fourni.
    _labelFor(kind, nom) {
        const template = kind === 'edit' ? this.editTemplateValue : this.deleteTemplateValue;
        return template ? template.replace('%name%', nom) : nom;
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
        } else if (key === 'demarrage' && current.demarrageLink) {
            // Même patron : on rejoue le lien caché de la carte, qui porte déjà
            // `?onboarding=1` — le guide s'ouvre donc de lui-même à l'arrivée.
            current.demarrageLink.click();
        } else if (key === 'edit' && current.editLink) {
            // Réutilise le lien existant (navigation + barre de progression).
            current.editLink.click();
        } else if (key === 'archive' && current.archiveLink) {
            // Un téléchargement (POST, jeton) : le formulaire caché de la carte fait le travail.
            current.archiveLink.submit();
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
        if (this.coquille) this.coquille.fermer();
    }
}
