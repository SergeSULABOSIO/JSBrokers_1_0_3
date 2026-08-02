import { Controller } from '@hotwired/stimulus';
import { grouperActions, urlAction } from './actions-groupees.js';

/**
 * Nombre maximal d'entrées d'actions spécifiques affichées EN LIGNE dans la barre.
 * Au-delà, le surplus est replié dans un menu « Autres actions » : la barre garde
 * une largeur prévisible quel que soit le nombre d'actions déclarées par la rubrique.
 */
const TOOLBAR_MAX_ACTIONS_EN_LIGNE = 4;

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
        // CORRECTION : Définir le nom du contrôleur en premier pour que les événements envoyés depuis initialize() soient valides.
        this.nomControleur = "Toolbar";

        // Isolation multi-onglets : détecte l'onglet workspace parent
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

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
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { selection, formCanvas } = event.detail;
        this.selectos = selection || [];
        this.activeFormCanvas = formCanvas || {};
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

        // Gérer les actions spécifiques à l'entité, avec filtrage conditionnel par état.
        // Une action déclarée `multi: true` est visible dès 1 sélection (unique ou
        // multiple) ; les autres restent strictement réservées à la sélection UNIQUE
        // (comportement historique inchangé).
        const rawActions   = canvasParams.attribute_actions || [];
        const entityData   = this.selectos[0]?.entity || {};
        const specificActions = rawActions.filter(action => {
            const countOk = action.multi === true ? selectionCount >= 1 : selectionCount === 1;
            if (!countOk) return false;
            if (!action.condition) return true;
            return entityData[action.condition.field] == action.condition.value;
        });
        this.updateSpecificActionButtons(specificActions);
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
                    // CORRECTION : Remplacer ':' par '--' pour créer un ID de requête valide comme sélecteur CSS.
                    requesterId: `toolbar-preload-${action.icon.replace(/:/g, '--')}` 
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
        this._fermerMenuOuvert();

        // On affiche ou masque le séparateur en fonction de la présence d'actions
        this.toggleButton(this.specificActionsSeparatorTarget, actions.length > 0);

        if (actions.length === 0) {
            return;
        }

        const selectedId = this.selectos[0].id;

        // REGROUPEMENT PAR FAMILLE : une famille d'actions (les mouvements d'une
        // police, par exemple) devient UN bouton qui déploie ses membres. Au-delà de
        // MAX_ACTIONS_EN_LIGNE entrées, le surplus rejoint « Autres actions » — la
        // barre reste lisible quel que soit le nombre d'actions déclarées.
        grouperActions(actions, { maxInline: TOOLBAR_MAX_ACTIONS_EN_LIGNE }).forEach((entree) => {
            this.specificActionsContainerTarget.appendChild(
                entree.type === 'groupe'
                    ? this._creerBoutonGroupe(entree, selectedId)
                    : this._creerBoutonAction(entree.action, selectedId),
            );
        });
    }

    /**
     * Bouton d'une action simple (comportement historique : icône seule + infobulle).
     * @private
     */
    _creerBoutonAction(action, selectedId) {
        const button = document.createElement('button');
        button.className = 'btn btn-default';
        button.setAttribute('type', 'button');
        button.setAttribute('title', action.label);
        button.setAttribute('aria-label', action.label);
        button.setAttribute('data-controller', 'ripple');

        this._poserIcone(button, action.icon, `toolbar-specific-action-${action.icon.replace(/:/g, '--')}-${selectedId}`);

        button.addEventListener('click', () => {
            this.notifyCerveau(action.event, { url: urlAction(action, selectedId), selection: this.selectos });
        });

        return button;
    }

    /**
     * Bouton d'une FAMILLE : icône + chevron, ouvrant un menu déroulant qui liste
     * les actions membres (icône + libellé, cette fois explicite — un menu a la
     * place d'écrire, contrairement à la barre).
     * @private
     */
    _creerBoutonGroupe(groupe, selectedId) {
        const wrapper = document.createElement('div');
        wrapper.className = 'toolbar-groupe';

        const button = document.createElement('button');
        button.className = 'btn btn-default toolbar-groupe-bouton';
        button.setAttribute('type', 'button');
        button.setAttribute('title', groupe.label);
        button.setAttribute('aria-label', groupe.label);
        button.setAttribute('aria-haspopup', 'menu');
        button.setAttribute('aria-expanded', 'false');
        // Le chevron « ce bouton déploie un menu » est un ::after CSS, PAS un élément :
        // le circuit d'icônes remplace l'innerHTML du bouton à l'arrivée de l'icône
        // (handleIconLoaded) et effacerait un enfant posé ici.
        this._poserIcone(button, groupe.icon, `toolbar-groupe-${groupe.icon.replace(/:/g, '--')}-${selectedId}`);

        const menu = document.createElement('ul');
        menu.className = 'toolbar-groupe-menu';
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', groupe.label);
        menu.hidden = true;

        groupe.actions.forEach((action) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'menuitem');
            item.setAttribute('tabindex', '-1');

            const icone = document.createElement('span');
            icone.className = 'toolbar-groupe-menu-icone';
            icone.setAttribute('aria-hidden', 'true');
            this._poserIcone(icone, action.icon, `toolbar-groupe-item-${action.icon.replace(/:/g, '--')}-${selectedId}-${crypto.randomUUID()}`, 18);
            item.appendChild(icone);

            const libelle = document.createElement('span');
            libelle.textContent = action.label;
            item.appendChild(libelle);

            item.addEventListener('click', (event) => {
                event.stopPropagation();
                this._fermerMenuOuvert();
                this.notifyCerveau(action.event, { url: urlAction(action, selectedId), selection: this.selectos });
            });
            menu.appendChild(item);
        });

        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const ouvert = !menu.hidden;
            this._fermerMenuOuvert();
            if (!ouvert) this._ouvrirMenu(button, menu);
        });

        wrapper.append(button, menu);

        return wrapper;
    }

    /** Ouvre le menu d'une famille et arme la fermeture (clic extérieur / Échap). @private */
    _ouvrirMenu(button, menu) {
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        this.menuOuvert = { button, menu };

        this.boundFermerMenu = (event) => {
            if (!menu.contains(event.target)) this._fermerMenuOuvert();
        };
        this.boundEchapMenu = (event) => {
            if (event.key === 'Escape') {
                this._fermerMenuOuvert();
                button.focus(); // restitution du focus au déclencheur (WCAG 2.4.3)
            }
        };
        document.addEventListener('click', this.boundFermerMenu);
        document.addEventListener('keydown', this.boundEchapMenu);

        menu.querySelector('[role="menuitem"]')?.focus();
    }

    /** Referme le menu de famille ouvert, s'il y en a un. @private */
    _fermerMenuOuvert() {
        if (this.boundFermerMenu) {
            document.removeEventListener('click', this.boundFermerMenu);
            this.boundFermerMenu = null;
        }
        if (this.boundEchapMenu) {
            document.removeEventListener('keydown', this.boundEchapMenu);
            this.boundEchapMenu = null;
        }
        if (!this.menuOuvert) return;
        this.menuOuvert.menu.hidden = true;
        this.menuOuvert.button.setAttribute('aria-expanded', 'false');
        this.menuOuvert = null;
    }

    /**
     * Pose une icône dans un conteneur : depuis le cache si possible, sinon en la
     * demandant au cerveau (circuit d'icônes existant, inchangé).
     * @private
     */
    _poserIcone(conteneur, iconName, requesterId, iconSize = 31) {
        if (!iconName) return;
        if (this.iconCache.has(iconName)) {
            this.handleIconLoaded({ detail: { html: this.iconCache.get(iconName), requesterId, iconName } }, conteneur);

            return;
        }
        conteneur.id = requesterId;
        this.notifyCerveau('ui:icon.request', { iconName, iconSize, requesterId });
    }

    /**
     * NOUVEAU : Gère la réception du HTML de l'icône et l'injecte dans le bon conteneur.
     * @param {CustomEvent} event L'événement contenant le HTML de l'icône.
     * @param {HTMLElement|null} directTarget Le bouton cible si l'icône vient du cache.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event, directTarget = null) {
        const { html, requesterId, iconName } = event.detail;
    
        // Étape 1 : Mettre en cache l'icône dans tous les cas.
        if (iconName && html) {
            this.iconCache.set(iconName, html);
        }

        // Étape 2 : Trouver la cible (soit via l'ID de la requête, soit la cible directe passée en paramètre)
        const targetButton = directTarget || (requesterId ? this.element.querySelector(`#${requesterId}`) : null);

        // Étape 3 : Injecter l'icône de manière intelligente.
        if (targetButton && html) {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const svgElement = tempDiv.querySelector('svg');
            if (svgElement) {
                svgElement.classList.add('toolbar-icon');
                svgElement.setAttribute('aria-hidden', 'true');
                targetButton.innerHTML = '';
                targetButton.appendChild(svgElement);
            }
        }
    }
}