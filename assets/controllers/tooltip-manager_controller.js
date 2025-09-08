// Fichier : assets/controllers/tooltip-manager_controller.js

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    
    connect() {
        this.tooltipElement = null;
    }

    show(event) {
        const content = event.currentTarget.dataset.tooltipContent;
        if (!content) return;

        // Créer l'élément de l'infobulle
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'canvas-tooltip';
        this.tooltipElement.innerHTML = content;
        document.body.appendChild(this.tooltipElement);
        
        // Positionner l'infobulle
        const targetRect = event.currentTarget.getBoundingClientRect();
        const tooltipRect = this.tooltipElement.getBoundingClientRect();

        let top = window.scrollY + targetRect.top + (targetRect.height / 2) - (tooltipRect.height / 2);
        let left = targetRect.right + 10;
        
        if (left + tooltipRect.width > window.innerWidth) {
            left = targetRect.left - tooltipRect.width - 10;
        }
        
        this.tooltipElement.style.left = `${left}px`;
        this.tooltipElement.style.top = `${top}px`;
        
        // Lancer l'animation d'apparition
        requestAnimationFrame(() => {
            this.tooltipElement.classList.add('is-visible');
        });
    }

    hide() {
        if (this.tooltipElement) {
            this.tooltipElement.classList.remove('is-visible');
            this.tooltipElement.addEventListener('transitionend', () => {
                this.tooltipElement?.remove();
                this.tooltipElement = null;
            }, { once: true });
        }
    }
}