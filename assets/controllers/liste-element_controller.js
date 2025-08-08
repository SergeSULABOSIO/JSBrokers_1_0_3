import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_COCHER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_SUPPRIMER, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_CHECK_REQUEST, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_MENU_CONTEXTUEL_INIT_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
    ];

    static values = {
        idobjet: Number,
        isShown: Boolean,
        objet: Object // Nous ajoutons cette valeur pour récupérer l'objet complet
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
        this.boundHandleCheckRequest = this.handleCheckRequest.bind(this);
        this.boundHandleChecked = this.handleChecked.bind(this);
        this.boundHandleContextMenu = this.handleContextMenu.bind(this);

        // console.log(this.nomControleur + " - Définition des écouteurs.");
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.boundHandleCheckRequest);
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.boundHandleChecked);
        //Pour le menu contextuel
        this.contextMenuTarget.addEventListener('contextmenu', this.boundHandleContextMenu);
    }

    disconnect() {
        // console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.boundHandleCheckRequest);
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.boundHandleChecked);
        this.contextMenuTarget.removeEventListener('contextmenu', this.boundHandleContextMenu);
    }


    handleContextMenu(event) {
        event.preventDefault();
        event.stopPropagation();
        console.log(this.nomControleur + " - HandleContextMenu");
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, true, true, {
            idObjet: this.idobjetValue,
            menuX: event.clientX,
            menuY: event.clientY,
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
        let checkBx = document.getElementById("check_" + this.idobjetValue);
        checkBx.checked = !checkBx.checked;
        buildCustomEventForElement(document, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, true, true, { 
            selectedCheckbox: this.idobjetValue,
            isChecked: checkBx.checked,
         });
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_supprimer ", this.idobjetValue);
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETE_REQUEST, true, true, {
            titre: "Suppression",
            action: EVEN_CODE_ACTION_SUPPRESSION,
            selection: [this.idobjetValue],
        });
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_modifier ", this.idobjetValue);
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, true, true, {
            titre: "Modification",
            action: EVEN_CODE_ACTION_MODIFICATION,
            selectedId: this.idobjetValue,
        });
    }
}