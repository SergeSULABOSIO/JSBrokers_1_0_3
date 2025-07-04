import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'reference',
        'referencepolice',
        'displayoffre',
        'displaycontact',
        'displaytache',
        'displaydoc',
        'btEnregistrer',
        'viewavenants',
    ];

    static values = {
        idnotificationsinistre: Number,
        identreprise: Number,
        nboffres: Number,
        nbcontacts: Number,
        nbtaches: Number,
        nbdocuments: Number,
    };

    connect() {
        this.nomcontroleur = "NOTIFICATIONSINISTRE-FORMULAIRE";
        // console.log("ID NOTIFICATION SINISTRE: " + this.idnotificationsinistreValue);
        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");
        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        this.updateMessage("Prêt.");

        //On initialise les badges des onglets
        this.initBadges(
            this.nboffresValue,
            this.nbcontactsValue,
            this.nbtachesValue,
            this.nbdocumentsValue,
        );
        this.updateViewAvenants(this.referencepoliceTarget.value);
        this.referencepoliceTarget.addEventListener("change", (event) => this.updateViewAvenants(this.referencepoliceTarget.value));
        this.btEnregistrerTarget.style.display = "none";
        this.connexionAuxControleurParents();
    }
    
    
    connexionAuxControleurParents(){
        this.controleurDeLaListePrincipale = this.getControleurListePrincipale("liste-principale");
        this.controleurDeLaBoiteDeDialogue = this.getControleurListePrincipale("dialogue");
        this.controleurDeLaBoiteDeDialogue.controleurenfant = this;
        console.log(this.nomcontroleur + " - Controleur Dialogue est notifié du chargement de Notificationsinistre-formulaire", this.controleurDeLaBoiteDeDialogue);
    }


    /**
     * 
     * @param {string} nomcontroleur 
     * @returns 
     */
    getControleurListePrincipale(nomcontroleur) {
        const listePrincipale = document.getElementById("liste");
        if (listePrincipale) {
            return this.application.getControllerForElementAndIdentifier(listePrincipale, nomcontroleur);
        }
        return null;
    }



    /**
     * @param {Number} nboffresValue 
     * @param {Number} nbcontactsValue 
     * @param {Number} nbtachesValue 
     * @param {Number} nbdocumentsValue 
     */
    initBadges(nboffresValue, nbcontactsValue, nbtachesValue, nbdocumentsValue) {
        this.displayoffreTarget.innerHTML = "";
        this.displaycontactTarget.innerHTML = "";
        this.displaytacheTarget.innerHTML = "";
        this.displaydocTarget.innerHTML = "";

        // Offres d'indemnisation
        if (nboffresValue != 0) {
            this.displayoffreTarget.append(this.generateSpanElement(nboffresValue));
        }
        // Contacts
        if (nbcontactsValue != 0) {
            this.displaycontactTarget.append(this.generateSpanElement(nbcontactsValue));
        }
        // Taches
        if (nbtachesValue != 0) {
            this.displaytacheTarget.append(this.generateSpanElement(nbtachesValue));
        }
        // Doc
        if (nbdocumentsValue != 0) {
            this.displaydocTarget.append(this.generateSpanElement(nbdocumentsValue));
        }
    }


    /**
     * 
     * @param {string} texte 
     * @returns {HTMLSpanElement}
     */
    generateSpanElement = (texte) => {
        var span = document.createElement("span");
        span.setAttribute('class', "badge rounded-pill text-bg-warning fw-bold");
        span.innerText = texte;
        return span;
    }


    /**
     * 
     * @param {MouseEvent} event 
     */
    ecouterClick = (event) => {
        // console.log(event.target);

        if (event.target.type != undefined) {
            if (event.target.type == "radio") {
                //On laisse le comportement par défaut
            } else if (event.target.type == "datetime-local") {
                //On laisse le comportement par défaut
            } else if (event.target.type == "date") {
                //On laisse le comportement par défaut
            } else if (event.target.type == "submit") {
                //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
                if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                    // console.log("On a cliqué sur un bouton Enregistré.");
                    this.enregistrerNotificationSinistre(event);
                }
            } else {
                event.preventDefault(); // Empêche la soumission classique du formulaire
            }
        }
    }


    /**
     * 
     * @param {Number} referencePolice 
     */
    updateViewAvenants(referencePolice) {
        this.viewavenantsTarget.textContent = "Actualisation des avenants...";
        this.updateMessage("Actualisation des termes de paiement...");

        fetch("/admin/avenant/viewAvenantsByReferencePolice/" + referencePolice)
            .then(response => response.text()) //.json()
            .then(data => {
                // console.log(data);
                this.updateMessage("Prêt.");
                this.viewavenantsTarget.innerHTML = data;
            })
            .catch(errorMessage => {
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }


    /**
     * 
     * @param {MouseEvent} event 
    */
    enregistrerNotificationSinistre = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.target.disabled = true;
        this.updateMessage("Enregistrement de " + this.referenceTarget.value + " en cours...");

        const isNew = this.idnotificationsinistreValue == 0 ? true : false;

        const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        const url = '/admin/notificationsinistre/formulaire/' + this.identrepriseValue + '/' + (this.idnotificationsinistreValue == 0 ? '-1' : this.idnotificationsinistreValue);
        // console.log("ICI", formData, this.element, url);
        fetch(url, {
            method: this.element.method,
            body: formData,
        })
            .then(response => response.json()) //.json()
            .then(data => {
                // console.log("Text Reponse:", data);
                const userObject = JSON.parse(data);
                // console.log("Json Reponse:", userObject, data);

                event.target.disabled = false;
                this.updateMessage("Prêt.");

                // Traitez la réponse ici
                this.idnotificationsinistreValue = userObject.idNotificationSinistre;
                this.initBadges(
                    userObject.nbOffres,
                    userObject.nbContacts,
                    userObject.nbTaches,
                    userObject.nbDocuments,
                );
                this.updateViewAvenants(userObject.referencePolice);

                if (this.controleurDeLaListePrincipale != null && isNew == true) {
                    this.controleurDeLaListePrincipale.outils_recharger(event);
                }
            })
            .catch(errorMessage => {
                event.target.disabled = false;
                this.updateMessage("Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.");
                console.error(this.nomcontroleur + " - Réponse d'erreur du serveur :", errorMessage);
            });
    }

    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        // Accéder à l'élément parent du contrôleur
        const elementListe = document.getElementById("liste");
        if (elementListe) {
            // Obtenir l'instance du contrôleur parent
            const dialogueController = this.application.getControllerForElementAndIdentifier(elementListe, 'dialogue');
            if (dialogueController && dialogueController.hasMessageTarget) {
                // Appeler une méthode du parent, par exemple
                dialogueController.updateMessage(newMessage);
            }
        }
    }


    /**
     * @param {Event} event 
    */
    triggerFromParent(event) {
        this.enregistrerNotificationSinistre(event);
    }
}