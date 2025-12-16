import { Controller } from '@hotwired/stimulus';

/**
 * @class ToolbarController - REFACTORED (V3)
 * @extends Controller
 * @description Gère la barre d'outils principale. Son rôle est de :
 * 1. Écouter `ui:selection.changed` (via le Cerveau) pour mettre à jour la liste des éléments sélectionnés (`selectos`).
 * 2. Écouter `ui:tab.context-changed` (via le Cerveau) pour mettre à jour le contexte de formulaire actif (`entityFormCanvas`).
 * 3. Ajuster la visibilité des boutons en fonction du nombre d'éléments sélectionnés.
 * 4. Notifier le Cerveau des actions initiées par l'utilisateur (Ajouter, Supprimer, etc.), en préfixant tous les événements par `ui:toolbar.`.
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
     * @property {ObjectValue} entityFormCanvasValue - La configuration (canvas) du formulaire d'édition/création
     * pour l'entité de la rubrique actuelle. Fourni par le serveur.
     */
    static values = {
        entityFormCanvas: Object,
    }

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
        // L'état du contrôleur est simple : il ne stocke que le tableau des "selectos" reçu du Cerveau.
        this.selectos = [];
        // Le canvas de formulaire est initialisé avec celui de la rubrique principale, puis mis à jour au changement d'onglet.
        this.activeFormCanvas = this.entityFormCanvasValue; // Initialisation avec la valeur du contexte principal.
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
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate); // NOUVEAU : Écoute le changement de contexte global
    }

    /**
     * Gère la mise à jour du contexte reçue du Cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        // NOUVEAU : Le payload de 'app:context.changed' contient la sélection et le formCanvas initial.
        const { selection, formCanvas } = event.detail;
        this.selectos = selection || [];
        if (formCanvas) { // Le formCanvas peut être null initialement pour les collections
            this.activeFormCanvas = formCanvas;
            this.entityFormCanvasValue = formCanvas;
        }
        this.organizeButtons();
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire lors de la déconnexion.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);
    }

    /**
     * Affiche ou masque les boutons contextuels en fonction de la sélection actuelle.
     * La logique est centralisée ici et se base sur le nombre d'éléments dans `this.selectos`.
     * @private
     */
    organizeButtons() {
        const selectionCount = this.selectos?.length || 0;
        const canvasParams = this.activeFormCanvas?.parametres || {};

        // Conditions de visibilité basées sur la sélection ET les permissions du canvas.
        const canAdd = !!canvasParams.endpoint_submit_url;
        const canEdit = selectionCount === 1 && !!canvasParams.endpoint_submit_url;
        const canDelete = selectionCount > 0 && !!canvasParams.endpoint_delete_url;
        const canOpen = selectionCount > 0; // L'ouverture est généralement toujours possible si sélection.

        // Règle : "Ajouter" est visible si le canvas le permet.
        this.toggleButton(this.btajouterTarget, canAdd);

        // Règle : "Modifier" est visible uniquement pour une sélection unique.
        this.toggleButton(this.btmodifierTarget, canEdit);

        // Règle : "Ouvrir" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btouvrirTarget, canOpen);

        // Règle : "Supprimer" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btsupprimerTarget, canDelete);
    }

    /**
     * Méthode utilitaire pour afficher/masquer un bouton cible.
     * @param {HTMLElement | undefined} target - Le bouton cible Stimulus (peut être optionnel).
     * @param {boolean} show - `true` pour afficher, `false` pour masquer.
     * @private
     */
    toggleButton(target, show) {
        if (!target) return;
        target.style.display = show ? 'block' : 'none';
    }

    /**
     * Initialise l'état des boutons. Certains sont toujours visibles,
     * d'autres sont cachés par défaut.
     * @private
     */
    initializeToolbarState() {
        // Ces boutons doivent toujours rester actifs et visibles.
        this.toggleButton(this.btquitterTarget, true);
        this.toggleButton(this.btparametresTarget, true);
        this.toggleButton(this.btrechargerTarget, true);
        this.toggleButton(this.bttoutcocherTarget, true);

        // Les boutons contextuels sont cachés par défaut.
        this.toggleButton(this.btajouterTarget, false); // Dépend maintenant du canvas
        this.toggleButton(this.btmodifierTarget, false);
        this.toggleButton(this.btouvrirTarget, false);
        this.toggleButton(this.btsupprimerTarget, false);
    }

    /**
     * Méthode générique pour notifier le Cerveau d'une action de la barre d'outils.
     * L'événement à envoyer est défini dans l'attribut `data-toolbar-event-name-param` du bouton.
     * @param {MouseEvent} event - L'événement de clic.
     * @fires CustomEvent#cerveau:event
     */
    notify(event) {
        const button = event.currentTarget;
        const eventName = button.dataset.toolbarEventNameParam;

        if (!eventName) {
            console.error("Le bouton n'a pas de 'data-toolbar-event-name-param' défini.", button);
            return;
        }

        // Le payload est maintenant générique. Il contient tout le contexte dont le cerveau pourrait avoir besoin.
        // C'est au cerveau de décider quelles informations utiliser.
        const payload = {
            selection: this.selectos, // Envoie la sélection complète (objets selecto)
            formCanvas: this.activeFormCanvas, // Envoie le contexte du formulaire actif
            // On pourrait ajouter ici d'autres éléments de contexte si nécessaire
        };

        this.notifyCerveau(eventName, payload);
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type Le type d'événement pour le Cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}