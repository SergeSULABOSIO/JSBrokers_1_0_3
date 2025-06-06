import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'reference',
        'referencepolice',
        'display',
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
        console.log("ID NOTIFICATION SINISTRE: " + this.idnotificationsinistreValue);

        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));

        // this.displayTarget.textContent = "Prêt.";
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
        console.log(event.target);

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
                    console.log("On a cliqué sur un bouton Enregistré.");
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
        // this.displayTarget.textContent = "Actualisation des termes de paiement...";
        this.updateMessage("Actualisation des termes de paiement...");

        fetch("/admin/avenant/viewAvenantsByReferencePolice/" + referencePolice)
            .then(response => response.text()) //.json()
            .then(data => {
                // console.log(data);
                // this.displayTarget.textContent = "Prêt.";
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
        // this.displayTarget.textContent = "Enregistrement de " + this.referenceTarget.value + " en cours...";
        this.updateMessage("Enregistrement de " + this.referenceTarget.value + " en cours...");

        this.displayTarget.style.display = 'block';

        // Ici, vous pouvez ajouter votre logique AJAX, de validation, etc.
        const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        const url = '/admin/notificationsinistre/formulaire/' + this.identrepriseValue + '/-1';// + this.idnotificationsinistreValue;
        console.log(formData, this.element, url);
        fetch(url, {
            method: this.element.method,
            body: formData,
        })
            .then(response => response.json()) //.json()
            .then(data => {
                console.log("Text Reponse:", data);
                const userObject = JSON.parse(data);
                console.log("Json Reponse:", userObject);

                event.target.disabled = false;
                this.displayTarget.style.display = 'block';
                this.displayTarget.textContent = "Prêt.";
                this.updateMessage("Prêt.");

                // Traitez la réponse ici
                this.initBadges(
                    userObject.nbOffres,
                    userObject.nbContacts,
                    userObject.nbTaches,
                    userObject.nbDocuments,
                );
                this.updateViewAvenants(userObject.referencePolice);
            })
            .catch(errorMessage => {
                event.target.disabled = false;
                // this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.updateMessage("Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.");
                this.displayTarget.style.display = 'none';
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }

    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = newMessage;

        // Accéder à l'élément parent du contrôleur
        const parentElement = this.element.closest('[data-controller="dialogue"]');
        if (parentElement) {
            // Obtenir l'instance du contrôleur parent
            const dialogueController = this.application.getControllerForElementAndIdentifier(parentElement, 'dialogue');

            if (dialogueController && dialogueController.hasMessageTarget) {
                // Accéder au target 'message' du parent (ou plus précisement de la boîte de dialogue)
                // console.log("Accès au target 'message' du parent :", dialogueController.messageTarget.textContent);

                // Appeler une méthode du parent, par exemple
                dialogueController.updateMessage(newMessage);
            }
        }
    }
}