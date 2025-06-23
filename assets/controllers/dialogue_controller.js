// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal

export default class extends Controller {
    /**
     * Action [0=New, 1=Edit, 2=Delete]
     */
    static targets = [
        'titre',
        'boite',
        'form',
        'message',
        'btSubmit',
        'btFermer'
    ];
    static values = {
        // nomcontrolerphp: String,
        // nomcontrolerstimulus: String,
        controleurenfant: String,
        identreprise: Number,
        action: Number,
        objet: Number,
    };


    connect() {
        console.log("Connecté au contrôleur dialogue.");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");
        defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
        // Initialiser la modal en désactivant le backdrop click
        this.boite = new Modal(this.boiteTarget, {
            backdrop: 'static', // ou true si vous voulez un backdrop sans fermeture au clic
            keyboard: false // Désactive la fermeture par la touche Échap si vous le souhaitez
        });
        this.updateMessage("Prêt");
    }


    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.messageTarget.innerHTML = newMessage + " | ";
    }


    /**
     * 
     * @param {Event} event 
     */
    open(event) {
        // Empêcher le comportement par défaut si le bouton est un submit ou un lien
        event.preventDefault();
        // console.log(event.currentTarget);
        const cible = event.currentTarget;
        const listeControler = this.getListeController();
        const controleurenfant = cible.dataset.itemControleurenfant;
        const action = cible.dataset.itemAction;
        const idObjet = cible.dataset.itemObjet;
        const titre = cible.dataset.itemTitre;
        this.titreTarget.innerHTML = titre;
        this.formTarget.innerHTML = "Veuillez patienter svp...";

        if (this.boite) {
            this.boite.show();
        } else {
            console.error("Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
        }

        // * Opération Ajout (0) ou Modification (1)
        if (action == 0 || action == 1) {
            if (action == 0) {
                this.updateMessage("Opération: Ajout d'un élément.");
                defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
            } else {
                this.updateMessage("Opération: Edition de l'élément ID: " + idObjet + ".");
                defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "METTRE A JOUR");
            }
            defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "ANNULER");
            const url = '/admin/' + listeControler.controleurphpValue + '/formulaire/' + listeControler.identrepriseValue + '/' + idObjet;
            fetch(url) // Remplacez par l'URL de votre formulaire
                .then(response => response.text())
                .then(html => {
                    this.formTarget.innerHTML = html;
                });
        }
        // * Opération Suppression
        if (action == 2) {
            listeControler.updateMessage("Opération de suppression déclanchée. Merci de confirmer dans la boîte de dialogue.");
            defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "SUPPRIMER");
            defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "ANNULER");
            this.formTarget.innerHTML = "Etes-vous sûre de vouloir supprimer cet enregistrement?";
        }

        this.actionValue = action;
        this.objetValue = idObjet;
        this.controleurenfantValue = controleurenfant;
    }


    /**
     * 
     * @param {Event} event 
     */
    close(event) {
        if (event) {
            event.preventDefault();
        }
        // console.log("On ferme après action: " + this.actionValue);
        //On ferme après Actualisation
        if (this.actionValue == 1) {
            const listeControler = this.getListeController();
            listeControler.actualiserElement(this.objetValue);
        }
        //Annulation de la suppression
        if (this.actionValue == 2) {
            const listeControler = this.getListeController();
            listeControler.updateMessage("Suppression annulée.");
        }
        // console.log("Méthode close appelée.");
        if (this.boite) {
            this.boite.hide();
        }
    }


    // Méthode pour obtenir l'instance du contrôleur enfant
    getChildController() {
        // Vérifie que l'élément 'form' est bien défini comme target
        if (this.hasFormTarget) {
            // console.log(this.formTarget.firstElementChild, this.nomcontrolerValue);
            return this.application.getControllerForElementAndIdentifier(this.formTarget.firstElementChild, this.controleurenfantValue);
        }
        return null;
    }


    getListeController() {
        const liste = document.getElementById("liste");
        // Vérifie que l'élément 'form' est bien défini comme target
        if (liste) {
            return this.application.getControllerForElementAndIdentifier(liste, "liste-principale");
        }
        return null;
    }


    /**
     * @param {Event} event 
    */
    submit(event) {
        // console.log("Dialogue - Action: " + this.actionValue);
        //Action: Ajout ou Modification
        if (this.actionValue == 0 || this.actionValue == 1) {
            // this.updateMessage('Enregistrement en cours...');
            const childController = this.getChildController();
            if (childController) {
                // Appeler une méthode du contrôleur enfant
                childController.triggerFromParent(event);
                // this.updateMessage('Prêt.');
            } else {
                console.error("Contrôleur enfant non trouvé.");
            }
        }
        //Action: Suppression
        if (this.actionValue == 2) {
            // this.updateMessage('Suppression en cours...');
            const listeControler = this.getListeController();
            // console.log("On lance la suppression...");
            listeControler.supprimerElement(this.objetValue);
            // this.updateMessage('Prêt.');
            if (this.boite) {
                this.boite.hide();
            }
        }
    }
}