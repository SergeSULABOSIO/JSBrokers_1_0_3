import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'texteprincipal',
        'textesecondaire',
        'details',
    ];

    static values = {
        idobjet: Number,
    };

    connect() {
        this.detailsVisible = false;
        this.listePrincipale = document.getElementById("liste");
    }


    action_selectionner() {
        console.log("Action_séléctionner ", this.idobjetValue);
        this.buildCustomEvent(
            "app:liste-principale:selectionner",
            true,
            true,
            {
                idobjet: this.idobjetValue,
            }
        );
    }

    action_supprimer() {
        console.log("Action_supprimer ", this.idobjetValue);
        this.action_selectionner();
        this.buildCustomEvent("app:liste-principale:supprimer", 
            true, 
            true,
            {
                titre: "Suppression",
            }
        );
    }

    action_modifier() {
        console.log("Action_modifier ", this.idobjetValue);
        this.action_selectionner();
        this.buildCustomEvent("app:liste-principale:modifier", 
            true, 
            true,
            {
                titre: "Modification",
            }
        );
    }

    action_cocher(){
        console.log("Action_cocher ", this.idobjetValue);
        this.buildCustomEvent(
            "app:liste-principale:cocher",
            true,
            true,
            {
                idobjet: this.idobjetValue,
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


    /**
     * 
     * @param {MouseEvent} event 
     */
    action_afficher_details = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        if (this.detailsVisible == true) {
            this.detailsTarget.style.display = "none";
            this.detailsVisible = false;
        } else {
            this.detailsTarget.style.display = "block";
            this.detailsVisible = true;
        }
    }
}