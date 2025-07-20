import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_COCHER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_SUPPRIMER, EVEN_LISTE_ELEMENT_CHECK_REQUEST, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_EXPANDED, EVEN_LISTE_ELEMENT_MODIFIED, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
    ];

    static values = {
        idobjet: Number,
        isShown: Boolean,
    };

    connect() {
        this.nomControleur = "LISTE-ELEMENT";
        // console.log(this.nomControleur + " - Connecté");
        this.detailsVisible = false;
        this.listePrincipale = document.getElementById("liste");
        this.contextMenuTarget = document.getElementById("liste_row_" + this.idobjetValue);
        // this.menu = document.getElementById("simpleContextMenu");
        this.setEcouteurs();
    }

    setEcouteurs() {
        console.log(this.nomControleur + " - Définition des écouteurs.");
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_EXPAND_REQUEST, this.handleExpandRequest.bind(this));
        //Pour le menu contextuel
        this.contextMenuTarget.addEventListener('contextmenu', this.boundHandleContextMenu.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_EXPAND_REQUEST, this.handleExpandRequest.bind(this));
        //Pour le menu contextuel
        this.contextMenuTarget.removeEventListener('contextmenu', this.boundHandleContextMenu.bind(this));
    }


    boundHandleContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();
        console.log(this.nomControleur + " - boundHandleContextMenu");
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, true, true, {
            idObjet: this.idobjetValue,
            menuX: event.clientX,
            menuY: event.clientY,
        });
    }


    handleExpandRequest(event) {
        const { selection } = event.detail;
        event.stopPropagation();
        selection.forEach(selectedId => {
            // console.log(this.nomControleur + "IdObjet: " + this.idobjetValue + " = Selected ID: " + selectedId + " " + (selectedId == this.idobjetValue));
            if (this.idobjetValue == selectedId) {
                this.action_afficher_details(event);
                // console.log(this.nomControleur + " - HandleExpandRequest", event.detail, this.idobjetValue, this.isShownValue);
            }
        });
    }

    handleCheckRequest(event) {
        console.log(this.nomControleur + " - HandleCheckRequest");
    }

    handleChecked(event) {
        console.log(this.nomControleur + " - HandleChecked");
    }


    action_cocher(event) {
        console.log(this.nomControleur + " - Action_cocher ", "check_" + this.idobjetValue);
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_COCHER, true, true, { idCheckBox: "check_" + this.idobjetValue });
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_supprimer ", this.idobjetValue);
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_SUPPRIMER, true, true, { titre: "Suppression" });
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_modifier ", this.idobjetValue);
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_MODIFIER, true, true, { titre: "Modification" });
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    action_afficher_details(event) {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.stopPropagation();
        if (this.isShownValue == true) {
            this.detailsTarget.style.display = "none";
            this.isShownValue = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.isShownValue = true;
        }

        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_EXPANDED, true, true, {
            expandedCheckBox: this.idobjetValue,
            selection: event.detail.selection,
        });
    }
}