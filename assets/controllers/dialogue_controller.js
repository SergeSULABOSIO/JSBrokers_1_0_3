// assets/controllers/dialogue_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, defineIcone, EVEN_ACTION_AFFICHER_MESSAGE, EVEN_ACTION_DIALOGUE_FERMER, EVEN_ACTION_DIALOGUE_OUVRIR, EVEN_BOITE_DIALOGUE_CANCEL_REQUEST, EVEN_BOITE_DIALOGUE_CANCELLED, EVEN_BOITE_DIALOGUE_CLOSE, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_BOITE_DIALOGUE_INITIALIZED, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, EVEN_BOITE_DIALOGUE_SUBMITTED, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_CODE_RESULTAT_OK, EVEN_LISTE_ELEMENT_DELETED, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_QUESTION_NO, EVEN_QUESTION_OK, EVEN_QUESTION_SUPPRIMER, getIconeUrl } from './base_controller.js'; // après que l'importation soit automatiquement pas VS Code, il faut ajouter l'extension ".js" à la fin!!!!
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
        this.idEntreprise = -1;
        this.idObjet = -1;
        this.controleurPhp = "";
        this.controleurStimulus = "";
        this.rubrique = "";
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
        document.addEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_CLOSE, this.handleClose.bind(this));
        document.addEventListener(EVEN_BOITE_DIALOGUE_SUBMITTED, this.handleSubmitted.bind(this));


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
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_CLOSE, this.handleClose.bind(this));
        document.removeEventListener(EVEN_BOITE_DIALOGUE_SUBMITTED, this.handleSubmitted.bind(this));


        // this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_OUVRIR, this.handleItemCanAjouter.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_DIALOGUE_FERMER, this.handleFermerBoite.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
    }


    notify(event) {
        const { titre, message } = event.detail;
        console.log(this.nomControleur + " - Notify");
        this.updateMessage(titre + ": " + message);
    }

    handleSubmitted(event) {
        console.log(this.nomControleur + " - HandleSubmitted", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Soumission",
            message: "Demande soumise. Veuillez patienter...",
        });
    }

    handleCancelRequest(event) {
        console.log(this.nomControleur + " - HandleCancelRequest", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Anullation",
            message: "Demande soumise. Veuillez patienter...",
        });
    }

    handleCancelled(event) {
        console.log(this.nomControleur + " - HandleCancelled", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Annullation",
            message: "Annullé avec succès.",
        });
    }

    handleInitRequest(event) {
        const { titre, action, idObjet, controleurPhp, idEntreprise, rubrique } = event.detail;
        this.action = action;
        let messageInitial = "Initialisation du formulaire d'édition";
        console.log(this.nomControleur + " - HandleInitRequest", event.detail);
        this.showDialogue();
        if (action === EVEN_CODE_ACTION_AJOUT || action === EVEN_CODE_ACTION_MODIFICATION) {
            this.loadFormulaireEdition(titre, messageInitial, action, idObjet, controleurPhp, idEntreprise, rubrique);
        } else {
            this.loadSuppressionQuestion(event);
        }
    }

    loadSuppressionQuestion(event) {
        const { titre, message, action, idObjet, selection, controleurPhp, controleurStimulus, idEntreprise, rubrique } = event.detail;
        this.tabSelectedCheckBoxes = selection;
        this.controleurPhp = controleurPhp;
        this.controleurStimulus = controleurStimulus;
        this.idEntreprise = idEntreprise;
        this.rubrique = rubrique;
        this.idObjet = idObjet;
        this.titre = titre;
        this.message = message;
        this.action = action;
        this.titreTarget.innerHTML = titre;
        this.formTarget.innerHTML = message;
        defineIcone(getIconeUrl(1, "delete", 19), this.btSubmitTarget, "SUPPRIMER");
        defineIcone(getIconeUrl(1, "exit", 19), this.btFermerTarget, "NON");
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
        this.formTarget.innerHTML = "Contruction du formulaire. Patientez svp...";
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
    handleClose(event) {
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
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, { titre: "Soumission", message: "Demande initié" });
        if (this.action === EVEN_CODE_ACTION_SUPPRESSION) {
            this.execution_suppression();
        } else {
            buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, true, true, {
                titre: this.titre,
                action: this.action,
                message: this.message,
                selection: this.tabSelectedCheckBoxes,
            });
        }
    }

    execution_suppression() {
        // const { selection } = event.detail; // Récupère les données de l'événement
        // #[Route('/remove_many/{idEntreprise}/{tabIDString}', name: 'remove_many', requirements: ['idEntreprise' => Requirement:: DIGITS])]
        // let tabIds = selection;
        // data.forEach(dataElement => {
        //     tabIds.push(dataElement.split("check_")[1]);
        // });
        const url = '/admin/' + this.controleurPhp + '/remove_many/' + this.idEntreprise + '/' + this.tabSelectedCheckBoxes;
        console.log(this.nomControleur + " - Exécution de la suppression", this.tabSelectedCheckBoxes, url);
        //Notification
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Suppression",
            message: "Suppression en cours... Merci de patienter.",
        });

        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(ServerJsonData => {
                const serverJsonObject = JSON.parse(ServerJsonData);
                // console.log(this.nomControleur + " - Réponse du serveur: ", serverJsonObject);
                if (serverJsonObject.reponse == "Ok") {
                    //Notification
                    buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
                        titre: "Actualisation",
                        message: "Suppression Réussie! Veuillez patienter svp...",
                    });

                    //On actualise la liste sans consulter le serveur
                    serverJsonObject.deletedIds.forEach(deletedId => {
                        let elementToDelete = document.getElementById("liste_row_" + deletedId);
                        if (elementToDelete) {
                            let parentElement = elementToDelete.parentNode;
                            if (parentElement) {
                                parentElement.removeChild(elementToDelete);
                                this.tabSelectedCheckBoxes.splice(this.tabSelectedCheckBoxes.indexOf(deletedId), 1);
                            }
                        }
                    });
                    //Notification
                    buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
                        titre: "Suppression",
                        message: "Bien fait: " + serverJsonObject.message,
                    });
                    buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETED, true, true, {
                        selection: this.tabSelectedCheckBoxes,
                        rubrique: this.rubrique,
                    });
                    this.closeDialogue();
                }
            });
    }
}