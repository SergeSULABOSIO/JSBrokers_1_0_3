// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal

export default class extends Controller {
    static targets = [
        'titre',
        'boite',
        'form',
        'message',
        'btSubmit',
        'btFermer'
    ];

    connect() {
        this.init();
    }

    disconnect() {
        console.log("Dialogue - Désactivation Ecouteur dialogueCanSupprimer");
        this.listePrincipale.removeEventListener("app:liste-principale:dialogueCanSupprimer", this.handleItemCanSupprimer.bind(this));
    }


    init() {
        this.listePrincipale = document.getElementById("liste");
        // Initialisation
        this.initBoiteDeDialogue();
        this.setEcouteurs();
    }


    setEcouteurs() {
        console.log("Dialogue - Ecouteur dialogueCanSupprimer");
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.addEventListener("app:liste-principale:dialogueCanSupprimer", this.handleItemCanSupprimer.bind(this));
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCanSupprimer(event) {
        console.log("handleItemCanSupprimer", new Date());
        const { titre, message, tabSelectedCheckBoxes } = event.detail; // Récupère les données de l'événement
        this.titreTarget.innerHTML = titre;
        this.formTarget.innerHTML = message;
        defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "OUI");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "NON");
        this.showDialogue();
    }

    initBoiteDeDialogue() {
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

    showDialogue() {
        if (this.boite) {
            this.boite.show();
        } else {
            console.error("Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
        }
    }


    closeDialogue() {
        if (this.boite) {
            this.boite.hide();
        }
    }


    loadAddEditFormFromServer() {
        const url = '/admin/' + this.controleurDeLaListePrincipale.controleurphpValue + '/formulaire/' + this.controleurDeLaListePrincipale.identrepriseValue + '/' + this.objet;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.formTarget.innerHTML = html;
            });
    }



    /**
     * @param {Event} event 
     */
    action_fermer(event) {
        event.preventDefault();
        this.closeDialogue();
    }


    /**
     * @param {Event} event 
    */
    action_accepter(event) {
        event.preventDefault();
        this.closeDialogue();
    }


    getControleurListePrincipale() {
        if (this.listePrincipale) {
            return this.application.getControllerForElementAndIdentifier(this.listePrincipale, "liste-principale");
        }
        return null;
    }
}