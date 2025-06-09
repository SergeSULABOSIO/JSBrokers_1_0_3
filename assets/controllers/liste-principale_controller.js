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
        this.updateMessage("");
    }

    /**
     * @param {Event} event 
    */
    selectionner(event) {
        const cible = event.currentTarget;
        this.objetValue = cible.dataset.itemObjet;
        this.updateMessage("Séléction [id.=" + this.objetValue + "]");
        console.log("Liste : Element selectionné: ", event.currentTarget, this.objetValue);
    }

    /**
     * @param {number} idObjet 
    */
    supprimer(idObjet) {
        this.updateMessage("Liste Principale - Suppression de " + idObjet);
    }


    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = "Résultat: " + this.nbelementsValue + " élement(s) | " + newMessage;
    }
}