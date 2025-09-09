// Fichier : assets/controllers/tooltip-manager_controller.js

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {

    connect() {
        this.nomControlleur = "TOOLTIP MANAGER";
        // AJOUTEZ CETTE LIGNE POUR LE DÉBOGAGE
        console.log(this.nomControlleur + " - Tooltip Manager connecté à l'élément :", this.element);

        this.tooltipElement = null;
        this.hideTimer = null;

        console.log(this.nomControlleur + " - CONNEXION : Le contrôleur tooltip est bien connecté à cet élément :", this.element);

        // LIGNE CRUCIALE : C'est ici que l'écouteur est ajouté.
        // 'addEventListener' est la fonction JavaScript qui écoute un événement.
        // 'mouseenter' est l'événement du survol de la souris.
        // 'this.show.bind(this)' est l'action à exécuter : appeler la méthode 'show' de ce contrôleur.
        this.element.addEventListener('mouseenter', this.show.bind(this));
        this.element.addEventListener('mouseleave', this.hide.bind(this));

        console.log(this.nomControlleur + " - ÉCOUTEUR EN PLACE : L'écouteur de survol a été ajouté.");
    }

    show(event) {
        // AJOUTEZ CETTE LIGNE EN PREMIER
        console.log(this.nomControlleur + " - SURVOL DÉTECTÉ : La méthode show() a été déclenchée !");
        // MODIFICATION: La première chose à faire est d'annuler toute disparition programmée.
        // C'est ce qui corrige la "race condition".
        clearTimeout(this.hideTimer);

        const content = event.currentTarget.dataset.tooltipContent;
        if (!content) return;

        // MODIFICATION: On vérifie si une infobulle existe déjà. Si non, on la crée.
        // Cela évite de créer des dizaines d'éléments si la souris bouge vite.
        if (!this.tooltipElement) {
            this.tooltipElement = document.createElement('div');
            this.tooltipElement.className = 'canvas-tooltip';
            document.body.appendChild(this.tooltipElement);
        }

        // On met à jour le contenu et la position
        this.tooltipElement.innerHTML = content;

        // document.body.appendChild(this.tooltipElement);

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

        // On s'assure que la classe 'is-visible' est bien présente
        this.tooltipElement.classList.add('is-visible');

        // Lancer l'animation d'apparition
        // requestAnimationFrame(() => {
        //     this.tooltipElement.classList.add('is-visible');
        // });
    }

    hide() {
        // MODIFICATION: On n'utilise plus 'transitionend' mais un simple timer,
        // c'est plus fiable et ça correspond à la durée de notre animation CSS.
        this.hideTimer = setTimeout(() => {
            if (this.tooltipElement) {
                this.tooltipElement.remove();
                this.tooltipElement = null;
            }
        }, 200); // Doit correspondre à la durée de la transition en CSS (ex: 0.2s)
    }

    disconnect() {
        // MODIFICATION: Bonne pratique de nettoyer en cas de déconnexion du contrôleur.
        clearTimeout(this.hideTimer);
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
    }
}