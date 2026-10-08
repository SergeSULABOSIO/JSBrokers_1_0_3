import { Controller } from '@hotwired/stimulus';
import { actionsVisibles, urlAction } from './actions-groupees.js';
import { BarreActions } from './barre-actions.js';

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
        
        this.boundHandleContextUpdate = this.handleContextUpdate.bind(this);

        // Le RENDU des actions spécifiques (boutons, familles, menus, icônes, clavier) est
        // partagé avec la barre du dialogue d'entité : barre-actions.js. La barre ne
        // garde ici que ce qui lui est propre — quelles actions, et ce qu'un clic envoie.
        //
        // UNE SEULE INSTANCE : Stimulus appelle initialize() avant connect(), qui le
        // rappelle. Deux barres, ce seraient deux écouteurs clavier — chaque flèche
        // sauterait alors deux boutons.
        this.barreActions ??= new BarreActions(this.specificActionsContainerTarget, {
            declencher: (action) => this._declencher(action),
            prefixe: 'toolbar-action',
            barre: this.element,
        });

        this.initializeToolbarState();
        this.setupEventListeners();

        // Pré-charger les icônes spécifiques dès le début.
        this.barreActions.precharger(this.activeFormCanvas?.parametres?.attribute_actions);
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
        this.barreActions?.detruire();
        this.barreActions = null;
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
        // `creation_interdite` : certaines entités n'existent que RATTACHÉES à une autre
        // (une condition de partage sans bénéficiaire ni affaire n'est qu'une règle
        // orpheline). Leur rubrique reste consultable et éditable, mais la création se
        // fait depuis la fiche parente.
        //
        // Le bouton est GRISÉ, PAS MASQUÉ : un bouton qui disparaît laisse croire à un
        // droit manquant ou à un bug ; désactivé avec son infobulle, il dit où aller.
        const creationInterdite = canvasParams.creation_interdite === true;
        // LE MOTIF VIENT DU CANEVAS. Il était écrit ici, au nom des conditions de
        // partage — la seule rubrique qui posait alors le drapeau. La deuxième (les
        // reversements de rétrocommission) aurait donc annoncé à l'utilisateur d'aller
        // créer sa pièce « depuis la fiche d'un partenaire ». Chaque rubrique dit
        // désormais où l'on crée la sienne.
        const motifCreation = canvasParams.creation_interdite_message
            || 'Cet enregistrement se crée depuis la fiche à laquelle il se rattache.';
        const canEdit = selectionCount === 1 && !!canvasParams.endpoint_submit_url;
        const canDelete = selectionCount > 0 && !!canvasParams.endpoint_delete_url;
        const canOpen = selectionCount > 0; // L'ouverture est généralement toujours possible si sélection.

        // Règle : "Ajouter" est visible si le canvas le permet.
        this.toggleButton(this.btajouterTarget, canAdd);
        this.setButtonDisabled(this.btajouterTarget, creationInterdite, motifCreation);

        // Règle : "Modifier" est visible uniquement pour une sélection unique.
        this.toggleButton(this.btmodifierTarget, canEdit);

        // Règle : "Ouvrir" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btouvrirTarget, canOpen);

        // Règle : "Supprimer" est visible dès qu'il y a au moins une sélection (unique ou multiple).
        this.toggleButton(this.btsupprimerTarget, canDelete);

        // Les actions spécifiques que la sélection permet : MÊME règle que le menu
        // contextuel (actions-groupees.js#actionsVisibles) — portées `sans_selection`,
        // `multi` ou sélection unique, puis la condition de chaque action.
        this.updateSpecificActionButtons(actionsVisibles(canvasParams.attribute_actions, this.selectos));
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
     * Grise un bouton sans le faire disparaître, et dit POURQUOI par une infobulle.
     *
     * Masquer une action légitime ailleurs laisse l'utilisateur chercher un droit qu'il a
     * peut-être, ou soupçonner une panne. Un bouton inactif qui explique où aller répond
     * à la question au lieu de la poser.
     *
     * @param {HTMLElement|undefined} target
     * @param {boolean} disabled
     * @param {string} raison - infobulle affichée à l'état inactif.
     * @private
     */
    setButtonDisabled(target, disabled, raison = '') {
        if (!target) return;
        target.classList.toggle('is-disabled', disabled);
        target.setAttribute('aria-disabled', disabled ? 'true' : 'false');
        const bouton = target.matches('button, a') ? target : target.querySelector('button, a');
        if (bouton) {
            bouton.disabled = disabled;
            if (disabled && raison) bouton.setAttribute('title', raison);
            else bouton.removeAttribute('title');
        }
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

        // Un seul arrêt de tabulation dans la barre, posé dès l'affichage.
        this.barreActions.rafraichirNavigation();
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
     * Affiche les actions spécifiques — rendu délégué au module partagé.
     * @param {Array} actions - Le tableau de configuration des actions venant du FormCanvas.
     * @private
     */
    updateSpecificActionButtons(actions) {
        // On affiche ou masque le séparateur en fonction de la présence d'actions
        this.toggleButton(this.specificActionsSeparatorTarget, actions.length > 0);

        // REGROUPEMENT PAR FAMILLE, et au-delà de TOOLBAR_MAX_ACTIONS_EN_LIGNE entrées,
        // le surplus rejoint « Autres actions » : la barre du workspace partage sa ligne
        // avec les actions CRUD, elle doit garder une largeur prévisible.
        this.barreActions.afficher(actions, { maxInline: TOOLBAR_MAX_ACTIONS_EN_LIGNE });
    }

    /**
     * Un clic sur une action spécifique : l'action part au cerveau avec la sélection.
     * @private
     */
    _declencher(action) {
        // Une action transverse n'a pas de ligne : l'identifiant peut manquer, et le
        // lire sans précaution faisait échouer TOUT le rendu de la barre.
        const selectedId = this.selectos[0]?.id ?? null;
        this.notifyCerveau(action.event, { url: urlAction(action, selectedId), selection: this.selectos });
    }
}
