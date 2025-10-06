import { Controller } from '@hotwired/stimulus';

/**
 * @class ContextMenuController
 * @extends Controller
 * @description Gère l'affichage, le positionnement et les actions d'un menu contextuel.
 * Le menu s'adapte en fonction de la sélection actuelle dans la liste.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} menuTargets - L'élément conteneur du menu.
     * @property {HTMLElement[]} btAjouterTargets - Le bouton "Ajouter".
     * @property {HTMLElement[]} btModifierTargets - Le bouton "Modifier".
     * @property {HTMLElement[]} btOuvrirTargets - Le bouton "Ouvrir".
     * @property {HTMLElement[]} btToutCocherTargets - Le bouton "Tout cocher".
     * @property {HTMLElement[]} btActualiserTargets - Le bouton "Actualiser".
     * @property {HTMLElement[]} btSupprimerTargets - Le bouton "Supprimer".
     * @property {HTMLElement[]} btParametrerTargets - Le bouton "Paramétrer".
     * @property {HTMLElement[]} btQuitterTargets - Le bouton "Quitter".
     */
    static targets = [
        'menu', 'btAjouter', 'btModifier', 'btOuvrir', 'btToutCocher',
        'btActualiser', 'btSupprimer', 'btParametrer', 'btQuitter',
    ];

    /**
     * Méthode du cycle de vie de Stimulus.
     * Initialise l'état et met en place les écouteurs.
     */
    connect() {
        this.nomControleur = "CONTEXT-MENU";
        console.log(`${this.nomControleur} - Connecté.`);

        // --- CORRECTION : Stockage de l'état de la sélection ---
        this.selection = [];
        this.entities = [];
        this.entityFormCanvas = null;

        // --- CORRECTION : Lier les méthodes une seule fois pour un nettoyage correct ---
        this.boundHandleContextMenuRequest = this.handleContextMenuRequest.bind(this);
        this.boundHandleSelectionUpdate = this.handleSelectionUpdate.bind(this);
        this.boundHideContextMenu = this.hideContextMenu.bind(this);

        this.menuTarget.style.display = 'none';
        document.addEventListener('click', this.boundHideContextMenu, false);
        document.addEventListener('app:context-menu.show', this.boundHandleContextMenuRequest);
        document.addEventListener('ui:selection.changed', this.boundHandleSelectionUpdate);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('click', this.boundHideContextMenu, false);
        document.removeEventListener('app:context-menu.show', this.boundHandleContextMenuRequest);
        document.removeEventListener('ui:selection.changed', this.boundHandleSelectionUpdate);
    }

    /**
     * Gère la demande d'ouverture du menu contextuel.
     * Positionne et affiche le menu.
     * @param {CustomEvent} event - L'événement `ui:context-menu.request`.
     */
    handleContextMenuRequest(event) {
        event.preventDefault();
        const { menuX, menuY } = event.detail;

        // Positionne le menu
        const menuWidth = this.menuTarget.offsetWidth;
        const menuHeight = this.menuTarget.offsetHeight;
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;

        let left = (menuX + menuWidth > windowWidth) ? windowWidth - menuWidth - 5 : menuX;
        let top = (menuY + menuHeight > windowHeight) ? windowHeight - menuHeight - 5 : menuY;

        this.menuTarget.style.left = `${left}px`;
        this.menuTarget.style.top = `${top}px`;

        // Met à jour et affiche le menu
        this.organizeButtons(this.selection);
        this.menuTarget.style.display = 'block';
    }

    /**
     * Met à jour l'état interne du contrôleur avec la sélection actuelle de l'application.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleSelectionUpdate(event) {
        // Le payload (event.detail) est maintenant directement le tableau des "selectos".
        const selectos = event.detail || [];
        this.selection = selectos.map(s => s.id);
        this.entities = selectos;
        this.entityFormCanvas = selectos.length > 0 ? selectos[0].entityFormCanvas : null;
        // Met à jour les boutons si le menu est déjà visible
        if (this.menuTarget.style.display === 'block') {
            this.organizeButtons(this.selection);
        }
    }

    /**
     * Affiche ou masque les boutons du menu en fonction de la sélection.
     * @param {Array<string>} selection - Le tableau des IDs sélectionnés.
     * @private
     */
    organizeButtons(selection) {
        const hasSelection = selection.length > 0;
        const isSingleSelection = selection.length === 1;

        if (this.hasBtModifierTarget) this.btModifierTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtouvrirTarget) this.btOuvrirTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtsupprimerTarget) this.btSupprimerTarget.style.display = hasSelection ? "block" : "none";
    }

    /**
     * Masque le menu contextuel.
     */
    hideContextMenu() {
        if (this.hasMenuTarget) {
            this.menuTarget.style.display = 'none';
        }
    }

    /**
     * Gère l'action spécifique "Ouvrir" du menu contextuel pour corriger l'erreur.
     * @param {MouseEvent} event - L'événement de clic.
     */
    context_action_ouvrir(event) {
        // CORRECTION : Notifie directement le Cerveau avec le bon événement,
        // car l'attribut data-context-menu-event-name-param est manquant sur le bouton.
        event.stopPropagation();
        this.hideContextMenu();

        const payload = { entities: this.entities };
        this.notifyCerveau('ui:toolbar.open-request', payload);
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action.
     * @param {MouseEvent} event - L'événement de clic du bouton.
     */
    notify(event) {
        event.stopPropagation();
        this.hideContextMenu();

        const button = event.currentTarget;
        const eventName = button.dataset.contextMenuEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-context-menu-event-name-param' défini.", button);
            return;
        }

        let payload = {};
        // --- CORRECTION : Enrichit le payload avec les données de sélection ---
        if (['ui:toolbar.edit-request', 'ui:toolbar.open-request'].includes(eventName)) {
            payload = { entities: this.entities };
        } else if (eventName === 'ui:toolbar.delete-request') {
            payload = { selection: this.selection };
        } else if (eventName === 'ui:toolbar.add-request') {
            payload = { entityFormCanvas: this.entityFormCanvas };
        }

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type d'événement (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Les données à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du Cerveau: ${type}`, payload);
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}