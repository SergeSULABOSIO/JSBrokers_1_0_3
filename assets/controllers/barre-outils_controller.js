import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_BARRE_OUTILS_INIT_REQUEST, EVEN_BARRE_OUTILS_INITIALIZED, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_ELEMENT_OPEN_REQUEST, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'btquitter',
        'btparametres',
        'btrecharger',
        'btajouter',
        'btmodifier',
        'btsupprimer',
        'bttoutcocher',
        'btouvrir',
    ];

    connect() {
        this.nomControleur = "BARRE-OUTILS";
        this.tabSelectedEntities = [];
        this.selectedEntitiesType = null;
        this.selectedEntitiesCanvas = null;
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.tabSelectedCheckBoxs = [];
        this.listePrincipale = document.getElementById("liste");
        
        this.boundhandleInitRequest = this.handleInitRequest.bind(this);
        this.boundhandleInitialized = this.handleInitialized.bind(this);
        this.boundhandlePublisheSelection = this.handlePublisheSelection.bind(this);
        
        
        this.initialiserBarreDoutils();
        this.initToolTips();
        this.ecouteurs();
    }

    ecouteurs() {
        console.log(this.nomControleur + " - Activation des écouteurs d'évènements");
        document.addEventListener(EVEN_BARRE_OUTILS_INIT_REQUEST, this.boundhandleInitRequest);
        document.addEventListener(EVEN_BARRE_OUTILS_INITIALIZED, this.boundhandleInitialized);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundhandlePublisheSelection);
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_BARRE_OUTILS_INIT_REQUEST, this.boundhandleInitRequest);
        document.removeEventListener(EVEN_BARRE_OUTILS_INITIALIZED, this.boundhandleInitialized);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundhandlePublisheSelection);
    }

    handleInitRequest(event){
        console.log(this.nomControleur + " - HandleInitRequest");
    }

    handleInitialized(event){
        console.log(this.nomControleur + " - HandleInitialized");
    }


    /**
     * @description Gère l'événement de modification.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handlePublisheSelection(event) {
        // console.log(this.nomControleur + " - handlePublishSelection", event.detail);
        const { selection, entities, canvas, entityType } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxs = selection;
        this.tabSelectedEntities = entities;
        this.selectedEntitiesType = entityType;
        this.selectedEntitiesCanvas = canvas;
        event.stopPropagation();
        
        //On réorganise les boutons en fonction de la selection actuelle
        this.organizeButtons(selection);

        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre:"Etat",
            message: "{" + selection  + "}. Taille de la sélection: " + this.tabSelectedCheckBoxs.length + ".",
        });
    }

    organizeButtons(selection){
        if (selection.length >= 1) {
            if (selection.length == 1) {
                this.btmodifierTarget.style.display = "block";
            } else {
                this.btmodifierTarget.style.display = "none";
            }
            this.btouvrirTarget.style.display = "block";
            this.btsupprimerTarget.style.display = "block";
        } else {
            this.btouvrirTarget.style.display = "none";
            this.btmodifierTarget.style.display = "none";
            this.btsupprimerTarget.style.display = "none";
        }
    }

    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    initialiserBarreDoutils() {
        this.btquitterTarget.style.display = "block";
        this.btparametresTarget.style.display = "block";
        this.btajouterTarget.style.display = "block";
        this.btrechargerTarget.style.display = "block";
        this.bttoutcocherTarget.style.display = "block";
    }

    /**
     * LES ACTIONS
     */
    action_quitte(event) {
        // console.log(this.nomControleur + " - Action_Quitter");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, true, true, event);
    }

    action_parametrer(event) {
        // console.log(this.nomControleur + " - Action_Parametrer");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, true, true, event);
    }

    action_tout_cocher(event) {
        // console.log(this.nomControleur + " - Action_tout_cocher");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, true, true, event);
    }

    action_ouvrir(event) {
        // console.log(this.nomControleur + " - Action Ouvrir", event);
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_OPEN_REQUEST, true, true, event);
    }

    action_recharger(event) {
        // console.log(this.nomControleur + " - Action_Recharger");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, true, true, event.detail);
    }

    action_ajouter() {
        // console.log(this.nomControleur + " - Action_Ajouter");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, true, true, {
            titre: "Ajout",
            action: EVEN_CODE_ACTION_AJOUT,
        });
    }

    action_modifier() {
        console.log(this.nomControleur + " - Action_Modifier");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, true, true, {
            titre: "Modification",
            action: EVEN_CODE_ACTION_MODIFICATION,
        });
    }

    action_supprimer() {
        console.log(this.nomControleur + " - Action_Supprimer");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETE_REQUEST, true, true, {
            titre: "Suppression",
            action: EVEN_CODE_ACTION_SUPPRESSION,
            selection: this.tabSelectedCheckBoxs,
        });
    }
}