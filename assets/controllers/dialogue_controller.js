// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, defineIcone, EVEN_ACTION_AFFICHER_MESSAGE, EVEN_ACTION_DIALOGUE_FERMER, EVEN_ACTION_DIALOGUE_OUVRIR, EVEN_QUESTION_NO, EVEN_QUESTION_OK, EVEN_QUESTION_SUPPRIMER, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
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
        this.listePrincipale.removeEventListener(EVEN_QUESTION_SUPPRIMER, this.handleItemCanSupprimer.bind(this));
        this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_OUVRIR, this.handleItemCanAjouter.bind(this));
        this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_FERMER, this.handleFermerBoite.bind(this));
        this.listePrincipale.removeEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
    }


    init() {
        this.listePrincipale = document.getElementById("liste");
        this.initBoiteDeDialogue();
        this.setEcouteurs();
    }

    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        this.listePrincipale.addEventListener(EVEN_QUESTION_SUPPRIMER, this.handleItemCanSupprimer.bind(this));
        this.listePrincipale.addEventListener(EVEN_ACTION_DIALOGUE_OUVRIR, this.handleItemCanAjouter.bind(this));
        this.listePrincipale.addEventListener(EVEN_ACTION_DIALOGUE_FERMER, this.handleFermerBoite.bind(this));
        this.listePrincipale.addEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
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
        const { titre, idObjet, action, entreprise, utilisateur, rubrique, controleurphp, controleurstimulus } = event.detail; // Récupère les données de l'événement
        this.titre = titre;
        this.message = titre;
        this.action = action; //Ajouter (0), Modifier (1)
        this.titreTarget.innerHTML = titre;
        // this.formTarget.innerHTML = "On va charger le formulaire de saisie de données ici!!!!";
        defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, action == 0 ? "ENREGISTRER" : "METTRE A JOUR");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");
        this.action_afficherMessage(titre, "Chargement du formulaire...");
        console.log(this.nomControleur + " - Chargement du formulaire...");
        const url = '/admin/' + controleurphp + '/formulaire/' + entreprise + '/' + idObjet;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.formTarget.innerHTML = html;
                this.showDialogue();
                this.action_afficherMessage(titre, "Formulaire chargé sur la boîte de dialogue.");
                console.log(this.nomControleur + " - Formulaire chargé sur la boîte de dialogue.");
            });
    }

    initBoiteDeDialogue() {
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
        buildCustomEventForElement(this.listePrincipale, EVEN_QUESTION_NO, true, true,
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
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_AFFICHER_MESSAGE, true, true,
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
        buildCustomEventForElement(this.listePrincipale, EVEN_QUESTION_OK, true, true,
            {
                titre: this.titre,
                message: this.message,
                action: this.action,
                data: this.tabSelectedCheckBoxes,
            }
        );
    }
}