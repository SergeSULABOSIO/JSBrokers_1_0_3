import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_COCHER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_SUPPRIMER, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_CHECK_REQUEST, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_DOUBLE_CLICKED, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_EXPANDED, EVEN_LISTE_ELEMENT_MODIFIED, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_ELEMENT_OPEN_REQUEST, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST } from './base_controller.js';

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
        this.boundHandleOpenRequest = this.handleOpenRequest.bind(this);
        this.boundHandleContextMenu = this.handleContextMenu.bind(this);

        // console.log(this.nomControleur + " - Définition des écouteurs.");
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.boundHandleCheckRequest);
        document.addEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.boundHandleChecked);
        document.addEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);
        //Pour le menu contextuel
        this.contextMenuTarget.addEventListener('contextmenu', this.boundHandleContextMenu);
    }

    disconnect() {
        // console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECK_REQUEST, this.boundHandleCheckRequest);
        document.removeEventListener(EVEN_LISTE_ELEMENT_CHECKED, this.boundHandleChecked);
        document.removeEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);
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


    handleOpenRequest(event) {
        const { selection } = event.detail;
        event.stopPropagation();
        console.log(this.nomControleur + " - Demande d'ouverture pour les éléments :", selection);
        
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


    // /**
    //  * 
    //  * @param {MouseEvent} event 
    //  */
    // action_afficher_details(event) {
    //     event.preventDefault(); // Empêche la soumission classique du formulaire
    //     event.stopPropagation();
    //     if (this.isShownValue == true) {
    //         this.detailsTarget.style.display = "none";
    //         this.isShownValue = false;
    //     } else {
    //         this.detailsTarget.style.display = "block";
    //         this.isShownValue = true;
    //     }

    //     buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_EXPANDED, true, true, {
    //         expandedCheckBox: this.idobjetValue,
    //         selection: event.detail.selection,
    //     });
    // }

    /**
     * Emet un événement personnalisé sur le document lorsqu'une ligne est
     * double-cliquée, en propageant les données de l'objet.
     * @param {Event} event L'événement de double-clic.
     */
    // dispatchDoubleClick(event) {
    //     // Empêche le double-clic de déclencher aussi l'événement de simple clic (sélection)
    //     event.preventDefault();
    //     event.stopPropagation();

    //     if (!this.hasObjetValue) {
    //         console.error("L'objet n'a pas été passé au contrôleur Stimulus 'liste-element'.");
    //         return;
    //     }

    //     buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DOUBLE_CLICKED, true, true,{objet: this.objetValue});
    //     console.log(`Événement 'app:liste-principale:elément-double-clicked' émis pour l'objet ID: ${this.idobjetValue}`, this.objetValue);
    // }
}