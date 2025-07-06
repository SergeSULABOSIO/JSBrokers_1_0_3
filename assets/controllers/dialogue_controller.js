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
        this.ACTION_AJOUTER = 0;
        this.ACTION_MODIFIER = 1;
        this.ACTION_SUPPRIMER = 2;
        this.action = -1;
        this.titre = "";
        this.message = "";
        this.tabSelectedCheckBoxes = [];
        this.nomControleur = "DIALOGUE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        this.listePrincipale.removeEventListener(this.app_can_supprimer, this.handleItemCanSupprimer.bind(this));
        this.listePrincipale.removeEventListener(this.app_can_ajouter, this.handleItemCanAjouter.bind(this));
        this.listePrincipale.removeEventListener(this.app_fermer_boite, this.handleFermerBoite.bind(this));
        this.listePrincipale.removeEventListener(this.app_afficher_message, this.handleDisplayMessage.bind(this));
    }


    init() {
        this.app_fermer_boite = "app:dialogue:fermer_boite";
        this.app_can_supprimer = "app:liste-principale:dialogueCanSupprimer";
        this.app_can_ajouter = "app:liste-principale:dialogueCanAjouter";
        this.app_afficher_message = "app:liste-principale:afficher_message";
        this.listePrincipale = document.getElementById("liste");
        // Initialisation
        this.initBoiteDeDialogue();
        this.setEcouteurs();
    }


    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.addEventListener(this.app_can_supprimer, this.handleItemCanSupprimer.bind(this));
        this.listePrincipale.addEventListener(this.app_can_ajouter, this.handleItemCanAjouter.bind(this));
        this.listePrincipale.addEventListener(this.app_fermer_boite, this.handleFermerBoite.bind(this));
        this.listePrincipale.addEventListener(this.app_afficher_message, this.handleDisplayMessage.bind(this));
    }

    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCanSupprimer(event) {
        console.log(this.nomControleur + " - handleItemCanSupprimer", new Date());
        const { titre, message, tabSelectedCheckBoxes } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxes = tabSelectedCheckBoxes;
        this.titre = titre;
        this.message = message;
        this.action = this.ACTION_SUPPRIMER; //Supprimer (2)
        this.titreTarget.innerHTML = titre;
        this.formTarget.innerHTML = message;
        defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "OUI");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "NON");
        this.showDialogue();
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleFermerBoite(event) {
        this.closeDialogue();
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDisplayMessage(event) {
        const { titre, message } = event.detail; // Récupère les données de l'événement
        this.updateMessage(titre + ": " + message);
        console.log(this.nomControleur + " - Titre: " + titre + ", Message: " + message);
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCanAjouter(event) {
        console.log(this.nomControleur + " - handleItemCanAjouter", new Date());
        const { titre, entreprise, utilisateur, rubrique, controleurphp, controleurstimulus } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxes = [];
        this.titre = titre;
        this.message = titre;
        this.action = this.ACTION_AJOUTER; //Ajouter (0)
        this.titreTarget.innerHTML = titre;

        // this.formTarget.innerHTML = "On va charger le formulaire de saisie de données ici!!!!";
        defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");

        this.action_afficherMessage("Nouveau", "Chargement du formulaire...");
        console.log(this.nomControleur + " - Chargement du formulaire...");
        const url = '/admin/' + controleurphp + '/formulaire/' + entreprise + '/-1';
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.formTarget.innerHTML = html;
                this.showDialogue();
                this.action_afficherMessage("Nouveau", "Formulaire chargé sur la boîte de dialogue.");
                console.log(this.nomControleur + " - Formulaire chargé sur la boîte de dialogue.");
            });
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
            console.error(this.nomControleur + " - Erreur: La modal n'est pas initialisée dans open(). Impossible d'afficher.");
        }
    }


    closeDialogue() {
        if (this.boite) {
            this.boite.hide();
        }
    }


    /**
     * @param {Event} event 
     */
    action_fermer(event) {
        event.preventDefault();
        console.log(this.nomControleur + " - DIALOGUE FERMER", this.titre, this.message, this.action, this.tabSelectedCheckBoxes);
        this.buildCustomEvent(
            "app:liste-principale:dialog_no", true, true,
            {
                titre: this.titre,
                message: this.message,
                action: this.action,
                data: this.tabSelectedCheckBoxes,
            }
        );
        this.closeDialogue();
    }


    /**
     * 
     * @param {String} titre 
     * @param {String} textMessage 
     */
    action_afficherMessage(titre, textMessage) {
        this.buildCustomEvent(
            "app:liste-principale:afficher_message", true, true,
            {
                titre: titre,
                message: textMessage,
            }
        );
    }


    /**
     * @param {Event} event 
    */
    action_accepter(event) {
        event.preventDefault();
        console.log(this.nomControleur + " - DIALOGUE OK", this.titre, this.message, this.action, this.tabSelectedCheckBoxes);
        this.buildCustomEvent(
            "app:liste-principale:dialog_ok", true, true,
            {
                titre: this.titre,
                message: this.message,
                action: this.action,
                data: this.tabSelectedCheckBoxes,
            }
        );
        // this.closeDialogue();
    }


    buildCustomEvent(nomEvent, canBubble, canCompose, detailTab) {
        const event = new CustomEvent(nomEvent, {
            bubbles: canBubble, composed: canCompose, detail: detailTab
        });
        this.listePrincipale.dispatchEvent(event);
    }

    getControleurListePrincipale() {
        if (this.listePrincipale) {
            return this.application.getControllerForElementAndIdentifier(this.listePrincipale, "liste-principale");
        }
        return null;
    }
}