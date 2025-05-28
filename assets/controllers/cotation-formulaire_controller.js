import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'nom',
        'prime',
        'display',
        'displayprime',
        'displaycommission',
        'displaytranche',
        'displayavenant',
        'displaydocument',
        'displaytache',
        'btEnregistrer',
    ];

    static values = {
        idcotation: Number,
        identreprise: Number,
        nbchargements: Number,
        nbrevenus: Number,
        nbavenants: Number,
        nbtranches: Number,
        nbtaches: Number,
        nbdocuments: Number,
    };

    connect() {
        this.isSaved = false;
        console.log("ID COTATION: " + this.idcotationValue);

        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        this.displayTarget.textContent = "Prêt.";

        //On initialise les badges des onglets
        this.initBadges(
            this.nbchargementsValue,
            this.nbrevenusValue,
            this.nbavenantsValue,
            this.nbtranchesValue,
            this.nbtachesValue,
            this.nbdocumentsValue,
        );
    }



    /**
     * 
     * @param {Number} nbchargements 
     * @param {Number} nbrevenus 
     * @param {Number} nbavenants 
     * @param {Number} nbtranches 
     * @param {Number} nbtaches 
     * @param {Number} nbdocuments 
     */
    initBadges(nbchargements, nbrevenus, nbavenants, nbtranches, nbtaches, nbdocuments) {
        this.displayprimeTarget.innerHTML = "";
        this.displaycommissionTarget.innerHTML = "";
        this.displaytrancheTarget.innerHTML = "";
        this.displayavenantTarget.innerHTML = "";
        this.displaydocumentTarget.innerHTML = "";
        this.displaytacheTarget.innerHTML = "";

        // Chargement
        if (nbchargements != 0) {
            this.displayprimeTarget.append(this.generateSpanElement(nbchargements));
        }
        // Commission
        if (nbrevenus != 0) {
            this.displaycommissionTarget.append(this.generateSpanElement(nbrevenus));
        }
        // Tranche
        if (nbtranches != 0) {
            this.displaytrancheTarget.append(this.generateSpanElement(nbtranches));
        }
        // Avenant
        if (nbavenants != 0) {
            this.displayavenantTarget.append(this.generateSpanElement(nbavenants));
        }
        // Document
        if (nbdocuments != 0) {
            this.displaydocumentTarget.append(this.generateSpanElement(nbdocuments));
        }
        // Tache
        if (nbtaches != 0) {
            this.displaytacheTarget.append(this.generateSpanElement(nbtaches));
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
            } else if (event.target.type == "submit") {
                //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
                if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                    console.log("On a cliqué sur un bouton Enregistré.");
                    this.enregistrerCotation(event);
                }
            } else {
                event.preventDefault(); // Empêche la soumission classique du formulaire
            }
        }
    }


    /**
     * 
     * @param {MouseEvent} event 
    */
    enregistrerCotation = (event) => {
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
            .then(response => response.json()) //.json()
            .then(data => {
                const userObject = JSON.parse(data);
                console.log(userObject);

                event.target.disabled = false;
                this.displayTarget.style.display = 'block';
                this.displayTarget.textContent = "Prêt.";

                // Traitez la réponse ici
                this.initBadges(
                    userObject.nbChargements, 
                    userObject.nbRevenus,
                    userObject.nbAvenants,
                    userObject.nbTranches,
                    userObject.nbTaches,
                    userObject.nbDocuments,
                );
                
                this.primeTarget.value = userObject.primeTTC;
            })
            .catch(errorMessage => {
                event.target.disabled = false;
                this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.displayTarget.style.display = 'none';
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }
}