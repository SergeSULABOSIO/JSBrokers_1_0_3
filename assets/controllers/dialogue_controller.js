// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, defineIcone, EVEN_ACTION_AFFICHER_MESSAGE, EVEN_ACTION_DIALOGUE_FERMER, EVEN_ACTION_DIALOGUE_OUVRIR, EVEN_BOITE_DIALOGUE_CANCEL_REQUEST, EVEN_BOITE_DIALOGUE_CANCELLED, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_BOITE_DIALOGUE_INITIALIZED, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, EVEN_BOITE_DIALOGUE_SUBMITTED, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_CODE_RESULTAT_OK, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_QUESTION_NO, EVEN_QUESTION_OK, EVEN_QUESTION_SUPPRIMER, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
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


    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        document.addEventListener(EVEN_BOITE_DIALOGUE_CANCEL_REQUEST, this.handleCancelRequest.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_CANCELLED, this.handleCancelled.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.handleInitRequest.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_INITIALIZED, this.handleInitialized.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, this.handleSubmitRequest.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_SUBMITTED, this.handleSubmited.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));


        // this.listePrincipale.addEventListener(EVEN_QUESTION_SUPPRIMER, this.handleItemCanSupprimer.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_DIALOGUE_OUVRIR, this.handleItemCanAjouter.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_DIALOGUE_FERMER, this.handleFermerBoite.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
    }



    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_BOITE_DIALOGUE_CANCEL_REQUEST, this.handleCancelRequest.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_CANCELLED, this.handleCancelled.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.handleInitRequest.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INITIALIZED, this.handleInitialized.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, this.handleSubmitRequest.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_SUBMITTED, this.handleSubmited.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));


        // this.listePrincipale.removeEventListener(EVEN_QUESTION_SUPPRIMER, this.handleItemCanSupprimer.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_OUVRIR, this.handleItemCanAjouter.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_FERMER, this.handleFermerBoite.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
    }


    notify(event) {
        const { titre, message } = event.detail;
        this.updateMessage(titre + ": " + message);
    }

    handleCancelRequest(event) {
        console.log(this.nomControleur + " - HandleCancelRequest");
    }

    handleSubmitRequest(event) {
        const {action} = event.detail;
        console.log(this.nomControleur + " - HandleSubmitRequest");
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_SUBMITTED, true, true,{action: action, code: EVEN_CODE_RESULTAT_OK, message: "Enregistré avec succès."});
    }

    handleSubmited(event) {
        const {action, code, message} = event.detail;
        console.log(this.nomControleur + " - HandleSubmited");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true,{titre: "Résultat", message: message});
        this.closeDialogue();
    }

    handleCancelled(event) {
        console.log(this.nomControleur + " - HandleCancelled");
    }

    handleInitRequest(event) {
        const { titre, action, controleurPhp, idEntreprise, rubrique } = event.detail;
        this.action = action;
        console.log(this.nomControleur + " - HandleInitRequest");
        console.log("Titre: " + titre, "Action: " + action);
        this.showDialogue();
        switch (action) {
            case EVEN_CODE_ACTION_AJOUT:
                this.loadFormulaireEdition(
                    titre,
                    "Initialisation du formulaire d'édition",
                    action,
                    -1,
                    controleurPhp,
                    idEntreprise,
                    rubrique
                );
                break;

            default:
                break;
        }
    }

    generateSubmissionBtLabel(action) {
        var titreBt = "";
        switch (action) {
            case EVEN_CODE_ACTION_AJOUT:
                titreBt = "ENREGISTRER";
                break;
            case EVEN_CODE_ACTION_MODIFICATION:
                titreBt = "METTRE A JOUR";
                break;
            case EVEN_CODE_ACTION_SUPPRESSION:
                titreBt = "SUPPRIMER";
                break;
            default:
                break;
        }
        return titreBt;
    }

    loadFormulaireEdition(Stitre, Smessage, Saction, idObjet, controleurPhp, idEntreprise, rubrique) {
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, { titre: Stitre, message: Smessage });
        this.titreTarget.innerHTML = Stitre + " - " + rubrique;
        defineIcone(getIconeUrl(1, "save", 19), this.btSubmitTarget, this.generateSubmissionBtLabel(Saction));
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "FERMER");
        const url = '/admin/' + controleurPhp + '/formulaire/' + idEntreprise + '/' + idObjet;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.formTarget.innerHTML = html;
                buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INITIALIZED, true, true, {});
            });
    }

    handleInitialized(event) {
        console.log(this.nomControleur + " - HandleInitialized");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, { titre: "Etat", message: "Initialisé et prêt." });
    }


    init() {
        this.listePrincipale = document.getElementById("liste");
        this.initBoiteDeDialogue();
        this.setEcouteurs();
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
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true,{titre:"Soumission", message:"Demande initié"});
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, true, true,{action: this.action});
    }
}