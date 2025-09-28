import { Controller } from '@hotwired/stimulus';

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
        // --- CORRECTION : Cacher le menu au démarrage et écouter les clics pour le fermer ---
        this.hideContextMenu();
        document.addEventListener('click', this.boundHideContextMenu, false);
        this.setEcouteurs();
    }

    setEcouteurs() {
        this.boundHandleContextMenuInitRequest = this.handleContextMenuInitRequest.bind(this);
        this.boundHandlePublisheSelection = this.handlePublisheSelection.bind(this);
        this.boundHideContextMenu = this.hideContextMenu.bind(this);

        // --- CORRECTION : Activation des écouteurs ---
        document.addEventListener('ui:context-menu.request', this.boundHandleContextMenuInitRequest);
        document.addEventListener('ui:selection.changed', this.boundHandlePublisheSelection);
    }

    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }

    disconnect() {
        // --- CORRECTION : Nettoyage des écouteurs ---
        document.removeEventListener('click', this.boundHideContextMenu, false);
        document.removeEventListener('ui:context-menu.request', this.boundHandleContextMenuInitRequest);
        document.removeEventListener('ui:selection.changed', this.boundHandlePublisheSelection);
    }

    hideContextMenu() {
        this.menu.style.display = 'none';
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
        this.showContextMenu(event);
    }


    organizeButtons(event) {
        // --- CORRECTION : La méthode doit accepter un simple tableau de sélection ---
        const selection = event.detail.selection || [];

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


    /**
     * @description Gère l'événement d'ajout.
     * @param {CustomEvent} event L'événement personnalisé déclenché.
     */
    handlePublisheSelection(event) {
        if (!event.detail.entities || event.detail.entities.length === 0 && event.detail.selection.length > 0) {
            return;
        }

        const { selection, entities, canvas, entityType } = event.detail; // Récupère les données de l'événement
        this.tabSelectedCheckBoxs = selection;
        this.tabSelectedEntities = entities;
        this.selectedEntitiesType = entityType;
        this.selectedEntitiesCanvas = canvas;

        this.organizeButtons({ detail: { selection: selection } });
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
    showContextMenu(event) {
        this.menu.style.display = 'block'; // Affiche le menu
    }

    /**
     * Méthode centralisée pour envoyer un événement au cerveau.
     * @param {string} type Le type d'événement pour le cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} payload Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du cerveau: ${type}`, payload);
        this.dispatch('cerveau:event', {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }

    // --- Méthodes spécifiques aux actions du menu ---

    context_action_ajouter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.add-request', {});
    }

    context_action_modifier(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.edit-request', {});
    }

    getUnifiedElementSelectionnes() {
        let data = [];
        if (this.tabSelectedCheckBoxs.length != 0) {
            data = this.tabSelectedCheckBoxs;
        }
        return data;
    }

    context_action_ouvrir(event) {
        event.preventDefault();
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.open-request', {});
    }

    context_action_tout_cocher(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.check-all-request', {});
    }

    context_action_actualiser(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.refresh-request', {});
    }

    context_action_supprimer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.delete-request', { selection: this.getUnifiedElementSelectionnes() });
    }

    context_action_parametrer(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        this.notifyCerveau('ui:toolbar.settings-request', {});
    }

    context_action_quitter(event) {
        event.stopPropagation(); // Empêche le clic de masquer immédiatement le menu
        this.hideContextMenu();
        this.notifyCerveau('ui:toolbar.close-request', {});
    }
}