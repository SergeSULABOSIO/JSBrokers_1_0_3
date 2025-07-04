import { Controller } from '@hotwired/stimulus';

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

    disconnect(){
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs - ID: " + this.name);
        
    }


    action_cocher(event) {
        this.isChecked = event.target.checked;
        // event.preventDefault();
        console.log(this.nomControleur + " - Déclanchement - Action_cocher ID: " + this.idobjetValue, "Etat actuel: " + this.isChecked);
        this.buildCustomEvent("app:liste-principale:selection", 
            true, 
            true,
            {
                titre: "Sélection - checkbox",
                idobjet: this.idobjetValue,
                selectedCheckbox: "check_" + this.idobjetValue, 
                isChecked: this.isChecked,
            }
        );
    }

    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble,
            composed: canCompose,
            detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }
}