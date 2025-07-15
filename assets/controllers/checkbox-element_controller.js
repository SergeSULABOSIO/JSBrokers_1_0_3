import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST } from './base_controller.js';

export default class extends Controller {
    static values = {
        idobjet: Number,
    };

    connect() {
        this.isChecked = false;
        this.name = "ckbx_" + this.idobjetValue;
        this.nomControleur = "CHECKBOX-ELEMENT";
        console.log(this.nomControleur + " - Connecté à l'élement ID " + this.name);
        this.listePrincipale = document.getElementById("liste");
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs - ID: " + this.name);
    }


    action_cocher(event) {
        this.isChecked = event.target.checked;
        event.stopPropagation();
        event.preventDefault();
        buildCustomEventForElement(document, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, true, true, {
            selectedCheckbox: this.idobjetValue,
            isChecked: this.isChecked,
        });
    }
}