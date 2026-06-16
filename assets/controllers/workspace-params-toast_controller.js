// assets/controllers/workspace-params-toast_controller.js
import { Controller } from '@hotwired/stimulus';

/**
 * Toast permanent « Paramètres de l'espace de travail ».
 * Clone le toast (contenu du <template>) en TÊTE du conteneur global des toasts
 * (notification-manager, défini dans base.html.twig). Comme les toasts dynamiques
 * y sont ajoutés en `beforeend`, ils s'empilent juste EN DESSOUS du toast permanent.
 *
 * L'élément du contrôleur lui-même n'est jamais déplacé : on insère un CLONE.
 * Cela évite tout cycle disconnect/connect parasite (qui détruirait le toast).
 */
export default class extends Controller {
    static targets = ['template'];

    connect() {
        this.container = document.querySelector('[data-controller~="notification-manager"]');
        if (!this.container || !this.hasTemplateTarget) return;

        // Descendre le conteneur sous la barre de titre (.ent-topbar ≈ 60px).
        // L'utilitaire Bootstrap .top-0 vaut `top: 0 !important` : il faut donc
        // poser notre valeur en `important` pour qu'elle prenne le dessus.
        this.container.style.setProperty('top', '70px', 'important');

        // Insérer le clone du toast en première position du conteneur.
        this.toastEl = this.templateTarget.content.firstElementChild.cloneNode(true);
        this.container.prepend(this.toastEl);
    }

    disconnect() {
        // Retirer le clone et restaurer la position d'origine du conteneur
        // (retrait de notre top inline → retour au .top-0 de Bootstrap).
        if (this.toastEl) this.toastEl.remove();
        if (this.container) this.container.style.removeProperty('top');
    }
}
