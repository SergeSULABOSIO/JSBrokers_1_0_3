// Fichier : assets/controllers/tooltip-manager_controller.js

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    connect() {
        this.nomControlleur = "TOOLTIP MANAGER";
        console.log(this.nomControlleur + " - Tooltip Manager connecté à l'élément :", this.element);

        this.tooltipElement = null;
        this.hideTimer = null;

        console.log(this.nomControlleur + " - CONNEXION : Le contrôleur tooltip est bien connecté à cet élément :", this.element);

        this.element.addEventListener('mouseenter', this.show.bind(this));
        this.element.addEventListener('mouseleave', this.hide.bind(this));

        console.log(this.nomControlleur + " - ÉCOUTEUR EN PLACE : L'écouteur de survol a été ajouté.");
    }

    show(event) {
        console.log(this.nomControlleur + " - SURVOL DÉTECTÉ : La méthode show() a été déclenchée !");
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

    hide() {
        this.hideTimer = setTimeout(() => {
            if (this.tooltipElement) {
                this.tooltipElement.remove();
                this.tooltipElement = null;
            }
        }, 200); // Doit correspondre à la durée de la transition en CSS (ex: 0.2s)
    }

    disconnect() {
        clearTimeout(this.hideTimer);
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
    }
}