import { Controller } from '@hotwired/stimulus';

/**
 * @class TooltipController
 * @extends Controller
 * @description Gère l'affichage d'une infobulle personnalisée au survol d'un élément.
 * L'élément doit avoir un attribut `data-tooltip-content` pour que l'infobulle s'affiche.
 */
export default class extends Controller {

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     * Initialise les propriétés et met en place les écouteurs d'événements.
     */
    connect() {
        /**
         * @property {HTMLElement|null} tooltipElement - L'élément DOM de l'infobulle.
         * @private
         */
        this.tooltipElement = null;
        /**
         * @property {number|null} hideTimer - L'ID du timer pour le masquage différé.
         * @private
         */
        this.hideTimer = null;

        // --- CORRECTION : Lier les méthodes une seule fois pour un nettoyage correct ---
        this.boundShow = this.show.bind(this);
        this.boundHide = this.hide.bind(this);

        this.element.addEventListener('mouseenter', this.boundShow);
        this.element.addEventListener('mouseleave', this.boundHide);
    }

    /**
     * Affiche et positionne l'infobulle.
     * @param {MouseEvent} event - L'événement de survol.
     */
    show(event) {
        clearTimeout(this.hideTimer);

        const content = event.currentTarget.dataset.tooltipContent;
        if (!content) return;

        if (!this.tooltipElement) {
            this.tooltipElement = document.createElement('div');
            this.tooltipElement.className = 'canvas-tooltip';
            document.body.appendChild(this.tooltipElement);
        }

        this.tooltipElement.innerHTML = content;
        const targetRect = event.currentTarget.getBoundingClientRect();
        const tooltipRect = this.tooltipElement.getBoundingClientRect();

        let top = window.scrollY + targetRect.top + (targetRect.height / 2) - (tooltipRect.height / 2);
        let left = targetRect.right + 10;

        if (left + tooltipRect.width > window.innerWidth) {
            left = targetRect.left - tooltipRect.width - 10;
        }

        this.tooltipElement.style.left = `${left}px`;
        this.tooltipElement.style.top = `${top}px`;
        this.tooltipElement.classList.add('is-visible');
    }

    /**
     * Masque et détruit l'infobulle après un court délai.
     */
    hide() {
        this.hideTimer = setTimeout(() => {
            if (this.tooltipElement) {
                this.tooltipElement.remove();
                this.tooltipElement = null;
            }
        }, 200); // Doit correspondre à la durée de la transition CSS (ex: 0.2s)
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs et les éléments pour éviter les fuites de mémoire.
     */
    disconnect() {
        clearTimeout(this.hideTimer);
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
        this.element.removeEventListener('mouseenter', this.boundShow);
        this.element.removeEventListener('mouseleave', this.boundHide);
    }
}