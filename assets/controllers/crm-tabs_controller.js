import { Controller } from '@hotwired/stimulus';

/**
 * @class CrmTabsController
 * @description Onglets de la fiche client CRM (rendus côté serveur, bascule sans
 * rechargement). Active un onglet/panneau par son `data-tab`. Sait ouvrir un
 * onglet précis via le fragment d'URL (#tab-activites) — utilisé après les
 * actions POST (redirection avec _fragment) — et via le bouton d'action rapide.
 *
 * Accessibilité : les onglets sont des <button role="tab"> focusables ; le
 * panneau visible porte la classe is-active (les autres sont masqués).
 */
export default class extends Controller {
    static targets = ['tab', 'pane'];

    connect() {
        const hash = (window.location.hash || '').replace(/^#tab-/, '');
        if (hash) {
            this.activate(hash);
        }
    }

    select(event) {
        this.activate(event.currentTarget.dataset.tab);
    }

    /** Active un onglet déclenché par un bouton externe (action rapide). */
    go(event) {
        const name = event.params.go;
        if (name) {
            this.activate(name);
            this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    activate(name) {
        let found = false;
        this.tabTargets.forEach((tab) => {
            const active = tab.dataset.tab === name;
            tab.classList.toggle('is-active', active);
            found = found || active;
        });
        if (!found) {
            return;
        }
        this.paneTargets.forEach((pane) => {
            pane.classList.toggle('is-active', pane.dataset.tab === name);
        });
    }
}
