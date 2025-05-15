import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'reference',
        'referencenote',
        'montantpayable',
        'montantpaye',
        'montantsolde',
        'display',
        'btEnregistrer',
        'btvoirnotepdf',
        'btvoirbordereaupdf',
    ];

    static values = {
        idpaiement: Number,
        idnote: Number,
        identreprise: Number,
    };

    connect() {
        this.isSaved = false;
        console.log("ID PAIEMENT: " + this.idpaiementValue);

        //Définition de l'icone sur le bouton Enregistrer.
        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");
        defineIcone(getIconeUrl(0, "note", 19), this.btvoirnotepdfTarget, "Ouvrir la note");
        defineIcone(getIconeUrl(0, "bordereau", 19), this.btvoirbordereaupdfTarget, "Ouvrir le bordereau");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        this.displayTarget.textContent = "Prêt.";
    }


    /**
     * 
     * @param {string} reponseServeur 
     */
    loadDetailsSurNote(reponseServeur) {
        const data = reponseServeur.split("__1986__");
        this.referencenoteTarget.value = data[0];
        this.montantpayableTarget.value = data[1];
        this.montantpayeTarget.value = data[2];
        this.montantsoldeTarget.value = data[3];
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
            } else if (event.target.type == "submit") {
                //Si le bouton cliqué contient la mention 'enregistrer' en minuscule
                if ((event.target.innerText.toLowerCase()).indexOf("enregistrer") != -1) {
                    console.log("On a cliqué sur un bouton Enregistré.");
                    this.enregistrer(event);
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
    enregistrer = (event) => {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        event.target.disabled = true;
        this.isSaved = true;
        this.displayTarget.textContent = "Enregistrement de " + this.referenceTarget.value + " en cours...";
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
                }
                this.loadDetailsSurNote(data);
            })
            .catch(errorMessage => {
                event.target.disabled = false;
                this.displayTarget.textContent = "Désolé, une erreur s'est produite, merci de vérifier vos données ou votre connexion Internet.";
                this.displayTarget.style.display = 'none';
                console.error("Réponse d'erreur du serveur :", errorMessage);
            });
    }


    /**
     * 
     * @param {Event} event 
     */
    voirnotepdf(event) {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        console.log("OUVERTURE DE LA NOTE EN PDF. ID NOTE: " + this.idnoteValue);
        // <a class="dropdown-item" href="{{ path('admin.etats.imprimer_note', {'idNote': note.id, 'idEntreprise': entreprise.id, 'currentURL': currentURL})}}">
        this.displayTarget.textContent = "Ouverture de la note en cours...";
        this.displayTarget.style.display = 'block';
        fetch('/admin/etats/imprimerNote/' + this.idnoteValue + "/" + this.identrepriseValue + "/" + window.location.href) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                console.log(htmlData);
                this.displayTarget.textContent = "Prêt.";
            })
            .catch(error => {
                this.displayTarget.textContent = "Erreur!";
                console.error('Erreur lors du chargement du fragment:', conteneurPanier, error);
            });
    }


    /**
     * 
     * @param {Event} event 
     */
    voirbordereaupdf(event) {
        event.preventDefault(); // Empêche la soumission classique du formulaire
        console.log("OUVERTURE DU BORDEREAU EN PDF. ID NOTE: " + this.idnoteValue);
        // <a class="dropdown-item" href="{{ path('admin.etats.imprimer_bordereau_note', {'idNote': note.id, 'idEntreprise': entreprise.id, 'currentURL': currentURL})}}">
        this.displayTarget.textContent = "Ouverture du bordereau en cours...";
        this.displayTarget.style.display = 'block';
        fetch('/admin/etats/imprimerBordereauNote/' + this.idnoteValue + "/" + this.identrepriseValue + "/" + window.location.href) // L'URL de votre route Symfony
            .then(response => response.text())
            .then(htmlData => {
                console.log(htmlData);
                this.displayTarget.textContent = "Prêt.";
            })
            .catch(error => {
                this.displayTarget.textContent = "Erreur!";
                console.error('Erreur lors du chargement du fragment:', conteneurPanier, error);
            });
    }
}