import { Controller } from '@hotwired/stimulus';
import { EVEN_ACTION_AJOUTER, EVEN_ACTION_COCHER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_ENREGISTRER, EVEN_ACTION_DIALOGUE_FERMER, EVEN_ACTION_MODIFIER, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SELECTIONNER, EVEN_ACTION_SUPPRIMER, EVEN_QUESTION_NO, EVEN_QUESTION_OK, EVEN_ACTION_DIALOGUE_OUVRIR, EVEN_QUESTION_SUPPRIMER, buildCustomEventForElement, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_SUPPRESSION, EVEN_CODE_RESULTAT_OK, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_LISTE_PRINCIPALE_ADDED, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESHED, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSED, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_BOITE_DIALOGUE_SUBMITTED, EVEN_SERVER_RESPONSED, EVEN_BOITE_DIALOGUE_CLOSE, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, EVEN_CHECKBOX_ELEMENT_CHECKED, EVEN_CHECKBOX_ELEMENT_UNCHECKED, EVEN_CHECKBOX_PUBLISH_SELECTION } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'display',          //Champ d'affichage d'informations
        'donnees',          //Liste conténant des élements
    ];
    static values = {
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
        controleurphp: String,
        controleursitimulus: String,
    };


    connect() {
        this.nomControleur = "LISTE-PRINCIPALE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }



    init() {
        this.listePrincipale = document.getElementById("liste");
        this.tabSelectedCheckBoxs = [];
        this.initToolTips();
        this.updateMessage("Prêt.");
        this.setEcouteurs();
    }


    setEcouteurs() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ADD_REQUEST, this.handleAddRequest.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ADDED, this.handleAdded.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, this.handleRefreshRequest.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_REFRESHED, this.handleRefreshed.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, this.handleAllCheckRequest.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECKED, this.handleAllChecked.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, this.handleSettingRequest.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, this.handleSettingUpdated.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, this.handleCloseRequest.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_CLOSED, this.handleClosed.bind(this));
        document.addEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));
        document.addEventListener(EVEN_SERVER_RESPONSED, this.handleServerResponsed.bind(this));
        document.addEventListener(EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.addEventListener(EVEN_CHECKBOX_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.addEventListener(EVEN_CHECKBOX_ELEMENT_UNCHECKED, this.handleUnChecked.bind(this));



        // this.listePrincipale.addEventListener(EVEN_ACTION_AJOUTER, this.handleItemAjouter.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_COCHER, this.handleItemCocher.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_QUITTER, this.handleQuitter.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_PARAMETRER, this.handleParametrer.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_RECHARGER, this.handleRecharger.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_MODIFIER, this.handleItemModifier.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_SUPPRIMER, this.handleItemSupprimer.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_SELECTIONNER, this.handleItemSelectionner.bind(this));
        // this.listePrincipale.addEventListener(EVEN_QUESTION_NO, this.handleDialog_no.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_COCHER_TOUT, this.handleItemToutCocher.bind(this));
        // this.listePrincipale.addEventListener(EVEN_RESULTAT_SUCCESS, this.handleFormulaireAjoutModifReussi.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ADD_REQUEST, this.handleAddRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ADDED, this.handleAdded.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, this.handleRefreshRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_REFRESHED, this.handleRefreshed.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, this.handleAllCheckRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECKED, this.handleAllChecked.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, this.handleSettingRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, this.handleSettingUpdated.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, this.handleCloseRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_CLOSED, this.handleClosed.bind(this));
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.notify.bind(this));
        document.removeEventListener(EVEN_SERVER_RESPONSED, this.handleServerResponsed.bind(this));
        document.removeEventListener(EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, this.handleCheckRequest.bind(this));
        document.removeEventListener(EVEN_CHECKBOX_ELEMENT_CHECKED, this.handleChecked.bind(this));
        document.removeEventListener(EVEN_CHECKBOX_ELEMENT_UNCHECKED, this.handleUnChecked.bind(this));


        // this.listePrincipale.removeEventListener(EVEN_ACTION_AJOUTER, this.handleItemAjouter.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_COCHER, this.handleItemCocher.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_QUITTER, this.handleQuitter.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_PARAMETRER, this.handleParametrer.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_RECHARGER, this.handleRecharger.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_MODIFIER, this.handleItemModifier.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_SUPPRIMER, this.handleItemSupprimer.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_SELECTIONNER, this.handleItemSelectionner.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_QUESTION_OK, this.handleDialog_ok.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_QUESTION_NO, this.handleDialog_no.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_AFFICHER_MESSAGE, this.handleDisplayMessage.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_COCHER_TOUT, this.handleItemToutCocher.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_RESULTAT_SUCCESS, this.handleFormulaireAjoutModifReussi.bind(this));
    }


    handleAddRequest(event) {
        const { titre, action } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleAddRequest", event.detail);
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            titre: titre,
            action: action,
            controleurPhp: this.controleurphpValue,
            idEntreprise: this.identrepriseValue,
            rubrique: this.rubriqueValue,
        });
    }

    handleAdded(event) {
        console.log(this.nomControleur + " - HandleAdded", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Prêt", message: "Element ajouté avec succès."
        })
    }

    handleAllCheckRequest(event) {
        console.log(this.nomControleur + " - HandleAllCheckRequest");
    }

    handleAllChecked(event) {
        console.log(this.nomControleur + " - HandleAllChecked");
    }

    handleCloseRequest(event) {
        console.log(this.nomControleur + " - HandleCloseRequest");
        this.updateMessage("Fermeture: " + "Redirection à la page d'acceuil...");
        window.location.href = "/admin/entreprise";
    }

    handleClosed(event) {
        console.log(this.nomControleur + " - HandleClosed");
        event.stopPropagation();
    }

    notify(event) {
        const { titre, message } = event.detail;
        this.updateMessage(titre + ": " + message);
    }

    handleRefreshRequest(event) {
        console.log(this.nomControleur + " - HandleRefreshRequest", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESHED, true, true, event.detail);
    }

    handleSettingRequest(event) {
        event.stopPropagation();
        console.log(this.nomControleur + " - HandleSettingRequest");
        this.updateMessage("Paramètres: " + "Redirection encours...");
        window.location.href = "/register";
    }

    handleSettingUpdated(event) {
        console.log(this.nomControleur + " - HandleSettingUpdated");
    }





    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleFormulaireAjoutModifReussi(event) {
        const { idObjet, code, message } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - ENREGISTREMENT REUSSI - On recharge la liste");
        if (code == EVEN_CODE_RESULTAT_OK) { //Ok(0), Erreur(1)
            buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_DIALOGUE_FERMER, true, true, {});
            this.outils_recharger(event);
        } else {
            this.updateMessage(code + ": " + message);
        }
        event.stopPropagation();
    }

    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleServerResponsed(event) {
        const { idObjet, code, message } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleServerResponded", event.detail);
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_CLOSE, true, true, event.detail);
        //ACTION AJOUT = 0
        if (code == EVEN_CODE_RESULTAT_OK) {
            // On actualise la liste en y ajoutant l'élément créé
            buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, true, true, event.detail);
            buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ADDED, true, true, event.detail);
        }
        // if (action == EVEN_CODE_ACTION_MODIFICATION) {

        // }
        // //ACTION SUPPRESSION = 2
        // if (action == EVEN_CODE_ACTION_SUPPRESSION) {

        // }
        // event.stopPropagation();
    }


    // /**
    //  * @description Gère l'événement d'ajout.
    //  * @param {CustomEvent} event L'événement personnalisé déclenché.
    //  */
    // handleSubmitted(event) {
    //     const {action, code, message } = event.detail; // Récupère les données de l'événement
    //     console.log(this.nomControleur + " - HandleSubmitted");
    //     //ACTION AJOUT = 0
    //     if (action == EVEN_CODE_ACTION_AJOUT) {
    //         this.execution_ajout(event);
    //     }
    //     if (action == EVEN_CODE_ACTION_MODIFICATION) {
    //         this.execution_modification(event);
    //     }
    //     //ACTION SUPPRESSION = 2
    //     if (action == EVEN_CODE_ACTION_SUPPRESSION) {
    //         this.execution_suppression(event);
    //     }
    //     event.stopPropagation();
    // }

    execution_suppression(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        // #[Route('/remove_many/{idEntreprise}/{tabIDString}', name: 'remove_many', requirements: ['idEntreprise' => Requirement:: DIGITS])]
        let tabIds = [];
        data.forEach(dataElement => {
            tabIds.push(dataElement.split("check_")[1]);
        });
        const url = '/admin/' + this.controleurphpValue + '/remove_many/' + this.identrepriseValue + '/' + tabIds;
        console.log(this.nomControleur + " - Exécution de la suppression", data, url);
        this.action_afficherMessage("Suppression", "Suppression en cours... Merci de patienter.");
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.json())
            .then(ServerJsonData => {
                const serverJsonObject = JSON.parse(ServerJsonData);
                console.log(this.nomControleur + " - Réponse du serveur: ", serverJsonObject);
                if (serverJsonObject.reponse == "Ok") {
                    //On demande de fermer la boite de dialogue
                    buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_DIALOGUE_FERMER, true, true, {});
                    //On actualise la liste sans consulter le serveur
                    serverJsonObject.deletedIds.forEach(deletedId => {
                        let elementToDelete = document.getElementById("liste_row_" + deletedId);
                        let parentElement = elementToDelete.parentNode;
                        if (elementToDelete) {
                            if (parentElement) {
                                parentElement.removeChild(elementToDelete);
                                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf("check_" + deletedId), 1);
                            }
                        }
                    });
                    this.action_afficherMessage("Suppression", "Bien fait: " + serverJsonObject.message);
                }
            });
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDialog_no(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, titre, message, action, data, "ON NE FAIT RIEN.");
        console.log(this.nomControleur + " - ON NE FAIT RIEN.");
        this.updateMessage("Boîte de dialogue fermée.");
        // Tu peux aussi prévenir la propagation de l'événement si nécessaire
    }

    /**
     * 
     * @param {String} titre 
     * @param {String} textMessage 
     */
    action_afficherMessage(titre, textMessage) {
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true,
            {
                titre: titre,
                message: textMessage,
            }
        );
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDisplayMessage(event) {
        const { titre, message } = event.detail; // Récupère les données de l'événement
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, message);
        this.updateMessage(titre + ": " + message);
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSelectionner(event) {
        const { titre, idobjet, isChecked, selectedCheckbox } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, "ID Objet: " + idobjet, "Checked: " + isChecked, "Selected Check Box: " + selectedCheckbox);
        let currentSelectedCheckBoxes = new Set(this.tabSelectedCheckBoxs);
        if (isChecked == true) {
            currentSelectedCheckBoxes.add(String(selectedCheckbox));
        } else {
            currentSelectedCheckBoxes.delete(String(selectedCheckbox));
        }
        this.tabSelectedCheckBoxs = Array.from(currentSelectedCheckBoxes);
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
        event.stopPropagation();
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemAjouter(event) {
        const { titre } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Titre: " + titre);
        event.stopPropagation();
        console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_DIALOGUE_OUVRIR, true, true,
            {
                titre: "Ajout - " + this.rubriqueValue,
                idObjet: -1,
                action: EVEN_CODE_ACTION_AJOUT, //Ajout
                entreprise: this.identrepriseValue,
                utilisateur: this.utilisateurValue,
                rubrique: this.rubriqueValue,
                controleurphp: this.controleurphpValue,
                controleursitimulus: this.controleursitimulusValue,
            }
        );
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemModifier(event) {
        const { titre } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - Titre: " + titre + ", Selected checkbox: " + this.tabSelectedCheckBoxs);
        event.stopPropagation();
        console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_DIALOGUE_OUVRIR, true, true,
            {
                titre: "Edition - " + this.rubriqueValue,
                idObjet: this.tabSelectedCheckBoxs[0].split("check_")[1],
                action: EVEN_CODE_ACTION_MODIFICATION, // Modification
                entreprise: this.identrepriseValue,
                utilisateur: this.utilisateurValue,
                rubrique: this.rubriqueValue,
                controleurphp: this.controleurphpValue,
                controleursitimulus: this.controleursitimulusValue,
            }
        );
    }


    // /**
    //  * @description Gère l'événement de la fermeture de l'espace de travail.
    //  * @param {CustomEvent} event L'événement personnalisé déclenché.
    //  */
    // handleQuitter(event) {
    //     console.log(this.nomControleur + " - EVENEMENT RECU: QUITTER CET ESPACE DE TRAVAIL");
    //     event.stopPropagation();
    //     this.action_afficherMessage("Fermeture", "Redirection à la page d'acceuil...");
    //     window.location.href = "/admin/entreprise";
    // }

    // /**
    //  * @description Gère l'événement de parametrage de la liste.
    //  * @param {CustomEvent} event L'événement personnalisé déclenché.
    //  */
    // handleParametrer(event) {
    //     console.log(this.nomControleur + " - EVENEMENT RECU: PAREMETRER CETTE LISTE");
    //     event.stopPropagation();
    //     this.action_afficherMessage("Paramètres", "Redirection vers la page des paramètres du compte...");
    //     window.location.href = "/register";
    // }

    /**
     * @description Gère l'événement de la recharge ou actualisation de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleRecharger(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: RECHARGER CETTE LISTE");
        event.stopPropagation();
        this.outils_recharger(event);
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSupprimer(event) {
        event.stopPropagation();
        console.log(this.nomControleur + " - EVENEMENT RECU: SUPPRESSION D'ELEMENT(S).");
        var question = "Etes-vous sûr de vouloir supprimer cet élement?";
        if (this.tabSelectedCheckBoxs.length > 1) {
            question = "Etes-vous sûr de vouloir supprimer ces " + this.tabSelectedCheckBoxs.length + " élements séléctionnés?";
        }
        console.log(this.nomControleur + " - On lance un evenement dialogueCanSupprimer");
        buildCustomEventForElement(this.listePrincipale, EVEN_QUESTION_SUPPRIMER, true, true,
            {
                titre: event.detail.titre,
                message: question,
                tabSelectedCheckBoxes: this.tabSelectedCheckBoxs,
            }
        );
    }


    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleChecked(event) {
        const { selectedCheckbox, isChecked } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleChecked", event.detail);
        event.stopPropagation();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Selection",
            message: "Selection de " + selectedCheckbox + ". Total actuel: " + this.tabSelectedCheckBoxs.length + " élément(s).",
        });
        buildCustomEventForElement(document, EVEN_CHECKBOX_PUBLISH_SELECTION, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
    }

    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleUnChecked(event) {
        const { selectedCheckbox, isChecked } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleChecked", event.detail);
        event.stopPropagation();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Selection",
            message: "Retrait de " + selectedCheckbox + ". Total actuel: " + this.tabSelectedCheckBoxs.length + " élément(s).",
        });
        buildCustomEventForElement(document, EVEN_CHECKBOX_PUBLISH_SELECTION, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
    }


    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleCheckRequest(event) {
        const { selectedCheckbox, isChecked } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleCheckRequest", event.detail);
        event.stopPropagation();
        if (isChecked == true) {
            if (this.tabSelectedCheckBoxs.includes(selectedCheckbox)) {
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(selectedCheckbox), 1);
                buildCustomEventForElement(document, EVEN_CHECKBOX_ELEMENT_UNCHECKED, true, true, event.detail);
            } else {
                this.tabSelectedCheckBoxs.push(selectedCheckbox);
                buildCustomEventForElement(document, EVEN_CHECKBOX_ELEMENT_CHECKED, true, true, event.detail);
            }
        } else {
            if (this.tabSelectedCheckBoxs.includes(selectedCheckbox)) {
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(selectedCheckbox), 1);
                buildCustomEventForElement(document, EVEN_CHECKBOX_ELEMENT_UNCHECKED, true, true, event.detail);
            }
        }
        // this.updateMessageSelectedCheckBoxes();
        // this.publierSelection();

        // const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        // checkBoxes.forEach(currentCheckBox => {
        //     currentCheckBox.checked = false;
        // });
        // var checkBox = document.getElementById(idCheckBox);
        // checkBox.checked = true;
        // this.tabSelectedCheckBoxs.push(idCheckBox);
        // console.log(this.nomControleur + " - EVENEMENT RECU: SELECTION. [id.=" + idCheckBox.split("check_")[1] + "]", idCheckBox);
        // this.updateMessageSelectedCheckBoxes();
        // this.publierSelection();
        // event.stopPropagation();
    }


    publierSelection() {
        console.log(this.nomControleur + " - Action_publier séléction - lancée.");
        // buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_NOTIFIER_SELECTION, true, true, { selection: this.tabSelectedCheckBoxs });
    }


    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    /**
     * 
     * @param {string} newMessage 
     */
    updateMessage(newMessage) {
        this.displayTarget.innerHTML = "Résultat: " + this.nbelementsValue + " élement(s) | " + newMessage;
    }


    /**
     * 
     * @param {Event} event 
      */
    handleItemToutCocher(event) {
        event.stopPropagation();
        let isChecked = null;
        if (event.target.getAttribute("type") == "checkbox") {
            isChecked = event.target.checked;
        } else {
            const btCkBox = document.getElementById("myCheckbox");
            btCkBox.checked = !btCkBox.checked;
            isChecked = btCkBox.checked;
        }
        console.log(this.nomControleur + " - TOUT COCHER !!!!", "isChecked?:" + isChecked);
        // const isChecked = event.target.checked;
        const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        this.tabSelectedCheckBoxs = [];
        checkBoxes.forEach(currentCheckBox => {
            currentCheckBox.checked = isChecked;
            if (isChecked == true) {
                this.tabSelectedCheckBoxs.push(currentCheckBox.getAttribute("id"));
            } else {
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(currentCheckBox.getAttribute("id")), 1);
            }
        });
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();
    }

    /**
     * 
     */
    updateMessageSelectedCheckBoxes() {
        if (this.tabSelectedCheckBoxs.length != 0) {
            this.updateMessage(this.tabSelectedCheckBoxs.length + " éléments cochés.");
        }
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_quitter(event) {
        console.log(this.nomControleur + " - Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    outils_parametrer(event) {
        console.log(this.nomControleur + " - Action Barre d'outils:", event.currentTarget);
    }


    /**
     * 
     * @param {Event} event 
     */
    handleRefreshed(event) {
        console.log(this.nomControleur + " - handleRefreshed", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Liste", message: "Actualisation encours..."
        });
        // this.updateMessage("Actualisation des données...");
        this.donneesTarget.disabled = true;
        // this.donneesTarget.innerHTML = "J'actualise cette liste";
        const url = '/admin/' + this.controleurphpValue + '/reload/' + this.identrepriseValue;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.donneesTarget.innerHTML = html;
                this.donneesTarget.disabled = false;

                const maintenant = new Date();
                const dateHeureLocaleSimple = maintenant.toLocaleString();
                // this.updateMessage("Dernière actualisation " + dateHeureLocaleSimple);
                buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
                    titre: "Prêt", message: "Dernière actualisation " + dateHeureLocaleSimple
                });
                this.tabSelectedCheckBoxs = [];
                this.updateMessageSelectedCheckBoxes();
                this.publierSelection();
            });
    }
}