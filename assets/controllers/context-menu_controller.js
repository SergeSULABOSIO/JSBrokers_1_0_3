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

        // NOUVEAU : Mémorise la position demandée pour le menu.
        this.pendingPosition = null;

        this.boundPrepareContextMenu = this.prepareContextMenu.bind(this);
        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);
        this.boundHideContextMenu = this.hideContextMenu.bind(this);
        this.boundHandleKeyboardShortcuts = this.handleKeyboardShortcuts.bind(this);

        this.menuTarget.style.display = 'none';
        document.addEventListener('click', this.boundHideContextMenu, false);
        document.addEventListener('app:context-menu.prepare', this.boundPrepareContextMenu);
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('click', this.boundHideContextMenu, false);
        document.removeEventListener('app:context-menu.prepare', this.boundPrepareContextMenu);
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);
        document.removeEventListener('keydown', this.boundHandleKeyboardShortcuts);
    }

    /**
     * NOUVEAU : Mémorise la position où le menu doit s'afficher.
     * @param {CustomEvent} event 
     */
    prepareContextMenu(event) {
        event.preventDefault();
        this.pendingPosition = { x: event.detail.menuX, y: event.detail.menuY };
    }

    _displayMenu() {
        // Positionne le menu
        const menuWidth = this.menuTarget.offsetWidth;
        const menuHeight = this.menuTarget.offsetHeight;
        const windowWidth = window.innerWidth;
        const windowHeight = window.innerHeight;

        let left = (this.pendingPosition.x + menuWidth > windowWidth) ? windowWidth - menuWidth - 5 : this.pendingPosition.x;
        let top = (this.pendingPosition.y + menuHeight > windowHeight) ? windowHeight - menuHeight - 5 : this.pendingPosition.y;

        this.menuTarget.style.left = `${left}px`;
        this.menuTarget.style.top = `${top}px`;

        // Met à jour et affiche le menu
        this.organizeButtons(this.entities);
        this.menuTarget.style.display = 'block';
        // Active l'écoute des raccourcis clavier uniquement quand le menu est visible
        document.addEventListener('keydown', this.boundHandleKeyboardShortcuts);
    }

    /**
     * Met à jour l'état interne du contrôleur avec la sélection actuelle de l'application.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        const selectos = event.detail.selection || [];
        this.entities = selectos;
        this.entityFormCanvas = selectos.length > 0 ? selectos[0].entityFormCanvas : null;

        // CORRECTION : C'est ici qu'on déclenche l'affichage.
        // Si une position est en attente, on affiche le menu.
        if (this.pendingPosition) {
            this._displayMenu();
            this.pendingPosition = null; // On réinitialise pour le prochain clic.
        } else if (this.menuTarget.style.display === 'block') {
            // Si le menu est déjà visible (ex: sélection avec Ctrl+clic), on le réorganise.
            this.organizeButtons(this.entities);
        }
    }

    /**
     * Affiche ou masque les boutons du menu en fonction de la sélection.
     * @param {Array<string>} selection - Le tableau des IDs sélectionnés.
     * @private
     */
    organizeButtons(selectos) {
        const hasSelection = selectos.length > 0;
        const isSingleSelection = selectos.length === 1;

        console.log(this.nomControleur + " - organizeButtons - Code: 8888 - Selection:", selectos, "Length:", selectos.length, "Single:", isSingleSelection, "Has:", hasSelection);

        // --- CORRECTION : Logique exhaustive ---
        // 1. On affiche toujours les boutons non contextuels.
        if (this.hasBtAjouterTarget) this.btAjouterTarget.style.display = 'flex';
        if (this.hasBtToutCocherTarget) this.btToutCocherTarget.style.display = 'flex';
        if (this.hasBtActualiserTarget) this.btActualiserTarget.style.display = 'flex';
        if (this.hasBtParametrerTarget) this.btParametrerTarget.style.display = 'flex';
        if (this.hasBtQuitterTarget) this.btQuitterTarget.style.display = 'flex';

        // 2. On gère la visibilité des boutons contextuels en fonction de la sélection.
        if (this.hasBtModifierTarget) this.btModifierTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtouvrirTarget) this.btOuvrirTarget.style.display = hasSelection ? "block" : "none";
        if (this.hasBtsupprimerTarget) this.btSupprimerTarget.style.display = hasSelection ? "block" : "none";
    }

    /**
     * Masque le menu contextuel.
     */
    hideContextMenu() {
        if (this.hasMenuTarget) {
            this.menuTarget.style.display = 'none';
            // Désactive l'écoute des raccourcis clavier quand le menu est caché
            document.removeEventListener('keydown', this.boundHandleKeyboardShortcuts);
        }
    }

    /**
     * Gère l'action spécifique "Ouvrir" du menu contextuel pour corriger l'erreur.
     * @param {MouseEvent} event - L'événement de clic.
     */
    handleKeyboardShortcuts(event) {
        if (this.menuTarget.style.display !== 'block') return;

        const key = event.key.toUpperCase();
        let targetButton = null;

        switch (key) {
            case 'A':
                targetButton = this.btAjouterTarget;
                break;
            case 'M':
                targetButton = this.btModifierTarget;
                break;
            case 'O':
                targetButton = this.btOuvrirTarget;
                break;
            case 'S':
                targetButton = this.btSupprimerTarget;
                break;
            case 'R':
                targetButton = this.btActualiserTarget;
                break;
            case 'Q':
                targetButton = this.btQuitterTarget;
                break;
        }

        if (targetButton && targetButton.style.display !== 'none') {
            event.preventDefault();
            targetButton.click();
        }
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

        // Le payload est maintenant générique, comme pour la barre d'outils.
        // C'est au cerveau de l'interpréter.
        const payload = {
            selection: this.entities, // Envoie la sélection complète (objets selecto)
            formCanvas: this.entityFormCanvas, // Envoie le contexte du formulaire actif
        };

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