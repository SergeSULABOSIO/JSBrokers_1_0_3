import { Controller } from '@hotwired/stimulus';

/**
 * Ce contrôleur surveille la largeur de son propre élément (une cellule <td>).
 * Il stocke sa largeur initiale, et si sa largeur actuelle devient inférieure
 * aux 2/3 de sa largeur initiale, il cache un élément cible (les infos secondaires).
 * Il utilise un ResizeObserver pour une performance optimale.
 */
export default class extends Controller {
    // On déclare une cible "secondaryInfo" qui correspondra à la 2ème ligne
    static targets = ["secondaryInfo"];

    connect() {
        // --- 1. Stocker la largeur initiale ---
        // Lorsque le contrôleur se connecte, on mesure et mémorise la largeur de la cellule.
        this.initialWidth = this.element.offsetWidth;

        // --- 2. Mettre en place un observateur de redimensionnement ---
        // C'est plus performant que d'écouter l'événement "resize" sur la fenêtre,
        // car il ne se déclenche que si CET élément spécifique change de taille.
        this.resizeObserver = new ResizeObserver(() => this.checkWidth());
        this.resizeObserver.observe(this.element);

        // --- 3. Vérifier la largeur une première fois au chargement ---
        this.checkWidth();
    }

    disconnect() {
        // --- 4. Nettoyer l'observateur ---
        // C'est une bonne pratique pour éviter les fuites de mémoire si l'élément est retiré du DOM.
        this.resizeObserver.disconnect();
    }

    /**
     * La logique principale : compare la largeur actuelle à la largeur initiale.
     */
    checkWidth() {
        const currentWidth = this.element.offsetWidth;
        const threshold = (2 / 3) * this.initialWidth; // Le seuil de 2/3

        // Si la cible "secondaryInfo" n'existe pas, on ne fait rien.
        if (!this.hasSecondaryInfoTarget) {
            return;
        }

        // --- 5. Appliquer ou retirer la classe CSS ---
        if (currentWidth < threshold) {
            // La largeur est trop petite, on cache les infos secondaires
            this.secondaryInfoTarget.classList.add('d-none');
        } else {
            // La largeur est suffisante, on affiche les infos secondaires
            this.secondaryInfoTarget.classList.remove('d-none');
        }
    }
}