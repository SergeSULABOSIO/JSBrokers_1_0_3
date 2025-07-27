import { Controller } from '@hotwired/stimulus';
import { EVEN_ACTION_AJOUTER, EVEN_ACTION_COCHER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_ENREGISTRER, EVEN_ACTION_DIALOGUE_FERMER, EVEN_ACTION_MODIFIER, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SELECTIONNER, EVEN_ACTION_SUPPRIMER, EVEN_QUESTION_NO, EVEN_QUESTION_OK, EVEN_ACTION_DIALOGUE_OUVRIR, EVEN_QUESTION_SUPPRIMER, buildCustomEventForElement, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_SUPPRESSION, EVEN_CODE_RESULTAT_OK, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_LISTE_PRINCIPALE_ADDED, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESHED, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSED, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_BOITE_DIALOGUE_SUBMITTED, EVEN_SERVER_RESPONSED, EVEN_BOITE_DIALOGUE_CLOSE, EVEN_CHECKBOX_ELEMENT_CHECK_REQUEST, EVEN_CHECKBOX_ELEMENT_CHECKED, EVEN_CHECKBOX_ELEMENT_UNCHECKED, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_EXPANDED, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_DELETED, EVEN_BOITE_DIALOGUE_SUBMIT_REQUEST, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, EVEN_SHOW_TOAST, EVEN_DATA_BASE_SELECTION_REQUEST, EVEN_DATA_BASE_SELECTION_EXECUTED } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'display',          //Champ d'affichage d'informations
        'donnees',          //Liste conténant des élements
        'selectAllCheckbox',
        'rowCheckbox',
    ];
    static values = {
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
        entite: String,
        controleurphp: String,
        controleursitimulus: String,
    };


    connect() {
        this.urlAPIDynamicQuery = "/admin/" + this.controleurphpValue + "/api/dynamic-query";
        this.nomControleur = "LISTE-PRINCIPALE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }



    init() {
        this.menu = document.getElementById("simpleContextMenu");
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
        document.addEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.handleExpanded.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.handleModifyRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.handleDeleteRequest.bind(this));
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETED, this.handleDeleted.bind(this));
        // Ajoute un écouteur d'événement sur l'élément du contrôleur.
        // L'événement va "buller" (bubble up), donc un composant enfant peut le déclencher.
        document.addEventListener(EVEN_DATA_BASE_SELECTION_REQUEST, this.handleDBRequest.bind(this));
        document.addEventListener(EVEN_DATA_BASE_SELECTION_EXECUTED, this.handleDBResult.bind(this));
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
        document.removeEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.handleExpanded.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.handleModifyRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.handleDeleteRequest.bind(this));
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETED, this.handleDeleted.bind(this));
        // Nettoie l'écouteur d'événement lorsque le contrôleur est déconnecté du DOM.
        document.removeEventListener(EVEN_DATA_BASE_SELECTION_REQUEST, this.handleDBRequest.bind(this));
        document.removeEventListener(EVEN_DATA_BASE_SELECTION_EXECUTED, this.handleDBResult.bind(this));
    }



    


    /**
     * Propage un événement de réponse personnalisé contenant les résultats ou une erreur.
     * @param {object[]|null} results - Les données de la base de données.
     * @param {string|null} error - Le message d'erreur, le cas échéant.
     */
    dispatchResponse(results, error) {
        const event = new CustomEvent(EVEN_DATA_BASE_SELECTION_EXECUTED, {
            bubbles: true, // Permet à l'événement de "buller" dans le DOM
            detail: {
                results: results,
                error: error,
                isSuccess: !error,
            }
        });
        document.dispatchEvent(event);
    }


    handleCheckboxChange(event) {
        const checkbox = event.currentTarget;
        const idObjet = checkbox.dataset.idobjetValue;
        const isChecked = checkbox.checked;

        if (isChecked) {
            if (!this.tabSelectedCheckBoxs.includes(idObjet)) {
                this.tabSelectedCheckBoxs.push(idObjet);
            }
        } else {
            const index = this.tabSelectedCheckBoxs.indexOf(idObjet);
            if (index > -1) {
                this.tabSelectedCheckBoxs.splice(index, 1);
            }
        }
        this.updateSelectAllCheckboxState();
        this.publierSelection();
    }

    updateSelectAllCheckboxState() {
        const allChecked = this.rowCheckboxTargets.every(checkbox => checkbox.checked);
        const someChecked = this.rowCheckboxTargets.some(checkbox => checkbox.checked);

        if (allChecked) {
            this.selectAllCheckboxTarget.checked = true;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else if (someChecked) {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = true;
        } else {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = false;
        }
    }

    boundHideContextMenu(event) {
        console.log(this.nomControleur + " - boundHideContextMenu");
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_HIDE, true, true, event);
    }

    handleDeleteRequest(event) {
        const { titre, action, selection } = event.detail;
        console.log(this.nomControleur + " - handleDeleteRequest", event.detail);
        var question = "Etes-vous sûr de vouloir supprimer cet élement?";
        if (selection.length > 1) {
            question = "Etes-vous sûr de vouloir supprimer ces " + selection.length + " élements séléctionnés?";
        }
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            titre: titre,
            message: question,
            action: action,
            idObjet: -1,
            selection: selection,
            controleurPhp: this.controleurphpValue,
            controleurSitimulus: this.controleurphpValue,
            idEntreprise: this.identrepriseValue,
            rubrique: this.rubriqueValue,
        });
    }

    handleDeleted(event) {
        console.log(this.nomControleur + " - HandleDeleted", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Prêt", message: "Suppression effectuée avec succès."
        });

        // Déclencher l'événement global pour afficher la notification
        buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, { text: 'Suppression réussie !', type: 'success' });
    }


    handleExpanded(event) {
        console.log(this.nomControleur + " - handleExpanded", event.detail);
        event.stopPropagation();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Sélection",
            message: "Détails affichés pour les élements " + event.detail.selection,
        });
    }


    handleModifyRequest(event) {
        const { titre, action, selectedId } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleModifyRequest", event.detail);
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            titre: titre,
            action: action,
            idObjet: selectedId,
            controleurPhp: this.controleurphpValue,
            idEntreprise: this.identrepriseValue,
            rubrique: this.rubriqueValue,
        });
    }

    handleAddRequest(event) {
        const { titre, action } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handleAddRequest", event.detail);
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            titre: titre,
            action: action,
            idObjet: -1,
            controleurPhp: this.controleurphpValue,
            idEntreprise: this.identrepriseValue,
            rubrique: this.rubriqueValue,
        });
    }

    handleAdded(event) {
        console.log(this.nomControleur + " - HandleAdded", event.detail);
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Prêt", message: "Element ajouté avec succès."
        });

        // Déclencher l'événement global pour afficher la notification
        buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, { text: 'Element ajouté avec succès !', type: 'success' });
    }

    handleAllCheckRequest(event) {
        console.log(this.nomControleur + " - handleAllCheckRequest", event.target);
        event.stopPropagation();
        event.preventDefault();
        if (typeof event.target.getAttribute === 'function') {
            if (event.target.getAttribute("type") == "checkbox") {
                this.isChecked = event.target.checked;
            }
        } else {
            const btCkBox = document.getElementById("myCheckbox");
            btCkBox.checked = !btCkBox.checked;
            this.isChecked = btCkBox.checked;
        }
        const checkBoxes = this.donneesTarget.querySelectorAll('input[type="checkbox"]');
        this.tabSelectedCheckBoxs = [];
        checkBoxes.forEach(currentCheckBox => {
            currentCheckBox.checked = this.isChecked;
            let idObjet = null;
            if (this.isChecked == true) {
                idObjet = (currentCheckBox.getAttribute("id")).split("check_")[1];
                this.tabSelectedCheckBoxs.push(idObjet);
            } else {
                this.tabSelectedCheckBoxs.splice(this.tabSelectedCheckBoxs.indexOf(idObjet), 1);
            }
        });
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
    }

    handleAllChecked(event) {
        console.log(this.nomControleur + " - HandleAllChecked", event);
        buildCustomEventForElement(document, EVEN_CHECKBOX_PUBLISH_SELECTION, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
    }

    handleItemToutCocher(event) {
        this.handleAllCheckRequest(event);
        // buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, true, true, {});
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
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleDialog_no(event) {
        const { titre, message, action, data } = event.detail; // Récupère les données de l'événement
        event.stopPropagation();
        // console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, titre, message, action, data, "ON NE FAIT RIEN.");
        // console.log(this.nomControleur + " - ON NE FAIT RIEN.");
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
        // console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, message);
        this.updateMessage(titre + ": " + message);
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemSelectionner(event) {
        const { titre, idobjet, isChecked, selectedCheckbox } = event.detail; // Récupère les données de l'événement
        // console.log(this.nomControleur + " - EVENEMENT RECU: " + titre, "ID Objet: " + idobjet, "Checked: " + isChecked, "Selected Check Box: " + selectedCheckbox);
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
        // console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
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
        // console.log(this.nomControleur + " - On lance un evenement dialogueCanAjouter");
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


    /**
     * @description Gère l'événement de la recharge ou actualisation de la liste.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleRecharger(event) {
        console.log(this.nomControleur + " - EVENEMENT RECU: RECHARGER CETTE LISTE");
        event.stopPropagation();
        this.outils_recharger(event);
    }


    


    publierSelection() {
        console.log(this.nomControleur + " - Action_publier séléction - lancée.");
        buildCustomEventForElement(document, EVEN_CHECKBOX_PUBLISH_SELECTION, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
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
     * Gère l'événement de demande de recherche.
     * @param {CustomEvent} event - L'événement doit contenir { detail: { entityName: '...', criteria: {...} } }
     */
    async handleDBRequest(event) {
        const { criteria } = event.detail;
        const entityName = this.entiteValue;

        console.log(this.nomControleur + " - ICI: ", entityName, criteria);

        if (!entityName || !criteria) {
            console.error('Event "app:base-données:sélection-request" is missing "entityName" or "criteria" in detail.', event.detail);
            this.dispatchResponse(null, 'Missing parameters in event detail.');
            return;
        }

        try {
            const response = await fetch(this.urlAPIDynamicQuery, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ entityName, criteria }),
            });

            const responseData = await response.json();

            if (!response.ok) {
                // Gère les erreurs HTTP (4xx, 5xx)
                console.error(this.nomControleur + ' - Error from server:', responseData);
                this.dispatchResponse(null, responseData.error || `HTTP error! Status: ${response.status}`);
            } else {
                // Succès : propage les résultats
                this.dispatchResponse(responseData.data, null);
            }

        } catch (error) {
            // Gère les erreurs réseau ou de parsing JSON
            console.error(this.nomControleur + ' - Fetch error:', error);
            this.dispatchResponse(null, error.message);
        }
    }


    handleDBResult(event) {
        const { results, error, isSuccess } = event.detail;
        console.log(this.nomControleur + " - handleDBResult", event.detail);
        //Ici on redessine la liste des données
        this.donneesTarget.innerText = "ICICIC - SULA - Il faut cherger les données.";
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
        this.donneesTarget.disabled = true;
        const url = '/admin/' + this.controleurphpValue + '/reload/' + this.identrepriseValue;
        fetch(url) // Remplacez par l'URL de votre formulaire
            .then(response => response.text())
            .then(html => {
                this.donneesTarget.innerHTML = html;
                this.donneesTarget.disabled = false;

                const maintenant = new Date();
                const dateHeureLocaleSimple = maintenant.toLocaleString();
                buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
                    titre: "Prêt", message: "Dernière actualisation " + dateHeureLocaleSimple
                });

                const btCkBox = document.getElementById("myCheckbox");
                btCkBox.checked = false;

                this.tabSelectedCheckBoxs = [];
                this.updateMessageSelectedCheckBoxes();
                this.publierSelection();

                // Déclencher l'événement global pour afficher la notification
                buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, { text: 'Liste actualisée avec succès !', type: 'info' });
            });
    }
}