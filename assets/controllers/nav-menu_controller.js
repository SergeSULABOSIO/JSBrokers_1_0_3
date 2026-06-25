import { Controller } from '@hotwired/stimulus';

/**
 * @class NavMenuController
 * @extends Controller
 * @description Pilote les menus déroulants de la navigation de la Console, bâtis
 * sur des <details>/<summary> natifs (état accessible exposé nativement aux
 * technologies d'assistance). Ajoute les comportements attendus d'un menu :
 *  - un seul menu ouvert à la fois (ouvrir l'un referme les autres) ;
 *  - fermeture au clic en dehors de la navigation ;
 *  - fermeture par la touche Échap, avec retour du focus sur le déclencheur.
 *
 * Ergonomie : contrôle explicite et liberté (Bastien & Scapin ; Nielsen #3).
 * Accessibilité : <summary> est focusable et annonce l'état ouvert/fermé (WCAG
 * 4.1.2) ; Échap referme (WCAG 2.1.2, pas de piège clavier).
 *
 * Markup attendu :
 *   <nav data-controller="nav-menu">
 *     <details data-nav-menu-target="menu" data-action="toggle->nav-menu#opened">
 *       <summary>…</summary>
 *       <div class="cs-menu-panel">…liens…</div>
 *     </details>
 *   </nav>
 */
export default class extends Controller {
    static targets = ['menu'];

    connect() {
        this._onDocClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.closeAll();
            }
        };
        this._onKeydown = (event) => {
            if (event.key === 'Escape') {
                this.closeAll(true);
            }
        };
        document.addEventListener('click', this._onDocClick);
        this.element.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        document.removeEventListener('click', this._onDocClick);
        this.element.removeEventListener('keydown', this._onKeydown);
    }

    /** À l'ouverture d'un menu, referme tous les autres (un seul ouvert à la fois). */
    opened(event) {
        if (!event.target.open) {
            return;
        }
        this.menuTargets.forEach((menu) => {
            if (menu !== event.target) {
                menu.open = false;
            }
        });
    }

    /** Referme tous les menus ; rend le focus au déclencheur si demandé (Échap). */
    closeAll(focusTrigger = false) {
        this.menuTargets.forEach((menu) => {
            if (menu.open) {
                menu.open = false;
                if (focusTrigger) {
                    menu.querySelector('summary')?.focus();
                }
            }
        });
    }
}
