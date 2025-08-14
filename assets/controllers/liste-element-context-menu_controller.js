import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_LISTE_ELEMENT_OPEN_REQUEST, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_NOTIFY, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, EVEN_MENU_CONTEXTUEL_INITIALIZED, EVEN_MENU_CONTEXTUEL_SHOW } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'menu',
        'btAjouter',
        'btModifier',
        'btOuvrir',
        'btToutCocher',
        'btActualiser',
        'btSupprimer',
        'btParametrer',
        'btQuitter',
    ];


    connect() {
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedEntities = [];
        this.selectedEntitiesType = null;
        this.selectedEntitiesCanvas = null;
        this.nomControleur = "LISTE-ELEMENT-CONTEXT-MENU";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.menu = this.menuTarget; // Le menu HTML
        this.setEcouteurs();
    }

    setEcouteurs() {
        this.boundHandleContextMenuInitRequest = this.handleContextMenuInitRequest.bind(this);
        this.boundHandleContextMenuInitialized = this.handleContextMenuInitialized.bind(this);
        this.boundHandleContextMenuShow = this.handleContextMenuShow.bind(this);
        this.boundHandleContextMenuHide = this.handleContextMenuHide.bind(this);
        this.boundHandlePublisheSelection = this.handlePublisheSelection.bind(this);
        this.boundHideContextMenu = this.hideContextMenu.bind(this);

        document.addEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.boundHandleContextMenuInitRequest);
        document.addEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.boundHandleContextMenuInitialized);
        document.addEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.boundHandleContextMenuShow);
        document.addEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.boundHandleContextMenuHide);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandlePublisheSelection);
        //Pour le menu contextuel
        document.addEventListener("click", this.boundHideContextMenu);
        document.addEventListener("scroll", this.boundHideContextMenu); // Cacher si on scroll
        window.addEventListener("resize", this.boundHideContextMenu); // Cacher si la fenêtre est redimensionnée
        this.menu.addEventListener("click", (e) => e.stopPropagation());
    }

    disconnect() {
        // console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.boundHandleContextMenuInitRequest);
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.boundHandleContextMenuInitialized);
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.boundHandleContextMenuShow);
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.boundHandleContextMenuHide);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandlePublisheSelection);
        //Pour le menu contextuel
        document.removeEventListener("click", this.boundHideContextMenu);
        document.removeEventListener("scroll", this.boundHideContextMenu); // Cacher si on scroll
        window.removeEventListener("resize", this.boundHideContextMenu); // Cacher si la fenêtre est redimensionnée
        this.menu.removeEventListener("click", (e) => e.stopPropagation());
    }

    hideContextMenu() {
        this.menu.style.display = 'none';
        // console.log(this.nomControleur + " - CACHER MENU CONTEXTUEL");
    }

    handleContextMenuInitRequest(event) {
        let { idObjet, menuX, menuY } = event.detail;
        // this.idObjet = idObjet;
        console.log(this.nomControleur + " - HandleContextMenuInitRequest", event.detail, this.tabSelectedCheckBoxs);
        event.preventDefault(); // Empêche le menu contextuel natif du navigateur d'apparaître
        // Positionne le menu près du curseur de la souris, en s'assurant qu'il reste dans la fenêtre
        const menuWidth = this.menu.offsetWidth;
        const menuHeight = this.menu.offsetHeight;
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;
        if (menuX + menuWidth > windowWidth) {
            menuX = windowWidth - menuWidth - 5; // Décale à gauche si trop à droite
        }
        if (menuY + menuHeight > windowHeight) {
            menuY = windowHeight - menuHeight - 5; // Décale vers le haut si trop en bas
        }
        this.menu.style.left = `${menuX}px`;
        this.menu.style.top = `${menuY}px`;

        event.detail['selection'] = this.tabSelectedCheckBoxs;

        this.organizeButtons(event);

        //On affiche le menu contextuel
        this.boundShowContextMenu(event);
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_INITIALIZED, true, true, {});
    }


    organizeButtons(event) {
        let { idObjet, menuX, menuY, selection } = event.detail;
        
        this.btOuvrirTarget.style.display = "none";
        this.btModifierTarget.style.display = "none";
        this.btSupprimerTarget.style.display = "none";
        
        console.log(this.nomControleur + " - Organisation des boutons - selection:", selection);
        
        if (selection.length != 0) {
            if (selection.length == 1) {
                this.btModifierTarget.style.display = "block";
            }
            this.btOuvrirTarget.style.display = "block";
            this.btSupprimerTarget.style.display = "block";

        }
    }

    handleContextMenuInitialized(event) {
        console.log(this.nomControleur + " - HandleContextMenuInitialized");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {
            titre: "Prêt",
            message: "Le ménu contextuel est initialisé. Position: " + this.idObjet,
        });
    }

    handleContextMenuShow(event) {
        console.log(this.nomControleur + " - HandleContextMenuShow");
        this.boundShowContextMenu(event);
    }

    handleContextMenuHide(event) {
        console.log(this.nomControleur + " - HandleContextMenuHide");
        this.hideContextMenu();
    }


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handlePublisheSelection(event) {
        const { selection, entities, canvas, entityType } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxs = selection;
        this.tabSelectedEntities = entities;
        this.selectedEntitiesType = entityType;
        this.selectedEntitiesCanvas = canvas;
        event.stopPropagation();
    }


    /**
     * @description Gère l'événement de séléction.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handleItemCoche(event) {
        const { idCheckBox } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedCheckBoxs.push(idCheckBox);
        event.stopPropagation();
    }

    /**
     * Cache le menu contextuel.
     */
    boundShowContextMenu(event) {
        this.menu.style.display = 'block'; // Affiche le menu
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_INITIALIZED, true, true, event);
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, true, true, {
            titre: "Ajout",
            action: EVEN_CODE_ACTION_AJOUT,
        });
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, true, true, {
            titre: "Modification",
            action: EVEN_CODE_ACTION_MODIFICATION,
        });
    }

    getUnifiedElementSelectionnes() {
        let data = [];
        if (this.tabSelectedCheckBoxs.length != 0) {
            data = this.tabSelectedCheckBoxs;
        } else {
            data.push(this.idObjet);
        }
        return data;
    }

    context_action_ouvrir(event) {
        event.preventDefault();
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_OPEN_REQUEST, true, true, event);
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, true, true, event);
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, true, true, event.detail);
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETE_REQUEST, true, true, {
            titre: "Suppression",
            action: EVEN_CODE_ACTION_SUPPRESSION,
            selection: this.getUnifiedElementSelectionnes(),
        });
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, true, true, event.detail);
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, true, true, event.detail);
    }
}