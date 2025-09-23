import { Controller } from '@hotwired/stimulus';
import { 
    EVEN_ACTION_DIALOGUE_FERMER, 
    EVEN_ACTION_DIALOGUE_OUVRIR, 
    buildCustomEventForElement, 
    EVEN_CODE_ACTION_MODIFICATION, 
    EVEN_CODE_ACTION_AJOUT, 
    EVEN_CODE_RESULTAT_OK, 
    EVEN_LISTE_PRINCIPALE_ADD_REQUEST, 
    EVEN_BOITE_DIALOGUE_INIT_REQUEST, 
    EVEN_LISTE_PRINCIPALE_ADDED, 
    EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, 
    EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, 
    EVEN_LISTE_PRINCIPALE_ALL_CHECKED, 
    EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, 
    EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, 
    EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, 
    EVEN_LISTE_PRINCIPALE_CLOSED, 
    EVEN_LISTE_PRINCIPALE_NOTIFY, 
    EVEN_SERVER_RESPONSED, 
    EVEN_BOITE_DIALOGUE_CLOSE, 
    EVEN_CHECKBOX_PUBLISH_SELECTION, 
    EVEN_LISTE_ELEMENT_EXPANDED, 
    EVEN_LISTE_ELEMENT_MODIFY_REQUEST, 
    EVEN_LISTE_ELEMENT_DELETE_REQUEST, 
    EVEN_LISTE_ELEMENT_DELETED, 
    EVEN_MENU_CONTEXTUEL_HIDE, 
    EVEN_SHOW_TOAST, 
    EVEN_DATA_BASE_SELECTION_REQUEST, 
    EVEN_DATA_BASE_SELECTION_EXECUTED, 
    EVEN_DATA_BASE_DONNEES_LOADED, 
    EVEN_LISTE_ELEMENT_OPEN_REQUEST, 
    EVEN_LISTE_ELEMENT_OPENNED 
} from './base_controller.js';

export default class extends Controller {
    static targets = [
        'donnees',          //Liste conténant des élements
        'selectAllCheckbox',
        'rowCheckbox',
    ];
    static values = {
        objet: Object,
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
        entite: String,
        controleurphp: String,
        controleursitimulus: String,
        entityFormCanvas: Object,
    };


    connect() {
        this.urlAPIDynamicQuery = "/admin/" + this.controleurphpValue + "/api/dynamic-query/" + this.identrepriseValue;
        this.nomControleur = "LISTE-PRINCIPALE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
        
        // --- AJOUT : Le "récepteur" pour restaurer l'état de la sélection ---
        // On met le contrôleur à l'écoute de l'événement de sélection global.
        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleGlobalSelectionUpdate);
    }


    init() {
        this.tabSelectedEntities = [];
        this.selectedEntitiesType = null;
        this.selectedEntitiesCanvas = null;
        this.tabSelectedCheckBoxs = [];

        this.menu = document.getElementById("simpleContextMenu");
        this.listePrincipale = document.getElementById("liste");
        this.initToolTips();
        this.updateMessage("Prêt.");
        this.setEcouteurs();
    }


    setEcouteurs() {
        this.boundHandleAddRequest = this.handleAddRequest.bind(this);
        this.boundHandleAdded = this.handleAdded.bind(this);
        this.boundHandleAllCheckRequest = this.handleAllCheckRequest.bind(this);
        this.boundHandleAllChecked = this.handleAllChecked.bind(this);
        this.boundHandleSettingRequest = this.handleSettingRequest.bind(this);
        this.boundHandleSettingUpdated = this.handleSettingUpdated.bind(this);
        this.boundHandleCloseRequest = this.handleCloseRequest.bind(this);
        this.boundHandleClosed = this.handleClosed.bind(this);
        this.boundNotify = this.notify.bind(this);
        this.boundHandleServerResponsed = this.handleServerResponsed.bind(this);
        this.boundHandleExpanded = this.handleExpanded.bind(this);
        this.boundHandleModifyRequest = this.handleModifyRequest.bind(this);
        this.boundHandleDeleteRequest = this.handleDeleteRequest.bind(this);
        this.boundHandleDeleted = this.handleDeleted.bind(this);
        this.boundHandleDBRequest = this.handleDBRequest.bind(this);
        this.boundHandleDBResult = this.handleDBResult.bind(this);
        this.boundHandleDonneesLoaded = this.handleDonneesLoaded.bind(this);
        this.boundHandleOpenRequest = this.handleOpenRequest.bind(this);

        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ADD_REQUEST, this.boundHandleAddRequest);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ADDED, this.boundHandleAdded);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, this.boundHandleAllCheckRequest);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECKED, this.boundHandleAllChecked);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, this.boundHandleSettingRequest);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, this.boundHandleSettingUpdated);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, this.boundHandleCloseRequest);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_CLOSED, this.boundHandleClosed);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.boundNotify);
        document.addEventListener(EVEN_SERVER_RESPONSED, this.boundHandleServerResponsed);
        document.addEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.boundHandleExpanded);
        document.addEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.boundHandleModifyRequest);
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.boundHandleDeleteRequest);
        document.addEventListener(EVEN_LISTE_ELEMENT_DELETED, this.boundHandleDeleted);
        document.addEventListener(EVEN_DATA_BASE_SELECTION_REQUEST, this.boundHandleDBRequest);
        document.addEventListener(EVEN_DATA_BASE_SELECTION_EXECUTED, this.boundHandleDBResult);
        document.addEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundHandleDonneesLoaded);
        document.addEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);

    }

    disconnect() {
        //On attache les écouteurs d'Evenements personnalisés à la liste principale
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ADD_REQUEST, this.boundHandleAddRequest);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ADDED, this.boundHandleAdded);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, this.boundHandleAllCheckRequest);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_ALL_CHECKED, this.boundHandleAllChecked);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, this.boundHandleSettingRequest);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_SETTINGS_UPDATED, this.boundHandleSettingUpdated);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, this.boundHandleCloseRequest);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_CLOSED, this.boundHandleClosed);
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_NOTIFY, this.boundNotify);
        document.removeEventListener(EVEN_SERVER_RESPONSED, this.boundHandleServerResponsed);
        document.removeEventListener(EVEN_LISTE_ELEMENT_EXPANDED, this.boundHandleExpanded);
        document.removeEventListener(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, this.boundHandleModifyRequest);
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETE_REQUEST, this.boundHandleDeleteRequest);
        document.removeEventListener(EVEN_LISTE_ELEMENT_DELETED, this.boundHandleDeleted);
        document.removeEventListener(EVEN_DATA_BASE_SELECTION_REQUEST, this.boundHandleDBRequest);
        document.removeEventListener(EVEN_DATA_BASE_SELECTION_EXECUTED, this.boundHandleDBResult);
        document.removeEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundHandleDonneesLoaded);
        document.removeEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);

        // --- AJOUT : Nettoyage de notre nouvel écouteur ---
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleGlobalSelectionUpdate);
    }

    /**
     * --- NOUVELLE MÉTHODE ---
     * C'est le "récepteur". Il écoute les événements de sélection globaux
     * et met à jour l'état visuel de cette liste si elle est concernée.
     */
    handleGlobalSelectionUpdate(event) {
        // Garde-fou 1 : On ne réagit que si la liste est visible à l'écran.
        // offsetParent est null si l'élément ou un de ses parents a 'display: none'.
        if (this.element.offsetParent === null) {
            return; // On ne fait rien si on est une liste cachée.
        }

        // --- CORRECTION : Simplification du garde-fou ---
        // On ne doit pas traiter l'événement si c'est cette instance même qui l'a émis.
        // On vérifie la présence de `canvas` qui n'est présent que dans les événements émis par ce contrôleur.
        if (event.detail.canvas) {
            return; // C'est notre propre événement, on l'ignore.
        }

        const restoredSelectionIds = new Set((event.detail.selection || []).map(id => String(id)));

        // On met à jour la sélection interne du contrôleur.
        this.tabSelectedCheckBoxs = Array.from(restoredSelectionIds);

        // On parcourt toutes les cases à cocher de CETTE liste et on met à jour leur état visuel.
        this.rowCheckboxTargets.forEach(checkbox => {
            const checkboxId = checkbox.dataset.idobjetValue;
            const shouldBeChecked = restoredSelectionIds.has(checkboxId);
            checkbox.checked = shouldBeChecked;

            const row = checkbox.closest('tr');
            if (row) {
                row.classList.toggle('row-selected', shouldBeChecked);
            }
        });

        // On met à jour l'état de la case "Tout cocher" pour refléter le nouvel état.
        this.updateSelectAllCheckboxState();

        // --- CORRECTION : Ne pas republier une sélection lors d'une restauration ---.
        // L'événement original a déjà été publié par list-tabs-controller. Le republier ici
        // avec un état interne pas encore complètement restauré cause la désynchronisation de la barre d'outils.
        // La ligne `this.publierSelection()` a été commentée/supprimée.
    }
    


    handleOpenRequest(event) {
        // const { selection } = event.detail;
        event.preventDefault();
        event.stopPropagation();

        console.log(this.nomControleur + " - Demande d'ouverture pour les éléments séléctionnés", this.tabSelectedEntities, this.selectedEntitiesType, this.selectedEntitiesCanvas);

        this.tabSelectedEntities.forEach(selectedEntity => {
            buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_OPENNED, true, true, {
                entity: selectedEntity,
                entityType: this.selectedEntitiesType,
                entityCanvas: this.selectedEntitiesCanvas
            });
        });
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

        //Utiles pour le panneau à onglets
        const entityType = checkbox.dataset.entityType;
        const entity = checkbox.dataset.entity;
        const canvas = checkbox.dataset.canvas;

        var entityJSON = null;
        var canvasJSON = null;

        if (!entity || !canvas) {
            console.error("Attributs data-entity ou data-entity-canvas manquants sur l'élément cliqué.", element);
            return;
        }

        try {
            // 1. On parse les données JSON
            entityJSON = JSON.parse(entity);
            canvasJSON = JSON.parse(canvas);

            // 2. (Optionnel mais recommandé) On vérifie que l'ID existe avant d'envoyer
            if (typeof entityJSON.id === 'undefined' || entityJSON.id === null) {
                console.error("L'entité parsée n'a pas d'ID valide.", entityJSON);
                return;
            }
            // console.log(this.nomControleur + " - Objet:", idObjet, canvasJSON, entityJSON, entityType);
        } catch (e) {
            console.error("Erreur de parsing JSON dans 'liste-principale_controller'. Vérifiez les données dans le template Twig.", { error: e, entityData: entity });
            return;
        }


        if (isChecked) {
            if (!this.tabSelectedCheckBoxs.includes(idObjet)) {
                this.tabSelectedCheckBoxs.push(idObjet);
                //Utiles pour le panneau à onglets
                this.tabSelectedEntities.push(entityJSON);
                this.selectedEntitiesType = entityType;
                this.selectedEntitiesCanvas = canvasJSON;
            }
        } else {
            const index = this.tabSelectedCheckBoxs.indexOf(idObjet);
            if (index > -1) {
                this.tabSelectedCheckBoxs.splice(index, 1);
            }
            // console.log(this.nomControleur + " -- ICI", index, idObjet);

            if (entityJSON && canvasJSON) {
                //Utile pour le panneau à onglets
                // const indexEntity = this.tabSelectedEntities.indexOf(entityJSON);
                // On cherche l'index de l'entité dont l'ID correspond à 'idObjet'.
                const indexEntity = this.tabSelectedEntities.findIndex(e => e.id == idObjet);
                if (indexEntity > -1) {
                    this.tabSelectedEntities.splice(indexEntity, 1);

                    // Ajouté : Si la liste des entités sélectionnées est maintenant vide...
                    if (this.tabSelectedEntities.length === 0) {
                        // ...on réinitialise le type et le canvas.
                        this.selectedEntitiesType = null;
                        this.selectedEntitiesCanvas = null;
                    }
                }
                // console.log(this.nomControleur + " -- ICI", indexEntity, entityJSON);
            }
        }
        // console.log(this.nomControleur + " -- ICI", this.tabSelectedEntities, this.tabSelectedCheckBoxs);
        
        // --- CORRECTION : Mettre à jour la case "Tout cocher" AVANT de publier ---
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
        // buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
        //     titre: titre,
        //     message: question,
        //     action: action,
        //     idObjet: -1,
        //     selection: selection,
        //     controleurPhp: this.controleurphpValue,
        //     controleurSitimulus: this.controleurphpValue,
        //     idEntreprise: this.identrepriseValue,
        //     rubrique: this.rubriqueValue,
        // });
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
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            entity: this.tabSelectedEntities[0],
            entityFormCanvas: this.entityFormCanvasValue
        });
    }

    handleAddRequest(event) {
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            entity: {},
            entityFormCanvas: this.entityFormCanvasValue
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

        if (this.isChecked == true) {
            this.tabSelectedEntities = this.getAllEntities();
            this.selectedEntitiesType = this.rowCheckboxTargets[0].dataset.entityType;
            this.selectedEntitiesCanvas = JSON.parse(this.rowCheckboxTargets[0].dataset.canvas);
            console.log(this.nomControleur + " - ICI Chargement des objets réussi:", this.tabSelectedEntities);
        } else {
            this.tabSelectedEntities = [];
            this.selectedEntitiesType = null;
            this.selectedEntitiesCanvas = null;
            console.log(this.nomControleur + " - ICI Suppression des objets réussi:", this.tabSelectedEntities);
        }

        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, true, true, {
            selection: this.tabSelectedCheckBoxs,
        });
    }

    /**
     * Récupère toutes les entités de la liste actuellement affichée à l'écran.
     * @returns {Array<Object>} Un tableau contenant tous les objets entité.
     */
    getAllEntities() {
        // 'this.rowCheckboxTargets' est un tableau fourni par Stimulus
        // contenant tous les éléments <input> ayant le target "rowCheckbox".
        return this.rowCheckboxTargets.map(checkbox => {
            // Pour chaque case à cocher, on lit son attribut data-entity.
            const entityData = checkbox.dataset.entity;
            try {
                // On parse la chaîne JSON pour la transformer en véritable objet JavaScript.
                return JSON.parse(entityData);
            } catch (e) {
                console.error("Erreur de parsing JSON sur une ligne :", entityData, e);
                return null; // Retourne null si une donnée est corrompue
            }
        }).filter(entity => entity !== null); // On retire les éventuelles erreurs de parsing
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

    // handleRefreshRequest(event) {
    //     console.log(this.nomControleur + " - HandleRefreshRequest", event.detail);
    //     buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESHED, true, true, event.detail);
    // }

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
            entities: this.tabSelectedEntities,
            canvas: this.selectedEntitiesCanvas,
            entityType: this.selectedEntitiesType,
            isRestored: false // Marqueur pour indiquer que ce n'est pas un événement de restauration initial
        });
    }


    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    
    /**
     * NOUVELLE VERSION : Notifie le contrôleur parent pour afficher un message.
     */
    updateMessage(titre, message) {
        const fullMessage = `<strong>[${titre}]</strong> ${message}`;
        // On envoie un événement que le `list-tabs-controller` va intercepter.
        document.dispatchEvent(new CustomEvent('list-status:notify', {
            bubbles: true,
            detail: { message: fullMessage }
        }));
    }


    handleDonneesLoaded(event) {
        const { status, page, limit, totalitems } = event.detail;

        this.nbelementsValue = totalitems;
        this.tabSelectedCheckBoxs = [];
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();

        console.log(this.nomControleur + " - handleDonneesLoaded" + event.datil);
        this.updateMessage(status.message);
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
        const page = 1;
        const limit = 100;

        // console.log(this.nomControleur + " - ICI: ", entityName, criteria);

        if (!entityName || !criteria) {
            console.error('Event "app:base-données:sélection-request" is missing "entityName" or "criteria" in detail.', event.detail);
            this.dispatchResponse(null, 'Missing parameters in event detail.');
            return;
        }

        // --- MODIFICATION : Afficher le spinner avant la requête ---
        this.donneesTarget.innerHTML = `<div class="spinner-container"><div class="custom-spinner"></div></div>`;
        // ---------------------------------------------------------

        try {
            buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, {
                text: 'Chargement, veuillez patiener...', type: 'info'
            });

            const response = await fetch(this.urlAPIDynamicQuery, { // La requête est déjà attendue, c'est bien.
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ entityName, criteria, page, limit }),
            });

            const responseData = await response.text(); // On attend la réponse texte.
            
            // MODIFICATION CLÉ : On propage le résultat APRÈS avoir reçu la réponse.
            this.dispatchResponse(responseData, null); // Succès : propage les résultats
        } catch (error) {
            // Gère les erreurs réseau ou de parsing JSON
            console.error(this.nomControleur + ' - Fetch error:', error);
            this.dispatchResponse(null, error.message);
            // --- MODIFICATION : Afficher une erreur à la place du spinner ---
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
            // ---------------------------------------------------------------

            buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, {
                text: error.message, type: 'error'
            });
        }
    }


    handleDBResult(event) {
        const { results, error, isSuccess } = event.detail;
        console.log(this.nomControleur + " - handleDBResult", event.detail);
        //Ici on redessine la liste des données
        this.donneesTarget.innerHTML = results;
    }


    toggleRowSelection(event) {
        // Trouve la checkbox à l'intérieur de la ligne cliquée (tr)
        const row = event.currentTarget;
        const checkbox = row.querySelector('input[type="checkbox"]');

        if (!checkbox) return;

        // Ne pas interférer si le clic était directement sur un lien, un bouton, ou la checkbox elle-même
        const isInteractiveElement = event.target.closest('a, button, input, label');
        if (isInteractiveElement && isInteractiveElement !== row) {
            // Mettre à jour l'état visuel même si on clique sur la checkbox
            if (event.target.type === 'checkbox') {
                row.classList.toggle('row-selected', event.target.checked);
            }
            return;
        }

        // Inverser l'état de la checkbox et de la classe
        checkbox.checked = !checkbox.checked;
        row.classList.toggle('row-selected', checkbox.checked);

        // Déclencher manuellement un événement "change" pour que vos autres logiques (ex: tout cocher) fonctionnent
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
    }
}