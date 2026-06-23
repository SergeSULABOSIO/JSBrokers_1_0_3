import { Controller } from '@hotwired/stimulus';

/**
 * Bascule entre plusieurs vues (« panes ») d'un même bloc graphique, sur le
 * modèle du bloc « Production » du tableau de bord courtiers : des boutons
 * segmentés affichent un volet et masquent les autres. La vue active est
 * mémorisée et ré-appliquée automatiquement après chaque rafraîchissement du
 * contenu (les volets rechargés se reconnectent via paneTargetConnected).
 *
 * Cibles  : tab (boutons), pane (volets). Chacun porte data-mode.
 * Valeur  : active (clé de la vue affichée).
 */
export default class extends Controller {
    static targets = ['tab', 'pane'];
    static values = { active: String };

    switch(event) {
        const mode = event.currentTarget.dataset.mode;
        if (mode) this.activeValue = mode;
    }

    activeValueChanged() { this._apply(); }

    paneTargetConnected() { this._apply(); }

    _apply() {
        const mode = this.activeValue;
        this.paneTargets.forEach(pane => {
            pane.style.display = (pane.dataset.mode === mode) ? '' : 'none';
        });
        this.tabTargets.forEach(tab => {
            const on = tab.dataset.mode === mode;
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
        // Nudge les graphiques responsifs (Chart.js) à se redimensionner une
        // fois leur volet rendu visible.
        window.dispatchEvent(new Event('resize'));
    }
}
