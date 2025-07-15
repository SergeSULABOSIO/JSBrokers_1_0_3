import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, defineIcone, EVEN_ACTION_AFFICHER_MESSAGE, EVEN_ACTION_CHANGE, EVEN_ACTION_CLICK, EVEN_ACTION_ENREGISTRER, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, EVEN_BOITE_DIALOGUE_SUBMITTED, EVEN_CODE_RESULTAT_ECHEC, EVEN_CODE_RESULTAT_OK, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_SERVER_RESPONSED, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

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
        this.listePrincipale = document.getElementById("liste");
        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");
        this.initBadges(
            this.nboffresValue,
            this.nbcontactsValue,
            this.nbtachesValue,
            this.nbdocumentsValue,
        );
        this.updateViewAvenants(this.referencepoliceTarget.value);
        this.btEnregistrerTarget.style.display = "none";
        this.setEcouteurs();
    }

    setEcouteurs() {
        document.addEventListener(EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, this.handleSubmitRequest.bind(this));
        this.element.addEventListener(EVEN_ACTION_CLICK, event => this.ecouterClick(event));
        this.referencepoliceTarget.addEventListener(EVEN_ACTION_CHANGE, (event) => this.updateViewAvenants(this.referencepoliceTarget.value));
    }

    disconnect() {
        document.removeEventListener(EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, this.handleSubmitRequest.bind(this));
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
        this.action_afficherMessage("Avenant", "Recherche des avenants. Veuillez patienter...");
        this.viewavenantsTarget.textContent = "Actualisation des avenants...";
        fetch("/admin/avenant/viewAvenantsByReferencePolice/" + referencePolice)
            .then(response => response.text()) //.json()
            .then(data => {
                this.action_afficherMessage("Etat", "Prêt.");
                this.viewavenantsTarget.innerHTML = data;
            })
            .catch(errorMessage => {
                this.action_afficherMessage("Avenant", "Avenant introuvable.");
                console.error(this.nomcontroleur + " - Réponse d'erreur du serveur :", errorMessage);
            });
    }



    /**
     * 
     * @param {String} titre 
     * @param {String} textMessage 
     */
    action_afficherMessage(titre, textMessage) {
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_AFFICHER_MESSAGE, true, true,
            {
                titre: titre,
                message: textMessage,
            }
        );
    }


    /**
     * @param {Event} event 
    */
    handleSubmitRequest(event) {
        const { action } = event.detail;
        console.log(this.nomcontroleur + " - HandleSubmitRequest", event.detail);
        event.target.disabled = true;
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Demande de soumission",
            message: "Veuillez patienter stp...",
        });
        const formData = new FormData(this.element); // 'this.element' fait référence à l'élément <form>
        const url = '/admin/notificationsinistre/formulaire/' + this.identrepriseValue + '/' + (this.idnotificationsinistreValue == 0 ? '-1' : this.idnotificationsinistreValue);
        // console.log(url);
        fetch(url, {
            method: this.element.method,
            body: formData,
        })
            .then(response => response.json()) //.json()
            .then(data => {
                const userObject = JSON.parse(data);
                event.target.disabled = false;

                // Traitez la réponse ici
                this.idnotificationsinistreValue = userObject.idNotificationSinistre;
                this.initBadges(
                    userObject.nbOffres,
                    userObject.nbContacts,
                    userObject.nbTaches,
                    userObject.nbDocuments,
                );
                this.updateViewAvenants(userObject.referencePolice);
                
                //On émet un évenement pour signaler que l'enreg s'est effectué avec succès
                buildCustomEventForElement(document, EVEN_SERVER_RESPONSED, true, true,
                    {
                        idObjet: userObject.idNotificationSinistre,
                        code: EVEN_CODE_RESULTAT_OK,
                        message: "Enregistrement réussi.",
                    }
                );
            })
            .catch(errorMessage => {
                event.target.disabled = false;
                console.error(this.nomcontroleur + " - Réponse d'erreur du serveur :", errorMessage);
                buildCustomEventForElement(document, EVEN_SERVER_RESPONSED, true, true,
                    {
                        idObjet: -1,
                        code: EVEN_CODE_RESULTAT_ECHEC,
                        message: "Enregistrement échoué.",
                    }
                );
            });
        //Juste après soumission de la requête vers le serveur
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_SUBMITTED, true, true, event.detail);
    }
}