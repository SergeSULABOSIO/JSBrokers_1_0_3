import { Controller } from '@hotwired/stimulus';
import { } from './base_controller.js';

/**
 * @file Ce fichier contient le contrôleur Stimulus 'cerveau'.
 * @description Ce contrôleur implémente le patron de conception Médiateur (Mediator Pattern).
 * Il agit comme le hub de communication central pour toute l'application, recevant des événements
 * de divers composants et orchestrant les réponses appropriées. Il ne doit pas être attaché à un
 * composant d'UI spécifique mais plutôt à un élément global comme `<body>`.
 */

/**
 * @class CerveauController
 * @extends Controller
 * @description Le contrôleur Cerveau est le médiateur central de l'application.
 */
export default class extends Controller {
    /**
     * Méthode du cycle de vie de Stimulus. S'exécute lorsque le contrôleur est connecté au DOM.
     * Met en place l'écouteur d'événement principal `cerveau:event`.
     */
    connect() {
        window.logSequence = window.logSequence || 0; // Initialise le compteur de log global
        this.nomControleur = "Cerveau";
        this.currentIdEntreprise = null;
        this.displayState = {
            rubricName: 'Tableau de bord',
            action: 'Initialisation',
            activeTabName: 'Principal', // NOUVEAU
            result: 'Prêt',
            selectionCount: 0,
            timestamp: null // NOUVEAU : Ajout du timestamp à l'état
        };
        /**
         * @property {Object<string, {selectionState: Array, selectionIds: Set, numericAttributesAndValues: Object, activeTabFormCanvas: Object}>} tabsState
         * @description La mémoire à court terme du cerveau.
         * Stocke l'état de chaque onglet (principal et contextuel).
         * La clé est l'ID de l'onglet (ex: 'principal', 'collection-contacts-for-1'),
         * et la valeur est un objet contenant l'état de cet onglet.
         */
        this.tabsState = {};

        /**
         * @property {Object} _tabStateTemplate
         * @description Un modèle pour l'état initial d'un nouvel onglet, utilisé pour la documentation et l'initialisation.
         * @private
         * @property {string} elementId - L'ID de l'élément DOM du contrôleur list-manager associé.
         */
        this._tabStateTemplate = {
            selectionState: [],
            selectionIds: new Set(),
            numericAttributesAndValues: {},
            activeTabFormCanvas: null,
            searchCriteria: {},
            elementId: null,
            serverRootName: null
        };

        this.currentIdInvite = null;

        this.activeParentId = null; // NOUVEAU : Pour stocker l'ID du parent de l'onglet actif.
        console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 -  🧠 Cerveau prêt à orchestrer.`);
        this.boundHandleEvent = this.handleEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleEvent);
    }

    /**
     * Méthode du cycle de vie de Stimulus. Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('cerveau:event', this.boundHandleEvent);
    }


    
    /**
     * Point d'entrée unique pour tous les événements destinés au Cerveau.
     * Analyse le type d'événement et délègue l'action appropriée.
     * @param {CustomEvent} event - L'événement personnalisé reçu.
     * @property {object} event.detail - Le conteneur de données de l'événement.
     * @property {string} event.detail.type - Le type d'action demandé (ex: 'ui:component.load').
     * @property {string} event.detail.source - Le nom du contrôleur qui a émis l'événement.
     * @property {object} event.detail.payload - Les données spécifiques à l'événement.
     * @property {number} event.detail.timestamp - L'horodatage de l'émission de l'événement.
     */
    handleEvent(event) {
        const { type, source, payload, timestamp } = event.detail;

        // NOUVEAU : Logging élégant et groupé pour les événements entrants.
        console.groupCollapsed(`[${++window.logSequence}] - Code: 1986 - 🧠 Cerveau Reçoit 📥`, `"${type}"`);
        console.log(`| Source:`, source);
        console.log(`| Payload:`, payload);
        console.groupEnd();

        // Validation de base de l'événement
        if (!type || !source || !payload || !timestamp) {
            console.error("🧠 [Cerveau] Événement invalide reçu. Structure attendue: {type, source, payload, timestamp}", event.detail);
            return;
        }

        switch (type) {
            case 'ui:component.load': // Utilisé pour charger une rubrique dans l'espace de travail
                this.tabsState = {}; // On vide la mémoire des onglets lors du chargement d'une nouvelle rubrique
                this.loadWorkspaceComponent(payload.componentName, payload.entityName, payload.idEntreprise, payload.idInvite);
                this.displayState.rubricName = payload.entityName || 'Inconnu';
                break;
            case 'app:context.initialized':
                this._setApplicationContext(payload);
                break;
            case 'app:error.api':
                this._showNotification('Une erreur serveur est survenue. Veuillez réessayer.', 'error');
                break;
            case 'ui:toolbar.close-request':
                this.broadcast('app:workspace.load-default');
                break;
            case 'ui:tab.context-changed':
                this.displayState.activeTabName = payload.tabName;
                // Met à jour l'état interne du cerveau.
                this.activeTabId = payload.tabId; 
                this.activeParentId = payload.parentId || null;

                const storedState = this.tabsState[this.activeTabId];
                if (storedState) {
                    this.displayState.selectionCount = storedState.selectionState.length;
                    this._publishSelectionStatus();

                    this.broadcast('app:context.changed', {
                        selection: storedState.selectionState,
                        numericAttributesAndValues: storedState.numericAttributesAndValues,
                        formCanvas: storedState.activeTabFormCanvas,
                        isTabSwitch: true,
                        searchCriteria: storedState.searchCriteria || {}
                    });
                } else {
                    // patiemment que l'événement 'ui:tab.initialized' arrive pour ce même onglet.
                    this.displayState.selectionCount = 0;
                    this._publishSelectionStatus('Chargement...');
                }
                break;
            case 'ui:context.reset':
                this._getActiveTabState().activeTabFormCanvas = payload.formCanvas;
                this._setSelectionState([]); // Réinitialise la sélection et publie le contexte.
                break;
            case 'app:list.context-ready':
                this._getActiveTabState().activeTabFormCanvas = payload.formCanvas;
                this.broadcast('app:form-canvas.updated', { tabId: payload.tabId, formCanvas: payload.formCanvas });
                break;
            case 'dialog:search.open-request':
                this.broadcast('dialog:search.open-request', payload); // Relay to search-bar-dialog
                break;
            case 'ui:search.submitted': // NOUVEAU : Point d'entrée unique pour toute soumission de recherche
                this._handleSearchRequest(payload.criteria);
                break;
            case 'ui:search.reset-request':
                this._handleSearchRequest({}); // On passe un objet vide pour réinitialiser.
                break;
            case 'ui:dialog.open-request': // Événement unifié pour ouvrir un dialogue
                this.broadcast('app:loading.start');
                this.openDialogBox(payload);
                break;
            case 'ui:toolbar.add-request':
                // LOGIQUE DÉPLACÉE : Le cerveau reçoit une demande simple et la transforme en appel complexe.
                this.broadcast('app:loading.start');
                this._publishSelectionStatus('Ouverture du formulaire de création...');
                this.openDialogBox({
                    entity: {},
                    entityFormCanvas: payload.formCanvas,
                    isCreationMode: true,
                    context: payload.context
                });
                break;
            case 'ui:toolbar.edit-request':
                // LOGIQUE DÉPLACÉE : Le cerveau gère la sélection unique et prépare le dialogue.
                this.broadcast('app:loading.start');
                this._publishSelectionStatus(`Modification de l'élément...`);
                this.openDialogBox({
                    entity: payload.selection[0].entity, // On prend la première (et unique) entité
                    entityFormCanvas: payload.formCanvas,
                    isCreationMode: false,
                    context: payload.context
                });
                break;
            case 'ui:dialog.opened':
                this._publishSelectionStatus(payload.mode === 'creation' ? 'Formulaire prêt pour la saisie.' : 'Formulaire prêt pour modification.');
                this.broadcast('app:loading.stop');
                break;
            case 'app:entity.saved':
                this._requestListRefresh(payload.originatorId);
                this._showNotification('Enregistrement réussi !', 'success');
                break;
            case 'app:form.validation-error':
                this._publishSelectionStatus('Erreur de validation. Veuillez corriger le formulaire.');
                this._showNotification(payload.message || 'Erreur de validation.', 'error');
                break;
            case 'app:base-données:sélection-request':
                console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 - Recherche`, payload);
                const criteriaText = Object.keys(payload.criteria || {}).length > 0 
                    ? `Filtre actif` 
                    : 'Recherche par défaut';
                this.broadcast('app:loading.start');
                this.broadcast('app:list.refresh-request', payload);
                break;
            case 'ui:toolbar.refresh-request':
                this.displayState.action = 'Rafraîchissement manuel';
                this._publishSelectionStatus('Rafraîchissement en cours...');
                this.broadcast('app:loading.start');
                this._requestListRefresh(this.getActiveTabId());
                break;
            case 'app:api.delete-request':
                this._publishSelectionStatus('Suppression en cours...');
                this._handleApiDeleteRequest(payload);
                break;
            case 'dialog:confirmation.request':
                this._publishSelectionStatus('Attente de confirmation...');
                this._requestDeleteConfirmation(payload);
                break;
            case 'app:delete-request': // ANCIENNE ACTION DE LA TOOLBAR, maintenant renommée et gérée ici.
                // LOGIQUE DÉPLACÉE : Le cerveau reçoit la demande de suppression et la transforme en demande de confirmation.
                const deletePayload = {
                    onConfirm: {
                        type: 'app:api.delete-request',
                        payload: {
                            ids: payload.selection.map(s => s.id), // On extrait les IDs
                            url: payload.formCanvas.parametres.endpoint_delete_url, // On extrait l'URL du canvas
                            originatorId: null, // La requête vient de la toolbar principale
                        }
                    },
                    title: 'Confirmation de suppression',
                    body: `Êtes-vous sûr de vouloir supprimer ${payload.selection.length} élément(s) ?`
                };
                this._requestDeleteConfirmation(deletePayload);
                break;
            case 'ui:status.notify':
                this.broadcast('app:status.updated', payload);
                break;
            case 'ui:toolbar.open-request':
                this.broadcast('app:loading.start');
                this._publishSelectionStatus('Ouverture de la vue détaillée...');
                this._handleOpenRequest(payload.selection);
                break;
            case 'app:tab.opened':
                this.broadcast('app:loading.stop');
                break;
            case 'ui:toolbar.select-all-request':
                this.broadcast('app:list.toggle-all-request');
                break;
            case 'app:navigation-rubrique:openned':
                this.broadcast('app:navigation-rubrique:openned', payload);
                break;
            case 'ui:list.selection-completed':
                // La logique de menu contextuel est maintenant gérée ici.
                this._setSelectionState(payload.selectos || [], payload.contextMenuPosition || null);
                break;
            case 'app:loading.start':
                this.broadcast('app:loading.start', payload);
                break;
            case 'app:loading.stop':
                this.broadcast('app:loading.stop', payload);
                break;
            // NOUVEAU : Gère la demande de chargement du contenu d'un onglet
            case 'app:tab-content.load-request':
                this._loadTabContent(payload);
                break;
            // NOUVEAU : La liste a fini son rendu. C'est le signal final du rafraîchissement.
            case 'app:list.rendered':
                // Met à jour le statut et arrête l'indicateur de chargement.
                this._publishSelectionStatus(`Liste chargée : ${payload.itemCount} élément(s)`);
                this.broadcast('app:loading.stop');
                break;
            // NOUVEAU : Stocke l'état initial d'un onglet nouvellement créé.
            case 'ui:tab.initialized':
                const { tabId, state, elementId, serverRootName } = payload;
                // On stocke l'état pour une restauration future.
                if (!this.tabsState[tabId]) {
                    state.elementId = elementId; // On mémorise l'ID de l'élément
                    state.serverRootName = serverRootName; // On mémorise le nom racine pour l'URL
                    this.tabsState[tabId] = state;
                    // console.log(`[${++window.logSequence}] 🧠 [Cerveau] État initialisé et stocké pour le nouvel onglet '${tabId}'.`, this.tabsState[tabId]);
                }

                // Si l'onglet qui vient d'être initialisé est l'onglet actuellement actif,
                // cela signifie qu'un nouvel onglet vient d'être chargé.
                // On met donc à jour le contexte courant de l'application avec cet état initial.
                if (this.activeTabId === tabId) {
                    // console.log(`[${++window.logSequence}] 🧠 [Cerveau] L'onglet initialisé '${tabId}' est actif. Mise à jour du contexte courant.`);
                    // L'onglet que nous attendions est enfin prêt.
                    // On peut maintenant publier son état initial (vide) en toute sécurité.
                    const activeTabState = this._getActiveTabState();

                    this.displayState.selectionCount = activeTabState.selectionState.length;
                    this._publishSelectionStatus(); // Affiche "0 sélection(s)"

                    // On publie le nouveau contexte pour que la toolbar, la barre des totaux et la barre de recherche se mettent à jour.
                    this.broadcast('app:context.changed', {
                        selection: activeTabState.selectionState,
                        numericAttributesAndValues: activeTabState.numericAttributesAndValues,
                        formCanvas: activeTabState.activeTabFormCanvas,
                        isTabSwitch: true, // On signale que c'est un changement d'onglet
                        searchCriteria: activeTabState.searchCriteria
                    });
                }
                break;
            case 'ui:dialog.closed':
                break;
            default:
                console.warn(`-> ATTENTION: Aucun gestionnaire défini pour l'événement "${type}".`);
        }
    }

    /**
     * NOUVEAU : Récupère l'état de l'onglet actif.
     * Si l'onglet n'a pas encore d'état, il en initialise un vide.
     * C'est la seule source de vérité pour l'état d'un onglet.
     * @returns {{selectionState: Array, selectionIds: Set, numericAttributesAndValues: Object, activeTabFormCanvas: Object}}
     * @private
     */
    _getActiveTabState() {
        if (!this.activeTabId) {
            // Fallback au cas où aucun onglet n'est actif, bien que cela ne devrait pas arriver en fonctionnement normal.
            // console.warn("🧠 [Cerveau] _getActiveTabState a été appelé sans onglet actif. Retour d'un état vide temporaire.");
            return { ...this._tabStateTemplate, selectionIds: new Set() }; // Retourne une nouvelle copie
        }
        if (!this.tabsState[this.activeTabId]) {
            // console.log(`[${++window.logSequence}] 🧠 [Cerveau] Initialisation à la volée de l'état pour l'onglet '${this.activeTabId}'.`);
            // Crée une copie du template.
            this.tabsState[this.activeTabId] = { ...this._tabStateTemplate, selectionIds: new Set(), searchCriteria: {} };
        }
        return this.tabsState[this.activeTabId];
    }


    /**
     * NOUVEAU : Charge le contenu HTML pour un onglet de collection et le diffuse.
     * @param {object} payload 
     * @param {string} payload.tabId - L'ID de l'onglet pour la réponse.
     * @param {string} payload.url - L'URL à appeler pour obtenir le contenu.
     * @private
     */
    async _loadTabContent(payload) {
        const { tabId, url, tabName } = payload;
        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Échec du chargement du contenu de l'onglet (statut ${response.status}).`);
            }
            const html = await response.text();
            this.broadcast('view-manager:tab-content.loaded', { tabId, html, tabName });
        } catch (error) {
            console.error(`[Cerveau] Erreur lors du chargement du contenu pour l'onglet ${tabId}:`, error);
            this.broadcast('view-manager:tab-content.loaded', { tabId, html: `<div class="alert alert-danger m-3">${error.message}</div>`, tabName });
        }
    }

    /**
     * Gère une demande d'ouverture d'éléments en diffusant un événement pour chaque entité sélectionnée.
     * @param {Array} selection - Le tableau d'objets "selecto" à ouvrir.
     * @private
     */
    _handleOpenRequest(selection) {
        if (selection && selection.length > 0) {
            selection.forEach(selecto => {
                this.broadcast('app:liste-element:openned', selecto);
            });
        }
    }


    openDialogBox(payload) {
        console.groupCollapsed(`[${++window.logSequence}] ${this.nomControleur} - handleEvent - EDITDIAL(1)`);
        console.log(`| Mode: ${payload.isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', payload.entity);
        console.log('| Canvas:', payload.entityFormCanvas);
        console.groupEnd();

        this.broadcast('app:boite-dialogue:init-request', {
            entity: payload.entity, // Entité vide pour le mode création
            entityFormCanvas: payload.entityFormCanvas,
            isCreationMode: payload.isCreationMode, // Correction: isCreationMode au lieu de isCreateMode
            context: {
                ...payload.context,
                idEntreprise: this.currentIdEntreprise, // CORRECTION : Utiliser la propriété correcte
                idInvite: this.currentIdInvite       // CORRECTION : Utiliser la propriété correcte
            }, 
            parentContext: this.activeParentId ? {
                id: this.activeParentId,
                fieldName: payload.entityFormCanvas && payload.entityFormCanvas.parametres && payload.entityFormCanvas.parametres.parent_entity_field_name
            } : null
        });
    }

    /**
     * Définit un nouvel état de sélection complet et le publie.
     * @param {Array} [selectos=[]] - Le nouveau tableau d'objets "selecto".
     * @param {object|null} [contextMenuPosition=null] - Les coordonnées pour le menu contextuel.
     * @private
     */
    _setSelectionState(selectos = [], contextMenuPosition = null, isTabSwitch = false) {
        const activeTabState = this._getActiveTabState();
        activeTabState.selectionState = selectos;
        activeTabState.selectionIds = new Set(selectos.map(s => s.id));
        
        // CORRECTION : On met à jour l'état du display AVANT de le publier.
        this.displayState.selectionCount = activeTabState.selectionState.length;
        this.displayState.timestamp = new Date(); // On met à jour l'heure.
        
        // NOUVEAU : On appelle la méthode dédiée pour l'affichage de la sélection.
        this._publishSelectionStatus();

        // C'est ce qui permet à la toolbar et à la barre des totaux de se mettre à jour.
        this.broadcast('app:context.changed', {
            selection: activeTabState.selectionState,
            numericAttributesAndValues: activeTabState.numericAttributesAndValues,
            contextMenuPosition: contextMenuPosition, // On passe la position (ou null)
            isTabSwitch: isTabSwitch,
            searchCriteria: activeTabState.searchCriteria,
            formCanvas: activeTabState.activeTabFormCanvas
        });
    }

    /**
     * Définit le contexte principal de l'application (entreprise et invité) et le diffuse.
     * @param {object} payload - Le payload contenant idEntreprise et idInvite.
     * @private
     */
    _setApplicationContext(payload) {
        this.currentIdEntreprise = payload.idEntreprise;
        this.currentIdInvite = payload.idInvite;
        // On relaie l'événement pour que les composants comme la toolbar puissent se mettre à jour.
        // this.broadcast('ui:tab.context-changed', payload); // Désactivé: Le contexte est maintenant diffusé via 'app:context.changed'
    }

    /**
     * Charge le contenu HTML d'un composant pour l'espace de travail et diffuse le résultat.
     * @param {string} componentName Le nom du fichier de template du composant.
     * @fires workspace:component.loaded
     * @private
     */
    async loadWorkspaceComponent(componentName, entityName, idEntreprise, idInvite) {
        // On construit l'URL avec les IDs dans le chemin, comme défini par la route Symfony
        let url = `/espacedetravail/api/load-component/${idInvite}/${idEntreprise}?component=${componentName}`;
        // On ajoute le paramètre 'entity' s'il est fourni
        if (entityName) {
            url += `&entity=${entityName}`;
        }

        // LOG: Vérifier l'URL finale avant l'appel fetch
        console.log(`[${++window.logSequence}] [Cerveau] Appel fetch vers l'URL: ${url}`);
        
        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Erreur serveur (${response.status}): ${response.statusText}`);
            }
            const html = await response.text();

            // On diffuse le HTML aux contrôleurs qui écoutent (ex: espace-de-travail)
            this.broadcast('workspace:component.loaded', { html: html, error: null });

        } catch (error) {
            console.error(`[Cerveau] Échec du chargement du composant '${componentName}':`, error);
            this.broadcast('workspace:component.loaded', { html: null, error: error.message });
        }
    }

    /**
     * Méthode utilitaire pour diffuser un événement à l'échelle de l'application.
     * @param {string} eventName - Le nom de l'événement à diffuser.
     * @param {object} [detail={}] - Le payload à inclure dans `event.detail`.
     * @private
     */
    broadcast(eventName, detail) {
        // NOUVEAU : Logging élégant et groupé pour les événements sortants.
        console.groupCollapsed(`[${++window.logSequence}] - Code: 1986 - 🧠 Cerveau Émet 📤`, `"${eventName}"`);
        console.log(`| Detail:`, detail);
        console.groupEnd();

        document.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail }));
    }

    /**
     * Récupère l'ID de l'onglet actuellement actif depuis le view-manager.
     * @returns {string|null}
     * @private
     */
    getActiveTabId() {
        const viewManagerEl = document.querySelector('[data-controller="view-manager"]');
        if (viewManagerEl && this.application.getControllerForElementAndIdentifier(viewManagerEl, 'view-manager')) {
            return this.application.getControllerForElementAndIdentifier(viewManagerEl, 'view-manager').activeTabId;
        }
        return 'principal'; // Fallback sur la liste principale
    }

    /**
     * Diffuse une demande de rafraîchissement de la liste.
     * @param {string|null} [originatorId=null] - L'ID du composant qui a initié la demande, pour un rafraîchissement ciblé.
     * @param {object} [criteriaPayload={}] - Le payload contenant les critères de recherche.
     * @private
     */
    _requestListRefresh(tabId = null, payload = {}) {
        const targetTabId = tabId || this.activeTabId;
        const tabState = this.tabsState[targetTabId];

        
        // La logique de fetch est maintenant directement dans le cerveau.
        const url = this._buildDynamicQueryUrl(tabState);
        if (!url) {
            console.error("Impossible de rafraîchir la liste : URL non trouvée pour l'onglet", targetTabId);
            return;
        }

        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, // On attend du JSON
            // CORRECTION : On envoie le payload complet (criteria + parentContext)
            body: JSON.stringify(payload),
        })
        .then(response => {
            const contentType = response.headers.get("content-type");
            if (response.ok && contentType && contentType.includes("application/json")) {
                return response.json();
            }
            return response.text().then(text => {
                throw new Error(`Réponse inattendue du serveur (HTML/texte) : ${text.substring(0, 500)}...`);
            });
        })
        .then(data => {
            // CORRECTION : Le flux de mise à jour après un rafraîchissement a été corrigé.

            // 1. On met à jour l'état interne du cerveau avec les nouvelles données.
            tabState.numericAttributesAndValues = data.numericAttributesAndValues || {};
            // La sélection est perdue après un rafraîchissement, on la réinitialise.
            tabState.selectionState = [];
            tabState.selectionIds.clear();

            // 2. On diffuse l'événement pour que le list-manager mette à jour son contenu HTML.
            this.broadcast('app:list.refreshed', { html: data.html, originatorId: tabState.elementId });

            // 3. CRUCIAL : On diffuse le nouveau contexte pour que la barre des totaux et la barre d'outils
            // se mettent à jour avec les nouvelles informations (nouvelles données numériques et sélection vide).
            this.broadcast('app:context.changed', {
                selection: tabState.selectionState,
                numericAttributesAndValues: tabState.numericAttributesAndValues,
                formCanvas: tabState.activeTabFormCanvas,
                searchCriteria: tabState.searchCriteria
            });
        })
        .catch(error => {
            this.broadcast('app:error.api', { 
                error: error.message,
                url: url,
                targetTabId: targetTabId
           });
           // On arrête le chargement en cas d'erreur réseau ou serveur.
           this.broadcast('app:loading.stop');
        });
    }

    /**
     * NOUVEAU : Construit l'URL de requête dynamique pour un onglet donné.
     * @param {object} tabState - L'objet d'état de l'onglet.
     * @returns {string|null} L'URL construite ou null si le contrôleur n'est pas trouvé.
     * @private
     */
    _buildDynamicQueryUrl(tabState) {
        if (!tabState || !tabState.serverRootName) {
            console.error("Impossible de construire l'URL : serverRootName manquant dans l'état de l'onglet.", { tabState });
            return null;
        }

        const { serverRootName } = tabState;
        const idInvite = this.currentIdInvite;
        const idEntreprise = this.currentIdEntreprise;

        // CORRECTION : La route Symfony pour la recherche dynamique attend les IDs
        // dans le chemin (path parameters), et non en paramètres de requête (query parameters).
        return `/admin/${serverRootName}/api/dynamic-query/${idInvite}/${idEntreprise}`;
    }

    /**
     * Diffuse une demande pour afficher une notification (toast).
     * @param {string} text - Le message à afficher.
     * @param {'success'|'error'|'info'|'warning'} [type='info'] - Le type de notification.
     * @private
     */
    _showNotification(text, type = 'info') {
        this.broadcast('app:notification.show', { text, type });
    }

    /**
     * NOUVEAU : Formate et diffuse le message de statut pour le display.
     * Cette fonction est la seule source de vérité pour l'affichage du statut.
     * Elle peut afficher un message d'action temporaire ou l'état de la sélection.
     * @param {string|null} [action=null] - La nouvelle action à afficher. Si null, l'action précédente est conservée.
     * @private
     */
    _publishSelectionStatus(action = null) {
        // On met à jour l'action et le timestamp à chaque publication
        if (action) {
            this.displayState.action = action;
        }
        this.displayState.timestamp = new Date();

        const timestamp = this.displayState.timestamp.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        const selectionCount = this.displayState.selectionCount;
        const rubricName = this.displayState.rubricName;
        const tabName = this.displayState.activeTabName;

        let messageParts = [
            `<span class="fw-bold text-dark">${timestamp}</span>`,
            `<span class="fw-bold text-dark">${rubricName}</span>`
        ];

        if (tabName && tabName.toLowerCase() !== 'principal') {
            messageParts.push(`<span class="fw-bold text-dark">${tabName}</span>`);
        }

        // Si une action est fournie, elle est affichée. Sinon, on affiche le nombre de sélections.
        if (action) {
            messageParts.push(`<span>${action}</span>`);
        } else {
            messageParts.push(`<span>${selectionCount} sélection(s)</span>`);
        }

        const messageHtml = messageParts.join('<span class="mx-2 text-muted">›</span>');
        this.broadcast('app:display.update', { html: messageHtml });
    }

    /**
     * Gère la logique de suppression d'éléments via l'API en exécutant plusieurs requêtes en parallèle.
     * Notifie le reste de l'application en cas de succès ou d'échec.
     * @param {object} payload - Le payload contenant les IDs, l'URL et l'originatorId.
     * @param {number[]} payload.ids - Tableau des IDs des entités à supprimer.
     * @param {string} payload.url - L'URL de base de l'API de suppression.
     * @param {string} [payload.originatorId] - L'ID du composant qui a initié la demande (pour un rafraîchissement ciblé).
     * @private
     */
    _handleApiDeleteRequest(payload) {
        const { ids, url, originatorId } = payload;

        // On crée un tableau de promesses, une pour chaque requête de suppression.
        const deletePromises = ids.map(id => {
            const deleteUrl = `${url}/${id}`; // Construit l'URL finale pour chaque ID.
            return fetch(deleteUrl, { method: 'DELETE' })
                .then(response => {
                    if (!response.ok) throw new Error(`Erreur lors de la suppression de l'élément ${id}.`);
                    return response.json();
                });
        });

        // On attend que toutes les promesses de suppression soient résolues.
        Promise.all(deletePromises)
            .then(results => {
                const message = results.length > 1 ? `${results.length} éléments supprimés avec succès.` : 'Élément supprimé avec succès.';
                console.log(`${this.nomControleur} - SUCCÈS: Suppression(s) réussie(s).`, results);
                this._showNotification(message, 'success');
                // On réinitialise l'état de la sélection et on notifie tout le monde (toolbar, etc.)
                this._setSelectionState([]);
                this._requestListRefresh(originatorId);
                this.broadcast('ui:confirmation.close');
            })
            .catch(error => {
                console.error("-> ERREUR: Échec de la suppression API.", error);
                // Notifie la boîte de dialogue de confirmation de l'erreur pour qu'elle l'affiche.
                this.broadcast('ui:confirmation.error', { error: error.message || "La suppression a échoué." });
                // La boîte de dialogue de confirmation gérera sa propre fermeture après affichage de l'erreur.
            });
    }

    /**
     * Met à jour l'état de la sélection en ajoutant ou retirant un élément.
     * @param {object} selecto - L'objet de sélection d'une ligne.
     * @private
     */
    updateSelectionState(selecto) {
        const { id, isChecked } = selecto;

        if (isChecked) {
            if (!this.selectionIds.has(id)) {
                this.selectionState.push(selecto);
                this.selectionIds.add(id);
            }
        } else {
            if (this.selectionIds.has(id)) {
                this.selectionState = this.selectionState.filter(item => item.id !== id);
                this.selectionIds.delete(id);
            }
        }
    }

    /**
     * Gère une demande de suppression provenant de la barre d'outils en construisant
     * et en diffusant une demande de confirmation.
     * @param {object} payload - Le payload de l'événement, contenant `selection` et `actionConfig`.
     * @private
     */
    _requestDeleteConfirmation(payload) {
        // Cette méthode reçoit maintenant un payload déjà formaté.
        // Son seul rôle est de diffuser la demande d'affichage du dialogue.
        const itemCount = payload.onConfirm?.payload?.ids?.length || 0;
        if (itemCount === 0) return;
    
        this.broadcast('ui:confirmation.request', {
            title: payload.title,
            body: payload.body,
            onConfirm: payload.onConfirm
        });
    }
    

    /**
     * NOUVEAU : Trouve le nom du champ parent en parcourant le canvas de formulaire.
     * @param {object} formCanvas - Le canvas du formulaire à inspecter.
     * @returns {string|null} Le nom du champ parent (ex: 'notificationSinistre') ou null.
     * @private
     */
    _findParentFieldName(formCanvas) {
        if (!formCanvas || !Array.isArray(formCanvas.form_layout)) {
            return null;
        }

        for (const row of formCanvas.form_layout) {
            for (const col of row.colonnes || []) {
                for (const field of col.champs || []) {
                    if (typeof field === 'object' && field.widget === 'collection' && field.options?.parentFieldName) {
                        // On a trouvé la première collection, on retourne son champ parent.
                        return field.options.parentFieldName;
                    }
                }
            }
        }
        return null; // Pas de champ de collection trouvé.
    }

    /**
     * NOUVEAU : Gère la logique de recherche et de réinitialisation pour éviter la répétition de code (DRY).
     * @param {object} [criteria={}] - Les critères de recherche. Un objet vide pour une réinitialisation.
     * @private
     */
    _handleSearchRequest(criteria = {}) {
        const activeState = this._getActiveTabState();
        activeState.searchCriteria = criteria;

        // Notifie le début du chargement pour afficher le squelette et la barre de progression.
        this.broadcast('app:loading.start', { originatorId: activeState.elementId });

        // Construit le payload complet pour la requête.
        const refreshPayload = {
            criteria: activeState.searchCriteria,
            // NOUVEAU : La logique complexe de recherche du contexte est maintenant dans sa propre fonction.
            parentContext: this._getParentContextForSearch()
        };

        // Log pour le débogage.
        console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 - Recherche`, { refreshPayload: refreshPayload });

        // Lance la requête de rafraîchissement de la liste.
        this._requestListRefresh(this.activeTabId, refreshPayload);
    }

    /**
     * NOUVEAU : Détermine et retourne le contexte parent pour une recherche.
     * @returns {{id: string, fieldName: string}|null} L'objet de contexte parent ou null.
     * @private
     */
    _getParentContextForSearch() {
        // Détermine le contexte parent de manière robuste, en se basant sur l'ID de l'onglet.
        const parentIdMatch = this.activeTabId.match(/-for-(\d+)$/);
        const parentId = parentIdMatch ? parentIdMatch[1] : this.activeParentId;

        if (!parentId) {
            return null;
        }

        let parentFieldName = null;
        // Pour une collection, le nom du champ liant au parent est dans le formCanvas de l'onglet principal.
        const principalState = this.tabsState['principal'];
        if (principalState) {
            parentFieldName = this._findParentFieldName(principalState.activeTabFormCanvas);
        }

        return {
            id: parentId,
            fieldName: parentFieldName
        };
    }
}