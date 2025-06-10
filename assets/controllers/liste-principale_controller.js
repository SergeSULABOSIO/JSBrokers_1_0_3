import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'display',  //Champ d'affichage d'informations
        'donnees',  //Liste conténant des élements
        'btToutCocher',
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
        this.tabSelectedCheckBox = [];
        console.log("Liste " + this.rubriqueValue);
        this.updateMessage("Prêt.");

        //On défini les écouteurs ici
        this.btToutCocherTarget.addEventListener('change', (event) => this.cocherTousElements(event));
    }

    /**
     * @param {Event} event 
    */
    selectionnerElement(event) {
        const cible = event.currentTarget;
        this.objetValue = cible.dataset.itemObjet;
        this.updateMessage("Séléction [id.=" + this.objetValue + "]");
        // console.log("Liste : Element selectionné: ", event.currentTarget, this.objetValue);
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
        this.updateMessage("Actualisation de l'élement " + idObjet + " en cours...");
        const url = '/admin/' + this.controleurphpValue + '/getlistelementdetails/' + this.identrepriseValue + "/" + idObjet;
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


    /**
     * 
     * @param {Event} event 
     */
    cocherTousElements(event) {
        const cases = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        // console.log(event);
        this.tabSelectedCheckBox = [];
        console.log("Coché?:" + this.btToutCocherTarget.checked);
        cases.forEach(caseACocher => {
            caseACocher.checked = this.btToutCocherTarget.checked;
            // this.tabSelectedCheckBox.push(caseACocher.getAttribute("id"));
        });
        if (this.tabSelectedCheckBox.length != 0) {
            this.updateMessage(this.tabSelectedCheckBox.length + " éléments cochés.");
        } else {
            this.updateMessage("");
        }
    }

    /**
     * 
     * @param {Event} event 
     */
    cocherElement(event) {
        // console.log("checkBox", event.currentTarget);
        const idCheckBoxCible = event.currentTarget.getAttribute("id");
        const indexASupp = this.tabSelectedCheckBox.indexOf(idCheckBoxCible);
        if (indexASupp == -1) {
            this.tabSelectedCheckBox.push(idCheckBoxCible);
        } else {
            this.tabSelectedCheckBox.splice(indexASupp, 1);
        }
        console.log(this.tabSelectedCheckBox);
        if (this.tabSelectedCheckBox.length != 0) {
            this.updateMessage(this.tabSelectedCheckBox.length + " éléments cochés.");
        } else {
            this.updateMessage("");
        }
    }
}