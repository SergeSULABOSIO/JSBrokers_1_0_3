import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'menu',
    ];


    connect() {
        this.nomControleur = "LISTE-ELEMENT-CONTEXT-MENU";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.menu = this.menuTarget; // Le menu HTML
        this.listePrincipale = document.getElementById("liste");
        this.setEcouteurs();
    }

    setEcouteurs() {
        // Pour éviter que le clic sur une option du menu ne le cache immédiatement (stopPropagation)
        this.menu.addEventListener('click', (e) => e.stopPropagation());
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.menu.removeEventListener('click', (e) => e.stopPropagation());
    }

    // /**
    //  * Gère l'événement de clic droit sur l'élément cible.
    //  */
    // handleContextMenu(event) {
    //     console.log("JE SUIS ICI....");
    //     event.preventDefault(); // Empêche le menu contextuel natif du navigateur d'apparaître

    //     console.log("OUVERTURE DU MENU CONTEXTUEL.");
    //     this.menu.style.display = 'block'; // Affiche le menu

    //     // Positionne le menu près du curseur de la souris, en s'assurant qu'il reste dans la fenêtre
    //     let menuX = event.clientX;
    //     let menuY = event.clientY;

    //     const menuWidth = this.menu.offsetWidth;
    //     const menuHeight = this.menu.offsetHeight;
    //     const windowWidth = window.innerWidth;
    //     const windowHeight = window.innerHeight;

    //     if (menuX + menuWidth > windowWidth) {
    //         menuX = windowWidth - menuWidth - 5; // Décale à gauche si trop à droite
    //     }
    //     if (menuY + menuHeight > windowHeight) {
    //         menuY = windowHeight - menuHeight - 5; // Décale vers le haut si trop en bas
    //     }

    //     this.menu.style.left = menuX + "px";
    //     this.menu.style.top = menuY + "px";

    //     console.log("Left: " + this.menu.style.left, "Top: " + this.menu.style.top);
    // }

    /**
     * Cache le menu contextuel.
     */
    hideMenu() {
        this.menu.style.display = 'none';
        console.log("FERMETURE DU MENU CONTEXTUEL.");
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR AJOUTER");
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR MODIFIER");
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR DEVELOPPER");
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR TOUT COCHER");
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR ACTUALISER");
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR SUPPRIMER");
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR PARAMETRER");
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR QUITTER");
    }
}