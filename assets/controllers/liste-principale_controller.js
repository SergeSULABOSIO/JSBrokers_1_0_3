import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'display',  //Champ d'affichage d'informations
        'donnees',     //Liste conténant des élements
    ];
    static values = {
        controleurphp: String,
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
    supprimerElement(idObjet) {
        this.objetValue = idObjet;
        this.updateMessage("Suppression de " + idObjet + " en cours... Patientez svp.");
        const url = '/admin/' + this.controleurphpValue + '/remove/' + this.identrepriseValue + '/' + this.objetValue;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(data => {
                const serverJsonObject = JSON.parse(data);
                // this.formTarget.innerHTML = html;
                if (serverJsonObject.reponse == "Ok") {
                    this.nbelementsValue--;
                    const elementDeleted = document.getElementById("liste_row_" + this.objetValue);
                    const parentElementDeleted = elementDeleted.parentElement;
                    parentElementDeleted.removeChild(elementDeleted);
                    // console.log("Element HTML à Supprimer: ", elementDeleted);
                    this.updateMessage("Suppression réussie.");
                } else {
                    this.updateMessage("Suppression échouée. Merci de bien vérifier votre connexion Internet.");
                }
            });
    }

    /**
     * 
     * @param {number} idObjet 
     */
    actualiserElement(idObjet) {
        this.updateMessage("Actualisation de l'élement " + idObjet + " en cours... Patientez svp.");
        const url = '/admin/' + this.controleurphpValue + '/getlistelementdetails/' + idObjet;
        console.log(url);
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(data => {
                const elementUpdated = document.getElementById("liste_row_" + idObjet);
                elementUpdated.innerHTML = data;
                this.updateMessage("La mise a jour réussie.");
            })
            .catch(errorMessage => {
                this.updateMessage("La mise a jour échouée. Prière de bien vérifier votre connexion Internet.");
                console.error(errorMessage);
            });
    }


    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = "Résultat: " + this.nbelementsValue + " élement(s) | " + newMessage;
    }
}