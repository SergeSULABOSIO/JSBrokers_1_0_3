// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
// Assurez-vous que Bootstrap est bien importé ici
// import * as bootstrap from '.bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal


export default class extends Controller {
    static targets = ['boite'];

    connect() {
        console.log("Connecté au contrôleur dialogue.");
        // Initialiser la modal uniquement si elle n'est pas déjà une instance
        if (!(this.boiteTarget instanceof Element)) { // Check if it's a DOM element, not already a Bootstrap instance
            console.error("Modal target is not a DOM element:", this.boiteTarget);
            return;
        }
        // Si this.modalTarget est bien un élément DOM, créez l'instance
        // Initialiser la modal en désactivant le backdrop click
        this.boite = new Modal(this.boiteTarget, {
            backdrop: 'static', // ou true si vous voulez un backdrop sans fermeture au clic
            keyboard: true // Désactive la fermeture par la touche Échap si vous le souhaitez
        });
        console.log("Modal instance created:", this.boite);

        // Optionnel: Ajouter un écouteur d'événement pour le clic sur le backdrop si vous voulez le gérer manuellement
        this.boiteTarget.addEventListener('click', (event) => {
            if (event.target === this.boiteTarget) { // S'assurer que le clic est sur le backdrop lui-même
                this.close(event); // Appeler votre méthode close de Stimulus
            }
        });
    }

    /**
     * 
     * @param {Event} event 
     */
    open(event) {
        // Empêcher le comportement par défaut si le bouton est un submit ou un lien
        event.preventDefault();
        console.log("Méthode open appelée.");
        if (this.boite) {
            this.boite.show();
        } else {
            console.error("Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
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
        console.log("Méthode close appelée.");
        if (this.boite) {
            this.boite.hide();
        }
    }
}