// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { defineIcone, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
import { Modal } from 'bootstrap'; // ou import { Modal } from 'bootstrap'; si vous voulez seulement Modal

export default class extends Controller {
    /**
     * Action [0=New, 1=Edit, 2=Delete, 3=Delete Multiple]
     */
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


    init() {
        /**
         * LES VARIABLES GLOBALES
         */
        this.ADD = 0;
        this.EDIT = 1;
        this.DELETE_SINGLE = 2;
        this.DELETE_MULTIPLE = 3;

        this.controleurenfant = "";
        this.identreprise = -1;
        this.action = -1;
        this.objet = -1;
        this.titre = "";
        this.controleurDeLaListePrincipale = this.getControleurListePrincipale();
        // Initialisation
        this.initBoutonValidationFermer();
        this.initBoiteDeDialogue();
    }


    initBoutonValidationFermer() {
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");
        defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
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
     * 
     * @param {Number} action 
     */
    customizeSubmitionButtons(action) {
        if (action == this.ADD || action == this.EDIT) {
            if (action == this.ADD) {
                this.updateMessage("Opération: Ajout d'un élément.");
                defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "ENREGISTRER");
            } else {
                this.updateMessage("Opération: Edition de l'élément ID: " + this.objet + ".");
                defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, "METTRE A JOUR");
            }
        } else if (action == this.DELETE_SINGLE || action == this.DELETE_MULTIPLE) {
            this.controleurDeLaListePrincipale.updateMessage("Opération de suppression déclanchée. Merci de confirmer dans la boîte de dialogue.");
            defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "SUPPRIMER");
        }
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");
    }


    /**
     * 
     * @param {Event} event 
     */
    open(event) {
        event.preventDefault();
        //A chaque fois que la boite de dialogue est appelée à s'ouvrir
        //On doit s'assurer que celle-ci s'est connectée au controleur de la liste principale
        if (this.controleurDeLaListePrincipale == null) {
            this.controleurDeLaListePrincipale = this.getControleurListePrincipale();
        }
        this.action = event.currentTarget.dataset.itemAction;
        this.objet = event.currentTarget.dataset.itemObjet;
        this.titre = event.currentTarget.dataset.itemTitre;

        this.titreTarget.innerHTML = this.titre;
        this.formTarget.innerHTML = "Veuillez patienter svp...";

        //Ouverture de la boite de dialogue
        this.showDialogue();
        this.customizeSubmitionButtons(this.action);

        // * Opération Ajout (0) ou Modification (1)
        if (this.action == this.ADD || this.action == this.EDIT) {
            this.loadAddEditFormFromServer();
        }
        // * Opération Suppression (2) ou Suppression Multiple (3)
        if (this.action == this.DELETE_SINGLE || this.action == this.DELETE_MULTIPLE) {
            var messageDeletion = "";
            const selectedCheckBoxes = this.controleurDeLaListePrincipale.tabSelectedCheckBoxs;
            if (selectedCheckBoxes.length != 0) {
                messageDeletion += "Etes-vous sûr de vouloir supprimer cette séléction de " + selectedCheckBoxes.length + " élément(s)?";
            } else {
                messageDeletion = "Etes-vous sûre de vouloir supprimer cet élément?";
            }
            this.formTarget.innerHTML = messageDeletion;
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
        // Edition
        if (this.action == this.EDIT) {
            this.controleurDeLaListePrincipale.actualiserElement(this.objet);
        }
        // Suppression
        if (this.action == this.DELETE_SINGLE || this.action == this.DELETE_MULTIPLE) {
            this.controleurDeLaListePrincipale.updateMessage("Suppression annulée.");
        }
        this.closeDialogue();
    }



    // Méthode pour obtenir l'instance du contrôleur enfant
    getControlleurEnfantSpecial(nom) {
        // Vérifie que l'élément 'form' est bien défini comme target
        if (this.hasFormTarget) {
            console.log("this.hasFormTarget: ", this.hasFormTarget);
            return this.application.getControllerForElementAndIdentifier(this.formTarget.firstElementChild, nom);
        }
        return null;
    }



    getControleurListePrincipale() {
        const listePrincipale = document.getElementById("liste");
        if (listePrincipale) {
            return this.application.getControllerForElementAndIdentifier(listePrincipale, "liste-principale");
        }
        return null;
    }



    /**
     * @param {Event} event 
    */
    submit(event) {
        //Action: Ajout (0) ou Modification (1)
        if (this.action == this.ADD || this.action == this.EDIT) {
            console.log("Controleur enfant actuel:", this.controleurenfant);
            this.controleurenfant.triggerFromParent(event);
        }
        //Action: Suppression simple (2) ou Suppression Multiple (3)
        if (this.action == this.DELETE_SINGLE || this.action == this.DELETE_MULTIPLE) {
            if (this.action == this.DELETE_SINGLE) {
                this.controleurDeLaListePrincipale.supprimerElement(this.objetValue);
            }
            if (this.actionValue == this.DELETE_MULTIPLE) {
                this.controleurDeLaListePrincipale.outils_supprimer(event);
            }
            this.closeDialogue();
        }
    }
}