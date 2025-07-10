import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'menu',
    ];


    connect() {
        this.tabSelectedCheckBoxs = [];
        this.app_liste_principale_selection = "app:liste-principale:selection";
        this.app_menu_contextuel_click = "click";
        this.app_liste_principale_cocher = "app:liste-principale:cocher";
        this.app_liste_element_developper = "app:liste-element:developper";

        this.listePrincipale = document.getElementById("liste");
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
        this.menu.addEventListener(this.app_menu_contextuel_click, (e) => e.stopPropagation());
        this.listePrincipale.addEventListener(this.app_liste_principale_selection, this.handleItemSelection.bind(this));
        this.listePrincipale.addEventListener(this.app_liste_principale_cocher, this.handleItemCoche.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.menu.removeEventListener(this.app_menu_contextuel_click, (e) => e.stopPropagation());
        this.listePrincipale.removeEventListener(this.app_liste_principale_selection, this.handleItemSelection.bind(this));
        this.listePrincipale.removeEventListener(this.app_liste_principale_cocher, this.handleItemCoche.bind(this));
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSelection(event) {
        const { titre, idobjet, isChecked, selectedCheckbox } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - ELEMENT SELECTIONNE: " + titre, "ID Objet: " + idobjet, "Checked: " + isChecked, "Selected Check Box: " + selectedCheckbox);

        let currentSelectedCheckBoxes = new Set(this.tabSelectedCheckBoxs);
        if (isChecked == true) {
            currentSelectedCheckBoxes.add(String(selectedCheckbox));
        } else {
            currentSelectedCheckBoxes.delete(String(selectedCheckbox));
        }
        this.tabSelectedCheckBoxs = Array.from(currentSelectedCheckBoxes);
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
        event.stopPropagation();
    }


    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCoche(event) {
        const { idCheckBox } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - ELEMENT COCHE. [id.=" + idCheckBox.split("check_")[1] + "]", idCheckBox);
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedCheckBoxs.push(idCheckBox);
        event.stopPropagation();
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
        this.buildCustomEvent("app:liste-principale:modifier", true, true,
            {
                titre: "Modification de la notification",
            }
        );
    }

    context_action_developper(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR DEVELOPPER ID: " + this.tabSelectedCheckBoxs);
        let listElement = document.getElementById("liste_row_" + this.tabSelectedCheckBoxs[0].split("check_")[1]);
        console.log("Element: ", listElement);
        this.buildCustomEventForElement(listElement, this.app_liste_element_developper, true, true,
            {}
        );
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR TOUT COCHER");
        this.buildCustomEvent(
            "app:liste-principale:tout_cocher", true, true,
            {}
        );
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR ACTUALISER");
        this.buildCustomEvent(
            "app:liste-principale:recharger", true, true,
            {}
        );
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR SUPPRIMER");
        this.buildCustomEvent("app:liste-principale:supprimer", true, true,
            {
                titre: "Suppression ",
            }
        );
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR PARAMETRER");
        this.buildCustomEvent(
            "app:liste-principale:parametrer", true, true,
            {}
        );
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log("CLIC SUR QUITTER");
        this.buildCustomEvent(
            "app:liste-principale:quitter", true, true,
            {}
        );
    }

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }

    buildCustomEventForElement(htmlElement, nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        htmlElement.dispatchEvent(event);
    }
}