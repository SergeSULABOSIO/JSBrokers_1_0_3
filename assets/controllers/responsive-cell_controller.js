import { Controller } from '@hotwired/stimulus';

/**
 * @class ResponsiveCellController
 * @extends Controller
 * @description Gère la visibilité du contenu d'une cellule en fonction de sa largeur.
 * Ce contrôleur surveille la largeur de son propre élément (une cellule <td>).
 * Si la largeur actuelle devient inférieure à un seuil (2/3 de la largeur initiale),
 * il cache un élément cible (les informations secondaires) en lui ajoutant une classe CSS.
 * Il utilise un ResizeObserver pour une performance optimale.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} secondaryInfoTargets - La cible contenant les informations secondaires à masquer.
     */
    static targets = ["secondaryInfo"];

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     * Mémorise la largeur initiale et met en place l'observateur de redimensionnement.
     */
    connect() {
        /**
         * @property {number} initialWidth - La largeur initiale de la cellule en pixels.
         * @private
         */
        this.initialWidth = this.element.offsetWidth;

        /**
         * @property {ResizeObserver} resizeObserver - L'observateur qui surveille les changements de taille de l'élément.
         * @private
         */
        this.resizeObserver = new ResizeObserver(() => this.checkWidth());
        this.resizeObserver.observe(this.element);

        // Vérifie la largeur une première fois au chargement.
        this.checkWidth();
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est déconnecté du DOM.
     * Nettoie l'observateur pour éviter les fuites de mémoire.
     */
    disconnect() {
        this.resizeObserver.disconnect();
    }

    /**
     * Compare la largeur actuelle de la cellule à un seuil basé sur sa largeur initiale
     * et bascule la visibilité de la cible `secondaryInfoTarget` en conséquence.
     * @private
     */
    checkWidth() {
        const currentWidth = this.element.offsetWidth;
        const threshold = 0.9 * this.initialWidth; // Le seuil de 90%

        // Garde-fou : si la cible n'existe pas, on ne fait rien.
        if (!this.hasSecondaryInfoTarget) {
            return;
        }

        // Applique ou retire la classe CSS 'd-none' de Bootstrap.
        if (currentWidth < threshold) {
            // La largeur est trop petite, on cache les infos secondaires
            this.secondaryInfoTarget.classList.add('d-none');
        } else {
            // La largeur est suffisante, on affiche les infos secondaires
            this.secondaryInfoTarget.classList.remove('d-none');
        }
    }
}