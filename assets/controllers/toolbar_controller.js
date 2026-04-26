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
        'specificActionsSeparator', // NOUVEAU : Séparateur
        'specificActionsContainer'  // NOUVEAU : Conteneur pour les boutons
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
        this.selectos = [];
        this.activeFormCanvas = this.entityFormCanvasValue;
        
        // NOUVEAU : Initialisation du cache pour les icônes.
        this.iconCache = new Map();

        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);
        
        // NOUVEAU : Lier la méthode pour gérer la réception des icônes.
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);


        this.initializeToolbarState();
        this.setupEventListeners();

        // NOUVEAU : Pré-charger les icônes spécifiques dès le début.
        this._preloadSpecificActionIcons();
    }

    /**
     * Met en place les écouteurs d'événements globaux.
     * La barre d'outils écoute uniquement le Cerveau pour ajuster son état.
     * @private
     */
    setupEventListeners() {
        document.addEventListener('app:context.changed', this.boundHandleContextUpdate); // NOUVEAU : Écoute le changement de contexte global

        // NOUVEAU : Écouter la réponse du cerveau lorsque l'icône est prête.
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);
    }

    /**
     * Gère la mise à jour du contexte reçue du Cerveau (sélection, onglet actif, etc.).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleContextUpdate(event) {
        // CORRECTION FINALE : On se fie entièrement au contexte envoyé par le cerveau.
        // Le cerveau garantit maintenant que formCanvas est toujours présent.
        const { selection, formCanvas } = event.detail;
        this.selectos = selection || [];
        this.activeFormCanvas = formCanvas || {}; // On se protège avec un objet vide si jamais il est null.
        this.organizeButtons();
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire lors de la déconnexion.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextUpdate);

        // NOUVEAU : Nettoyer l'écouteur d'icône.
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
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

        // NOUVEAU : Gérer les actions spécifiques à l'entité
        const specificActions = canvasParams.attribute_actions || [];
        // Les actions spécifiques ne sont visibles que pour une sélection unique.
        const canShowSpecificActions = selectionCount === 1 && specificActions.length > 0;
        this.updateSpecificActionButtons(canShowSpecificActions ? specificActions : []);
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

        // Les actions spécifiques sont aussi cachées par défaut.
        this.toggleButton(this.specificActionsSeparatorTarget, false);
        this.specificActionsContainerTarget.innerHTML = '';
    }

    /**
     * NOUVEAU : Parcourt les actions spécifiques définies dans le canvas
     * et demande au cerveau de pré-charger les icônes manquantes.
     * @private
     */
    _preloadSpecificActionIcons() {
        const specificActions = this.activeFormCanvas?.parametres?.attribute_actions || [];
        if (specificActions.length === 0) return;

        specificActions.forEach(action => {
            if (action.icon && !this.iconCache.has(action.icon)) {
                this.notifyCerveau('ui:icon.request', {
                    iconName: action.icon,
                    requesterId: `toolbar-preload-${action.icon}` // ID pour le suivi, ne correspond à aucun élément
                });
            }
        });
    }

    /**
     * NOUVEAU : Parcourt les actions spécifiques définies dans le canvas
     * et demande au cerveau de pré-charger les icônes manquantes.
     * @private
     */
    _preloadSpecificActionIcons() {
        const specificActions = this.activeFormCanvas?.parametres?.attribute_actions || [];
        if (specificActions.length === 0) return;

        specificActions.forEach(action => {
            if (action.icon && !this.iconCache.has(action.icon)) {
                this.notifyCerveau('ui:icon.request', {
                    iconName: action.icon,
                    requesterId: `toolbar-preload-${action.icon}` // ID pour le suivi, ne correspond à aucun élément
                });
            }
        });
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

    /**
     * NOUVEAU : Crée et affiche les boutons pour les actions spécifiques.
     * @param {Array} actions - Le tableau de configuration des actions venant du FormCanvas.
     * @private
     */
    updateSpecificActionButtons(actions) {
        // On vide le conteneur
        this.specificActionsContainerTarget.innerHTML = '';

        // On affiche ou masque le séparateur en fonction de la présence d'actions
        this.toggleButton(this.specificActionsSeparatorTarget, actions.length > 0);

        if (actions.length === 0) {
            return;
        }

        // On récupère l'ID de l'unique élément sélectionné
        const selectedId = this.selectos[0].id;

        actions.forEach(action => {
            // NOUVELLE LOGIQUE D'URL :
            // Si l'URL contient le placeholder %id%, on le remplace.
            // Sinon, on suppose que l'URL est déjà complète (cas où le PHP a déjà injecté l'ID).
            const finalUrl = action.url.includes('%id%')
                ? action.url.replace('%id%', selectedId)
                : action.url;

            const button = document.createElement('button');
            button.className = 'btn btn-default'; // Utilisation de la classe par défaut pour la cohérence
            button.setAttribute('type', 'button');
            button.setAttribute('title', action.label); // Pour l'infobulle

            // On crée le conteneur pour l'icône
            const iconContainer = document.createElement('div');
            iconContainer.className = 'toolbar-icon';

            button.appendChild(iconContainer);

            // NOUVELLE LOGIQUE : On utilise le cache.
            if (this.iconCache.has(action.icon)) {
                // Si l'icône est en cache, on l'injecte directement.
                iconContainer.innerHTML = this.iconCache.get(action.icon);
            } else {
                // Sinon (cas de fallback), on la demande au serveur.
                iconContainer.id = `toolbar-specific-action-${action.icon.replace(/:/g, '--')}-${selectedId}`;
                this.notifyCerveau('ui:icon.request', {
                    iconName: action.icon,
                    iconSize: 24,
                    requesterId: iconContainer.id
                });
            }

            // On attache l'événement de clic
            button.addEventListener('click', () => {
                this.notifyCerveau(action.event, {
                    url: finalUrl, // Le cerveau reçoit l'URL finale à appeler
                    selection: this.selectos // On passe la sélection complète
                });
            });
            this.specificActionsContainerTarget.appendChild(button);
        });
    }

    /**
     * NOUVEAU : Gère la réception du HTML de l'icône et l'injecte dans le bon conteneur.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event) {
        const { html, requesterId, iconName } = event.detail;
    
        // Étape 1 : Mettre en cache l'icône dans tous les cas.
        if (iconName && html) {
            this.iconCache.set(iconName, html);
        }
    
        // On s'assure que l'événement nous est destiné (l'ID du demandeur commence par 'toolbar-specific-action-')
        // et que le conteneur d'icône correspondant existe dans le DOM.
        if (requesterId && requesterId.startsWith('toolbar-specific-action-')) {
            const iconContainer = this.element.querySelector(`#${requesterId}`);
            if (iconContainer) {
                iconContainer.innerHTML = html;
            }
        }
    }
}