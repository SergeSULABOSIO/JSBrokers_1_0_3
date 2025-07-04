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
        this.nomControleur = "LISTE-ELEMENT";
        console.log(this.nomControleur + " - Connecté");
        this.detailsVisible = false;
        this.listePrincipale = document.getElementById("liste");
    }

    disconnect(){
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        
    }

    action_cocher(){
        console.log(this.nomControleur + " - Action_cocher ", "check_" + this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:cocher", 
            true, 
            true,
            {
                idCheckBox: "check_" + this.idobjetValue,
            }
        );
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_supprimer ", this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:supprimer", 
            true, 
            true,
            {
                titre: "Suppression",
            }
        );
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_modifier ", this.idobjetValue);
        this.buildCustomEvent("app:liste-principale:modifier", 
            true, 
            true,
            {
                titre: "Modification",
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