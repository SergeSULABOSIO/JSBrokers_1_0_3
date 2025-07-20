import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_ACTION_AJOUTER, EVEN_ACTION_CLICK, EVEN_ACTION_COCHER, EVEN_ACTION_COCHER_TOUT, EVEN_ACTION_DEVELOPPER, EVEN_ACTION_MENU_CONTEXTUEL, EVEN_ACTION_MODIFIER, EVEN_ACTION_PARAMETRER, EVEN_ACTION_QUITTER, EVEN_ACTION_RECHARGER, EVEN_ACTION_SELECTIONNER, EVEN_ACTION_SUPPRIMER, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_CODE_ACTION_AJOUT, EVEN_CODE_ACTION_MODIFICATION, EVEN_CODE_ACTION_SUPPRESSION, EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_MENU_CONTEXTUEL_HIDE, EVEN_MENU_CONTEXTUEL_INIT_REQUEST, EVEN_MENU_CONTEXTUEL_INITIALIZED, EVEN_MENU_CONTEXTUEL_SHOW } from './base_controller.js';

export default class extends Controller {
    static targets = [
        'menu',
        'btAjouter',
        'btModifier',
        'btDevelopper',
        'btToutCocher',
        'btActualiser',
        'btSupprimer',
        'btParametrer',
        'btQuitter',
    ];


    connect() {
        this.tabSelectedCheckBoxs = [];
        this.nomControleur = "LISTE-ELEMENT-CONTEXT-MENU";
        console.log(this.nomControleur + " - Connecté");
        this.init();
    }

    init() {
        this.menu = this.menuTarget; // Le menu HTML
        this.setEcouteurs();
    }

    setEcouteurs() {
        document.addEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.handleContextMenuInitRequest.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.handleContextMenuInitialized.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.handleContextMenuShow.bind(this));
        document.addEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.handleContextMenuHide.bind(this));
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.handlePublisheSelection.bind(this));
        //Pour le menu contextuel
        document.addEventListener("click", this.boundHideContextMenu.bind(this));
        document.addEventListener("scroll", this.boundHideContextMenu.bind(this)); // Cacher si on scroll
        window.addEventListener("resize", this.boundHideContextMenu.bind(this)); // Cacher si la fenêtre est redimensionnée
        this.menu.addEventListener("click", (e) => e.stopPropagation());
    }

    disconnect() {
        console.log(this.nomControleur + " - Déconnecté - Suppression d'écouteurs.");
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INIT_REQUEST, this.handleContextMenuInitRequest.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_INITIALIZED, this.handleContextMenuInitialized.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_SHOW, this.handleContextMenuShow.bind(this));
        document.removeEventListener(EVEN_MENU_CONTEXTUEL_HIDE, this.handleContextMenuHide.bind(this));
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.handlePublisheSelection.bind(this));
        //Pour le menu contextuel
        document.removeEventListener("click", this.boundHideContextMenu.bind(this));
        document.removeEventListener("scroll", this.boundHideContextMenu.bind(this)); // Cacher si on scroll
        window.removeEventListener("resize", this.boundHideContextMenu.bind(this)); // Cacher si la fenêtre est redimensionnée
        this.menu.removeEventListener("click", (e) => e.stopPropagation());
    }

    hideContextMenu() {
        this.menu.style.display = 'none';
        console.log(this.nomControleur + " - CHECHER MENU CONTEXTUEL");
    }

    handleContextMenuInitRequest(event) {
        let { idObjet, menuX, menuY } = event.detail;
        this.idObjet = idObjet;
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

        //On réorganise les boutons en fonction de la selection actuelle
        if (this.tabSelectedCheckBoxs.length != 0) {
            event.detail['selection'] = this.tabSelectedCheckBoxs;
        } else {
            let newTab = [];
            newTab.push(idObjet);
            event.detail['selection'] = newTab;
        }
        this.organizeButtons(event);

        //On affiche le menu contextuel
        this.boundShowContextMenu(event);
    }


    organizeButtons(event) {
        let { idObjet, menuX, menuY, selection } = event.detail;
        // console.log(this.nomControleur + " - Organisation des boutons", event.detail);
        if (selection.length >= 1) {
            if (selection.length == 1) {
                this.btModifierTarget.style.display = "block";
            } else {
                this.btModifierTarget.style.display = "none";
            }
            this.btDevelopperTarget.style.display = "block";
            this.btSupprimerTarget.style.display = "block";
        } else {
            this.btDevelopperTarget.style.display = "none";
            this.btModifierTarget.style.display = "none";
            this.btSupprimerTarget.style.display = "none";
        }
    }

    handleContextMenuInitialized(event) {
        console.log(this.nomControleur + " - HandleContextMenuInitialized");
    }

    handleContextMenuShow(event) {
        console.log(this.nomControleur + " - HandleContextMenuShow");
    }

    handleContextMenuHide(event) {
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
    boundHideContextMenu() {
        this.menu.style.display = 'none';
        console.log(this.nomControleur + " - FERMETURE DU MENU CONTEXTUEL.");
    }

    /**
     * Cache le menu contextuel.
     */
    boundShowContextMenu(event) {
        this.menu.style.display = 'block'; // Affiche le menu
        // console.log(this.nomControleur + " - OUVERTURE DU MENU CONTEXTUEL.");
        buildCustomEventForElement(document, EVEN_MENU_CONTEXTUEL_INITIALIZED, true, true, event);
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        console.log(this.nomControleur + " - CLIC SUR AJOUTER");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, true, true, {
            titre: "Ajout",
            action: EVEN_CODE_ACTION_AJOUT,
        });
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        console.log(this.nomControleur + " - CLIC SUR MODIFIER", this.idObjet, this.tabSelectedCheckBoxs);
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, true, true, {
            titre: "Modification",
            action: EVEN_CODE_ACTION_MODIFICATION,
            selectedId: this.idObjet,
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

    context_action_developper(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_EXPAND_REQUEST, true, true, {
            selection: this.getUnifiedElementSelectionnes(),
        });
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        console.log(this.nomControleur + " - CLIC SUR TOUT COCHER");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, true, true, event);
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        console.log(this.nomControleur + " - CLIC SUR ACTUALISER");
        buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, true, true, event.detail);
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        console.log(this.nomControleur + " - CLIC SUR SUPPRIMER");
        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_DELETE_REQUEST, true, true, {
            titre: "Suppression",
            action: EVEN_CODE_ACTION_SUPPRESSION,
            selection: this.getUnifiedElementSelectionnes(),
        });
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