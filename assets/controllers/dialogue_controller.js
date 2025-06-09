// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal

export default class extends Controller {
    /**
     * Action [0=New, 1=Edit, 3=Delete]
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
        nomcontroler: String,
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
        const cible = event.currentTarget;
        this.actionValue = cible.dataset.itemAction;
        this.objetValue = cible.dataset.itemObjet;
        this.nomcontrolerValue = cible.dataset.itemNomcontroler;
        this.titreTarget.innerHTML = cible.dataset.itemTitre;

        this.formTarget.innerHTML = "Veuillez patienter svp...";
        if (this.boite) {
            this.boite.show();
        } else {
            console.error("Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
        }

        if (this.actionValue == 0 || this.actionValue == 1) {
            defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
            defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "ANNULER");
            const url = '/admin/notificationsinistre/formulaire/' + this.identrepriseValue + '/' + this.objetValue;
            fetch(url) // Remplacez par l'URL de votre formulaire
                .then(response => response.text())
                .then(html => {
                    this.formTarget.innerHTML = html;
                });
        }
        if (this.actionValue == 3) {
            const listeControler = this.getListeController();
            listeControler.updateMessage("Opération de suppression déclanchée. Merci de confirmer dans la boîte de dialogue.");
            defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "SUPPRIMER");
            defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "ANNULER");
            this.formTarget.innerHTML = "Etes-vous sûre de vouloir supprimer cet enregistrement?";
        }
    }


    /**
     * 
     * @param {Event} event 
     */
    close(event) {
        if (event) {
            event.preventDefault();
        }
        if (this.actionValue == 3) {
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
            return this.application.getControllerForElementAndIdentifier(this.formTarget.firstElementChild, this.nomcontrolerValue);
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
        if (this.actionValue == 3) {
            const listeControler = this.getListeController();
            listeControler.supprimer(this.objetValue);
            console.log("On lance la suppression...");
            if (this.boite) {
                this.boite.hide();
            }
        } else {
            const childController = this.getChildController();
            if (childController) {
                // Appeler une méthode du contrôleur enfant
                childController.triggerFromParent(event);
            } else {
                console.error("Contrôleur enfant non trouvé.");
            }
        }
    }
}