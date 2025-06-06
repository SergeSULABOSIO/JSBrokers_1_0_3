// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal

export default class extends Controller {
    static targets = [
        'boite',
        'form',
        'message',
        'btFermer'
    ];
    static values = {
        idnotificationsinistre: Number,
        identreprise: Number,
        action: Number,
        objet: Number,
    };

    connect() {
        console.log("Connecté au contrôleur dialogue.");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");

        // Initialiser la modal uniquement si elle n'est pas déjà une instance
        if (!(this.boiteTarget instanceof Element)) { // Check if it's a DOM element, not already a Bootstrap instance
            console.error("Modal target is not a DOM element:", this.boiteTarget);
            return;
        }
        // Initialiser la modal en désactivant le backdrop click
        this.boite = new Modal(this.boiteTarget, {
            backdrop: 'static', // ou true si vous voulez un backdrop sans fermeture au clic
            keyboard: false // Désactive la fermeture par la touche Échap si vous le souhaitez
        });
        console.log("Modal instance created:", this.boite);
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
        const url = '/admin/notificationsinistre/formulaire/' + this.identrepriseValue + '/' + this.idnotificationsinistreValue;
        console.log("Méthode open appelée.", this.identrepriseValue, this.idnotificationsinistreValue, url);

        this.formTarget.innerHTML = "Veuillez patienter svp...";
        this.boite.show();

        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                if (this.boite) {
                    this.formTarget.innerHTML = html;
                    // this.boite.show();
                } else {
                    console.error("Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
                }
            });
    }

    /**
     * 
     * @param {Event} event 
     */
    close(event) {
        if (event) {
            event.preventDefault();
        }
        console.log("Méthode close appelée.");
        if (this.boite) {
            this.boite.hide();
        }
    }


}