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
        this.buildCustomEvent("app:liste-principale:ajouter", true, true,
            {
                titre: "Nouvelle notification",
            }
        );
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR MODIFIER");
    }

    context_action_developper(event) {
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

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }
}