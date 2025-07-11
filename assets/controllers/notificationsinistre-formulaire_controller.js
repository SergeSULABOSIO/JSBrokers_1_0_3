import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, defineIcone, EVEN_ACTION_CHANGE, EVEN_ACTION_CLICK, EVEN_ACTION_ENREGISTRER, EVEN_CODE_RESULTAT_ECHEC, EVEN_CODE_RESULTAT_OK, EVEN_RESULTAT_ECHEC, EVEN_RESULTAT_SUCCESS, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

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
        this.listePrincipale.addEventListener(EVEN_ACTION_ENREGISTRER, this.handleActionEnregistrer.bind(this));
        this.element.addEventListener(EVEN_ACTION_CLICK, event => this.ecouterClick(event));
        this.referencepoliceTarget.addEventListener(EVEN_ACTION_CHANGE, (event) => this.updateViewAvenants(this.referencepoliceTarget.value));
    }

    disconnect() {
        this.listePrincipale.removeEventListener(EVEN_ACTION_ENREGISTRER, this.handleActionEnregistrer.bind(this));
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
        this.viewavenantsTarget.textContent = "Actualisation des avenants...";
        fetch("/admin/avenant/viewAvenantsByReferencePolice/" + referencePolice)
            .then(response => response.text()) //.json()
            .then(data => {
                // console.log(data);
                this.viewavenantsTarget.innerHTML = data;
            })
            .catch(errorMessage => {
                console.error(this.nomcontroleur + " - Réponse d'erreur du serveur :", errorMessage);
            });
    }


    /**
     * 
     * @param {MouseEvent} event 
    */
    enregistrerNotificationSinistre = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.target.disabled = true;
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

                console.log(this.nomcontroleur + " - ICI.");
                //On émet un évenement pour signaler que l'enreg s'est effectué avec succès
                buildCustomEventForElement(this.listePrincipale, EVEN_RESULTAT_SUCCESS, true, true,
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
                buildCustomEventForElement(this.listePrincipale, EVEN_RESULTAT_ECHEC, true, true,
                    {
                        idObjet: -1,
                        code: EVEN_CODE_RESULTAT_ECHEC,
                        message: "Enregistrement échoué.",
                    }
                );
            });
    }


    /**
     * @param {Event} event 
    */
    handleActionEnregistrer(event) {
        this.enregistrerNotificationSinistre(event);
    }
}