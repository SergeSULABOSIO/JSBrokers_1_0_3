import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_AJOUTER, EVEN_ACTION_CLICK, EVEN_ACTION_COCHER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_DEVELOPPER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SELECTIONNER, EVEN_ACTION_SUPPRIMER, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, EVEN_MENU_CONTEXTUEL_INITIALIZED, EVEN_MENU_CONTEXTUEL_SHOW } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'menu',
    ];


    connect() {
        this.tabSelectedCheckBoxs = [];
        this.listePrincipale = document.getElementById("liste");
        this.nomControleur = "LISTE-ELEMENT-CONTEXT-MENU";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.menu = this.menuTarget; // Le menu HTML
        this.listePrincipale = document.getElementById("liste");
        this.setEcouteurs();
    }

    setEcouteurs() {
        document.addEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.handleContextMenuInitRequest.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.handleContextMenuInitialized.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.handleContextMenuShow.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.handleContextMenuHide.bind(this));
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.handlePublisheSelection.bind(this));


        // Pour éviter que le clic sur une option du menu ne le cache immédiatement (stopPropagation)
        // this.menu.addEventListener(EVEN_ACTION_CLICK, (e) => e.stopPropagation());
        // this.listePrincipale.addEventListener(EVEN_ACTION_SELECTIONNER, this.handleItemSelection.bind(this));
        // this.listePrincipale.addEventListener(EVEN_ACTION_COCHER, this.handleItemCoche.bind(this));
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.handleContextMenuInitRequest.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.handleContextMenuInitialized.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.handleContextMenuShow.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.handleContextMenuHide.bind(this));
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.handlePublisheSelection.bind(this));

        
        
        // this.menu.removeEventListener(EVEN_ACTION_MENU_CONTEXTUEL, (e) => e.stopPropagation());
        // this.listePrincipale.removeEventListener(EVEN_ACTION_SELECTIONNER, this.handleItemSelection.bind(this));
        // this.listePrincipale.removeEventListener(EVEN_ACTION_COCHER, this.handleItemCoche.bind(this));
    }

    handleContextMenuInitRequest(event){
        console.log(this.nomControleur + " - HandleContextMenuInitRequest");
    }

    handleContextMenuInitialized(event){
        console.log(this.nomControleur + " - HandleContextMenuInitialized");
    }

    handleContextMenuShow(event){
        console.log(this.nomControleur + " - HandleContextMenuShow");
    }

    handleContextMenuHide(event){
        console.log(this.nomControleur + " - HandleContextMenuHide");
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handlePublisheSelection(event) {
        const { selection } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - handlePublishSelection", event.detail);
        this.tabSelectedCheckBoxs = selection;
        event.stopPropagation();
        //A partir d'ici il faut personnaliser les elements du menu contextuel avant qu'il ne puisse s'afficher.
    }


    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCoche(event) {
        const { idCheckBox } = event.detail; // Récupère les données de l'événement
        console.log(this.nomControleur + " - ELEMENT COCHE. [id.=" + idCheckBox.split("check_")[1] + "]", idCheckBox);
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedCheckBoxs.push(idCheckBox);
        event.stopPropagation();
    }

    /**
     * Cache le menu contextuel.
     */
    hideMenu() {
        this.menu.style.display = 'none';
        console.log(this.nomControleur + " - FERMETURE DU MENU CONTEXTUEL.");
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR AJOUTER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_AJOUTER, true, true, {titre: "Nouvelle notification"});
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR MODIFIER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_MODIFIER, true, true, {titre: "Modification de la notification"});
    }

    context_action_developper(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        let listElement = document.getElementById("liste_row_" + this.tabSelectedCheckBoxs[0].split("check_")[1]);
        buildCustomEventForElement(listElement, EVEN_ACTION_DEVELOPPER, true, true, {});
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR TOUT COCHER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_COCHER_TOUT, true, true, {});
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR ACTUALISER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_RECHARGER, true, true, {});
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR SUPPRIMER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_SUPPRIMER, true, true, {titre: "Suppression "});
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR PARAMETRER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_PARAMETRER, true, true, {});
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideMenu();
        console.log(this.nomControleur + " - CLIC SUR QUITTER");
        buildCustomEventForElement(this.listePrincipale, EVEN_ACTION_QUITTER, true, true, {});
    }
}