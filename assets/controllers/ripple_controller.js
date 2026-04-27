import { Controller } from '@hotwired/stimulus';

/**
 * @class RippleController
 * @extends Controller
 * @description Ajoute un effet d'onde (ripple) au clic sur l'élément auquel il est attaché.
 * L'élément doit avoir un style `position: relative` et `overflow: hidden`.
 */
export default class extends Controller {
    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.boundCreateRipple = this.createRipple.bind(this);
        this.element.addEventListener('click', this.boundCreateRipple);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie l'écouteur d'événement.
     */
    disconnect() {
        this.element.removeEventListener('click', this.boundCreateRipple);
    }

    /**
     * Crée et anime l'élément de l'onde.
     * @param {MouseEvent} event
     */
    createRipple(event) {
        const button = this.element;
        const circle = document.createElement('span');
        const diameter = Math.max(button.clientWidth, button.clientHeight);
        const radius = diameter / 2;

        circle.style.width = circle.style.height = `${diameter}px`;
        circle.style.left = `${event.clientX - button.getBoundingClientRect().left - radius}px`;
        circle.style.top = `${event.clientY - button.getBoundingClientRect().top - radius}px`;
        circle.classList.add('ripple');

        // Supprime l'élément après l'animation pour garder le DOM propre
        circle.addEventListener('animationend', () => circle.remove());

        button.appendChild(circle);
    }
}