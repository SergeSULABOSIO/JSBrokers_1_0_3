import { Controller } from '@hotwired/stimulus';

/**
 * @class ToolbarController
 * @extends Controller
 * @description Gère la barre d'outils principale de l'application.
 * Ce contrôleur écoute les changements de contexte (sélection) et ajuste l'état de ses boutons.
 * Il notifie le Cerveau des actions initiées par l'utilisateur.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement} btquitterTarget - Le bouton pour quitter la rubrique.
     * @property {HTMLElement} btparametresTarget - Le bouton pour les paramètres.
     * @property {HTMLElement} btrechargerTarget - Le bouton pour recharger la liste.
     * @property {HTMLElement} btajouterTarget - Le bouton pour ajouter un élément.
     * @property {HTMLElement} btmodifierTarget - Le bouton pour modifier un élément.
     * @property {HTMLElement} btsupprimerTarget - Le bouton pour supprimer un ou plusieurs éléments.
     * @property {HTMLElement} bttoutcocherTarget - Le bouton pour tout cocher/décocher.
     * @property {HTMLElement} btouvrirTarget - Le bouton pour ouvrir un ou plusieurs éléments.
     */
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

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "Toolbar";
        console.log(`${this.nomControleur} - Connecté`);
        this.initialize();
    }

    /**
     * Initialise les propriétés et les écouteurs d'événements.
     * @private
     */
    initialize() {
        this.tabSelectedCheckBoxs = [];
        this.tabSelectedEntities = [];
        this.formCanvas = null;

        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);

        this.initializeToolbarState();
        this.setupEventListeners();
    }

    /**
     * Met en place les écouteurs d'événements globaux.
     * La barre d'outils écoute uniquement le Cerveau pour ajuster son état.
     * @private
     */
    setupEventListeners() {
        console.log(`${this.nomControleur} - Activation des écouteurs d'événements`);
        document.addEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire lors de la déconnexion.
     */
    disconnect() {
        console.log(`${this.nomControleur} - Déconnecté - Suppression d'écouteurs.`);
        document.removeEventListener('ui:selection.changed', this.boundHandleContextUpdate);
    }

    /**
     * Gère la mise à jour du contexte reçue du Cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        console.log(`${this.nomControleur} - Mise à jour du contexte reçue du Cerveau`, event.detail);

        const { selection, entities, entityFormCanvas } = event.detail;

        this.tabSelectedCheckBoxs = selection || [];
        this.tabSelectedEntities = entities || [];
        this.formCanvas = entityFormCanvas;

        this.organizeButtons(selection || []);
    }

    /**
     * Affiche ou masque les boutons en fonction de la sélection actuelle.
     * @param {Array<string>} selection - Le tableau des IDs sélectionnés.
     * @private
     */
    organizeButtons(selection) {
        const hasSelection = selection.length > 0;
        const isSingleSelection = selection.length === 1;

        if (this.hasBtmodifierTarget) this.btmodifierTarget.style.display = isSingleSelection ? "block" : "none";
        if (this.hasBtouvrirTarget) this.btouvrirTarget.style.display = hasSelection ? "block" : "none";
        if (this.hasBtsupprimerTarget) this.btsupprimerTarget.style.display = hasSelection ? "block" : "none";
    }

    /**
     * Initialise l'état visible des boutons de la barre d'outils.
     * @private
     */
    initializeToolbarState() {
        if (this.hasBtquitterTarget) this.btquitterTarget.style.display = "block";
        if (this.hasBtparametresTarget) this.btparametresTarget.style.display = "block";
        if (this.hasBtajouterTarget) this.btajouterTarget.style.display = "block";
        if (this.hasBtrechargerTarget) this.btrechargerTarget.style.display = "block";
        if (this.hasBttoutcocherTarget) this.bttoutcocherTarget.style.display = "block";
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action de la barre d'outils.
     * L'événement à envoyer est défini dans l'attribut `data-toolbar-event-name-param` du bouton.
     * @param {MouseEvent} event - L'événement de clic.
     * @fires cerveau:event
     */
    notify(event) {
        const button = event.currentTarget;
        const eventName = button.dataset.toolbarEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-toolbar-event-name-param' défini.", button);
            return;
        }

        let payload = {};
        // Enrichit le payload en fonction de l'action demandée
        if (eventName === 'ui:toolbar.delete-request') {
            payload = { selection: this.tabSelectedCheckBoxs };
        } else if (eventName === 'ui:toolbar.add-request') {
            payload = { entityFormCanvas: this.formCanvas };
        } else if (['ui:toolbar.edit-request', 'ui:toolbar.open-request'].includes(eventName)) {
            payload = { entities: this.tabSelectedEntities };
        }

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type Le type d'événement pour le Cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du Cerveau: ${type}`, payload);
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        }});
        this.element.dispatchEvent(event);
    }
}