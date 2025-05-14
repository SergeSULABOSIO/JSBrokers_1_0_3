import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!

export default class extends Controller {
    static targets = [
        'nom',
        'display',
        'btEnregistrer',
    ];

    static values = {
        idtaxe: Number,
        identreprise: Number,
    };

    connect() {
        this.isSaved = false;
        console.log("ID TAXE: " + this.idtaxeValue);

        //Définition de l'icone sur le bouton Enregistrer.
        defineIcone(getIconeUrl(1, "save", 19), this.btEnregistrerTarget, "ENREGISTRER");

        //On écoute les boutons de soumission du formulaire
        this.element.addEventListener("click", event => this.ecouterClick(event));
        this.displayTarget.textContent = "Prêt.";
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
                    this.enregistrer(event);
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
    enregistrer = (event) => {
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