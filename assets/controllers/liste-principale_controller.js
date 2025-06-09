import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'display',  //Champ d'affichage d'informations
        'donnees',     //Liste conténant des élements
    ];
    static values = {
        objet: Number,
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
    };


    connect() {
        console.log("Liste " + this.rubriqueValue);
        this.updateMessage("Prêt: " + this.nbelementsValue + " élement(s).");
    }

    /**
     * @param {Event} event 
    */
    selectionner(event) {
        const cible = event.currentTarget;
        this.objetValue = cible.dataset.itemObjet;
        this.updateMessage("Prêt: " + this.nbelementsValue + " élement(s) " + "| Séléction [id.=" + this.objetValue + "]");
        console.log("Liste : Element selectionné: ", event.currentTarget, this.objetValue);
    }


    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = newMessage;
    }
}