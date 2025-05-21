import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'nom',
        'display',
        'displaycontact',
        'displaydocument',
        'btEnregistrer',
        'block_entreprise',
    ];

    static values = {
        idclient: Number,
        identreprise: Number,
        nbcontacts: Number,
        nbdocuments: Number,
        civilite: Number,
    };

    connect() {
        this.isSaved = false;
        console.log("ID CLIENT: " + this.idclientValue);

        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        //On définit la civilité du client
        this.setCivilite(this.civiliteValue);
        //On initialise les badges des onglets
        this.initBadges(
            this.nbcontactsValue,
            this.nbdocumentsValue,
        );
        this.displayTarget.textContent = "Prêt.";
    }


    /**
     * 
     * @param {Event} event 
     */
    changerCivilite = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        var selectedValue = event.target.selectedOptions[0].value;
        console.log("J'ECOUTE:", selectedValue);
        this.setCivilite(selectedValue);
    }

    /**
     * 
     * @param {Number} code 
     */
    setCivilite(code){
        if (code != 2) {
            this.block_entrepriseTarget.style.display = 'none';
        } else {
            this.block_entrepriseTarget.style.display = 'block';
        }
    }



    /**
     * 
     * @param {Number} nbContacts 
     * @param {Number} nbDocuments 
     */
    initBadges(nbContacts, nbDocuments) {
        this.displaycontactTarget.innerHTML = "";
        this.displaydocumentTarget.innerHTML = "";
        // Contacts
        if (nbContacts != 0) {
            this.displaycontactTarget.append(this.generateSpanElement(nbContacts));
        }
        // Documents
        if (nbDocuments != 0) {
            this.displaydocumentTarget.append(this.generateSpanElement(nbDocuments));
        }
    }


    /**
     * 
     * @param {string} texte 
     * @returns {HTMLSpanElement}
     */
    generateSpanElement = (texte) => {
        var span = document.createElement("span");
        span.setAttribute('class', "badge rounded-pill text-bg-warning m-2 fw-bold");
        span.innerText = texte;
        return span;
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    ecouterClick = (event) => {
        console.log(event.target);
        
        if (event.target.type != undefined) {
            if (event.target.type == "datetime-local") {
                //On laisse le comportement par défaut
            }else if (event.target.type == "submit") {
                //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
                if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                    console.log("On a cliqué sur un bouton Enregistré.");
                    this.enregistrerClient(event);
                }
            }else{
                event.preventDefault(); // Empêche la soumission classique du formulaire
            }
        }
    }


    /**
     * 
     * @param {MouseEvent} event 
    */
    enregistrerClient = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.target.disabled = true;
        this.isSaved = true;
        this.displayTarget.textContent = "Enregistrement de " + this.nomTarget.value + " en cours...";
        this.displayTarget.style.display = 'block';

        // Ici, vous pouvez ajouter votre logique AJAX, de validation, etc.
        const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        fetch(this.element.action, {
            method: this.element.method,
            body: formData,
        })
            .then(response => response.text()) //.json()
            .then(data => {
                console.log(data);
                event.target.disabled = false;
                this.displayTarget.style.display = 'block';

                // Traitez la réponse ici
                if (this.isSaved == true) {
                    this.displayTarget.textContent = "Prêt.";
                    //actualisation des autres composant du formulaire ainsi que du panier
                    const tabData = data.split("__1986__");
                    this.initBadges(tabData[1], tabData[2]);
                }

            })
            .catch(errorMessage => {
                event.target.disabled = false;
                this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.displayTarget.style.display = 'none';
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }
}