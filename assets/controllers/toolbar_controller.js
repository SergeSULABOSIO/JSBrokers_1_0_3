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
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire lors de la déconnexion.
     */
    disconnect() {
        console.log(`${this.nomControleur} - Déconnecté - Suppression d'écouteurs.`);
    }

    /**
     * Affiche ou masque les boutons contextuels en fonction de la sélection actuelle.
     * La logique est centralisée ici et se base sur le nombre d'éléments dans `this.selectos`.
     * @private
     */
    organizeButtons() {
        const selectionCount = this.selectos.length;
        const hasSelection = selectionCount > 0;
        const isSingleSelection = selectionCount === 1;

        // Règle : "Modifier" est visible uniquement pour une sélection unique.
        this.toggleButton(this.btmodifierTarget, isSingleSelection);

        // Règle : "Ouvrir" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btouvrirTarget, hasSelection);

        // Règle : "Supprimer" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btsupprimerTarget, hasSelection);
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
        if (this.hasBtquitterTarget) this.btquitterTarget.style.display = "block";
        if (this.hasBtparametresTarget) this.btparametresTarget.style.display = "block";
        if (this.hasBtajouterTarget) this.btajouterTarget.style.display = "block";
        if (this.hasBtrechargerTarget) this.btrechargerTarget.style.display = "block";
        if (this.hasBttoutcocherTarget) this.bttoutcocherTarget.style.display = "block";

        // Les boutons contextuels sont cachés par défaut.
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

        let payload = {};
        // Enrichit le payload en fonction de l'action demandée
        if (eventName === 'app:delete-request') { // Renommé
            payload = {
                selection: this.selectos.map(s => s.id),
                actionConfig: {
                    // On récupère l'URL de base pour la suppression depuis le canvas du formulaire actif.
                    url: this.activeFormCanvas.parametres.endpoint_delete_url,
                    originatorId: null, // Indique que la requête vient de la barre d'outils principale, pour un rafraîchissement global.
                }
            };
            console.log(this.nomControleur + " - Code: 1986 - Suppression", payload);
        } else if (eventName === 'ui:toolbar.add-request') {
            payload = {
                entity: {},
                entityFormCanvas: this.activeFormCanvas,
                isCreationMode: true,
            };
        } else if (eventName === 'ui:toolbar.edit-request') {
            payload = {
                // On envoie l'entité elle-même, pas l'objet selecto complet
                entity: this.selectos[0].entity, 
                entityFormCanvas: this.activeFormCanvas,
                isCreationMode: false,
            };
        } else if (eventName === 'ui:toolbar.open-request') {
            console.log(this.nomControleur + " - Ouverture", this.selectos);
            payload = {
                entities: this.selectos
            };
        }
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
            detail: {
                type: type,
                source: this.nomControleur,
                payload: payload,
                timestamp: Date.now()
            }
        });
        this.element.dispatchEvent(event);
    }
}