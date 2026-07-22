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
        // Objets attachés au contexte du chat IA actif ({type, id}) : alimente
        // les badges « déjà en contexte » des listes, y compris celles ouvertes
        // après l'attache (re-diffusé sur ui:tab.initialized).
        this.assistantContexteActif = [];
        this.displayState = {
            rubricName: 'Tableau de bord',
            action: 'Initialisation',
            activeTabName: 'Principal', // NOUVEAU
            result: 'Prêt',
            selectionCount: 0,
            pageItemCount: 0,   // Nombre d'éléments affichés sur la page courante
            totalItems: null,   // Nombre total d'éléments résultant de la recherche
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
            serverRootName: null,
            currentPage: 1,
            pageItemCount: 0, // éléments affichés sur la page courante (par onglet)
            totalItems: null, // total de la recherche (par onglet)
        };

        this.currentIdInvite = null;
        this.currentWorkspaceTabId = null; // Onglet workspace actif — sert à scopter tabsState et enrichir les broadcasts

        this.activeParentId = null; // NOUVEAU : Pour stocker l'ID du parent de l'onglet actif.
        this._iconCache = new Map(); // cache partagé : clé = `${iconName}::${iconSize}`
        console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 -  🧠 Cerveau prêt à orchestrer.`);
        this.boundHandleEvent = this.handleEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleEvent);
        this.boundSyncContextFromDialog = this._syncContextFromDialog.bind(this);
        document.addEventListener('app:boite-dialogue:init-request', this.boundSyncContextFromDialog);
    }

    /**
     * Méthode du cycle de vie de Stimulus. Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('cerveau:event', this.boundHandleEvent);
        document.removeEventListener('app:boite-dialogue:init-request', this.boundSyncContextFromDialog);
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
                this.currentWorkspaceTabId = payload.workspaceTabId || null;
                if (this.currentWorkspaceTabId) {
                    // On réinitialise l'état de cet onglet ET on y stocke le nom de la rubrique
                    this.tabsState[this.currentWorkspaceTabId] = { __rubricName: payload.entityName || 'Inconnu' };
                } else {
                    this.tabsState = {}; // Compat. si workspaceTabId absent
                }
                this.loadWorkspaceComponent(payload.componentName, payload.entityName, payload.idEntreprise, payload.idInvite);
                this.displayState.rubricName = payload.entityName || 'Inconnu'; // Fallback si pas de workspaceTabId
                break;
            case 'ui:workspace-tab.switched': // Onglet workspace déjà chargé activé par l'utilisateur
                this.currentWorkspaceTabId = payload.workspaceTabId || null;
                break;
            case 'app:context.initialized':
                this._setApplicationContext(payload);
                break;
            case 'app:error.api':
                this._showNotification('Une erreur serveur est survenue. Veuillez réessayer.', 'error');
                break;
            case 'ui:toolbar.close-request':
                this.broadcast('app:workspace.close-active-tab');
                break;
            case 'ui:tab.context-changed':
                // NOUVEAU : Ajout pour gérer le changement de contexte d'onglet
                // Met à jour l'état interne du cerveau avec l'ID de l'onglet actif et le nom de l'onglet.
                // Cela permet de savoir quel onglet est actuellement affiché à l'utilisateur.
                // La logique de mise à jour de l'affichage et de publication de l'état est gérée ci-dessous.
                this.displayState.activeTabName = payload.tabName;
                // Met à jour l'état interne du cerveau.
                this.activeTabId = payload.tabId;
                this.activeParentId = payload.parentId || null;

                const storedState = this._getCurrentWsTabState()[this.activeTabId];
                if (storedState) {
                    this.displayState.selectionCount = storedState.selectionState.length;
                    // Restaure les compteurs mémorisés pour cet onglet (page + total).
                    this.displayState.pageItemCount = storedState.pageItemCount ?? 0;
                    this.displayState.totalItems = storedState.totalItems ?? null;
                    this._publishSelectionStatus();

                    this.broadcast('app:context.changed', {
                        selection: storedState.selectionState,
                        numericAttributesAndValues: storedState.numericAttributesAndValues,
                        formCanvas: storedState.activeTabFormCanvas,
                        isTabSwitch: true,
                        searchCriteria: storedState.searchCriteria || {},
                        searchCanvas: storedState.searchCanvas || null,
                        entiteNom: storedState.entiteNom || null,
                        workspaceTabId: this.currentWorkspaceTabId,
                    });
                } else {
                    // L'onglet n'a pas encore d'état (contenu en cours de chargement) :
                    // on RÉINITIALISE immédiatement tout le chrome contextuel (toolbar,
                    // totaux, badges de recherche) au lieu de le laisser refléter
                    // l'onglet précédent. Le vrai contexte arrivera via 'ui:tab.initialized'.
                    this.displayState.selectionCount = 0;
                    this._publishSelectionStatus('Chargement...');
                    this.broadcast('app:context.changed', {
                        selection: [],
                        numericAttributesAndValues: {},
                        formCanvas: null,
                        isTabSwitch: true,
                        searchCriteria: {},
                        searchCanvas: null, // null = « inconnu » : la barre de recherche garde ses critères en attendant
                        entiteNom: null,
                        workspaceTabId: this.currentWorkspaceTabId,
                    });
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
            case 'ui:pagination.page-changed': {
                const pageState = this._getActiveTabState();
                pageState.currentPage = payload.page;
                this.broadcast('app:loading.start', { originatorId: pageState.elementId, workspaceTabId: this.currentWorkspaceTabId });
                this._requestListRefresh(this.activeTabId, {
                    criteria: pageState.searchCriteria || {},
                    parentContext: this._getParentContextForSearch(),
                    page: payload.page,
                });
                break;
            }
            case 'ui:search.submitted': // NOUVEAU : Point d'entrée unique pour toute soumission de recherche
                this._handleSearchRequest(payload.criteria);
                break;
            case 'ui:search.reset-request':
                this._handleSearchRequest({}); // On passe un objet vide pour réinitialiser.
                break;
            case 'ui:filter.preset': {
                // Chip de filtre rapide (ex. statut de paiement des tranches) : pose ou
                // retire (valeur vide = « Toutes ») le critère synthétique en conservant
                // les autres filtres actifs, puis relance la recherche page 1. Le badge de
                // la barre de recherche suit via app:context.changed après rafraîchissement.
                const presetState = this._getActiveTabState();
                const presetCriteria = { ...(presetState.searchCriteria || {}) };
                if (payload.value === undefined || payload.value === null || payload.value === '') {
                    delete presetCriteria[payload.key];
                } else {
                    presetCriteria[payload.key] = {
                        operator: '=',
                        value: payload.value,
                        label: payload.label || String(payload.value),
                    };
                }
                this._handleSearchRequest(presetCriteria);
                break;
            }
            case 'ui:toolbar.add-request':
                // LOGIQUE DÉPLACÉE : Le cerveau reçoit une demande simple et la transforme en appel complexe.
                this.broadcast('app:loading.start');
                this._publishSelectionStatus('Ouverture du formulaire de création...');
                this.openDialogBox({
                    entity: {},
                    entityFormCanvas: payload.formCanvas,
                    isCreationMode: true,
                    context: payload.context,
                    // NOUVEAU: On passe le contexte parent s'il est fourni (par une collection)
                    parentContext: payload.parentContext || null
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
                    context: payload.context,
                    // NOUVEAU: On passe le contexte parent s'il est fourni (par une collection)
                    parentContext: payload.parentContext || null
                });
                break;
            case 'ui:dialog.opened':
                this._publishSelectionStatus(payload.mode === 'creation' ? 'Formulaire prêt pour la saisie.' : 'Formulaire prêt pour modification.');
                this.broadcast('app:loading.stop');
                break;
            case 'app:entity.saved':
                this._showNotification('Enregistrement réussi !', 'success');
                const originatorId = payload.originatorId;
                if (originatorId) {
                    // Si l'ID de l'initiateur commence par 'collection-', c'est un widget de collection.
                    if (String(originatorId).startsWith('collection-')) {
                        // On diffuse une demande de rafraîchissement ciblée pour cette collection.
                        this.broadcast('app:list.refresh-request', { originatorId });
                    } else {
                        // Sinon, c'est une liste principale dans un onglet, on utilise l'ancienne logique.
                        this._requestListRefresh(originatorId);
                    }
                }
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
            case 'renew:set-not-renewable':
                this._handleSetNotRenewable(payload);
                break;
            case 'dialog:confirmation.request':
                this._publishSelectionStatus('Attente de confirmation...');
                this._requestDeleteConfirmation(payload);
                break;
            case 'app:delete-request': // ANCIENNE ACTION DE LA TOOLBAR, maintenant renommée et gérée ici.
                // LOGIQUE DÉPLACÉE : Le cerveau reçoit la demande de suppression et la transforme en demande de confirmation.
                // NOUVEAU : On détermine si la requête vient d'un widget collection ou d'une liste/onglet.
                const isFromCollectionWidget = !!payload.context?.originatorId;

                const deletePayload = {
                    onConfirm: {
                        type: 'app:api.delete-request',
                        payload: {
                            ids: payload.selection.map(s => s.id), // On extrait les IDs
                            url: payload.formCanvas.parametres.endpoint_delete_url, // On extrait l'URL du canvas
                            originatorId: payload.context?.originatorId || this.getActiveTabId(),
                            isFromCollectionWidget: isFromCollectionWidget // On transmet l'info
                        }
                    },
                    title: 'Confirmation de suppression',
                    // NOUVEAU : Message de base + détails des éléments pour plus de clarté.
                    body: `Vous êtes sur le point de supprimer définitivement ${payload.selection.length} élément(s).`,
                    itemDescriptions: payload.selection.map(s => s.name || `Élément #${s.id}`)
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
                this.broadcast('app:list.toggle-all-request', { workspaceTabId: this.currentWorkspaceTabId });
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
            // NOUVEAU : Gère la demande d'ouverture d'une entité liée depuis un accordéon.
            // Il relaie simplement la demande en tant qu'événement d'ouverture standard.
            case 'ui:related-entity.open-request':
                this.broadcast('app:liste-element:openned', payload);
                break;
            // NOUVEAU : Gère la demande de chargement du contenu d'un onglet
            case 'app:tab-content.load-request':
                this._loadTabContent(payload);
                break;
            // NOUVEAU : La liste a fini son rendu. C'est le signal final du rafraîchissement.
            case 'app:list.rendered': {
                // Mémorise les compteurs (page + total) pour l'onglet courant et rafraîchit
                // la barre de statut avec « X affiché(s) sur Y ».
                const renderedState = this._getActiveTabState();
                renderedState.pageItemCount = payload.itemCount ?? 0;
                renderedState.totalItems = payload.totalItems ?? payload.itemCount ?? null;
                this.displayState.pageItemCount = renderedState.pageItemCount;
                this.displayState.totalItems = renderedState.totalItems;
                this._publishSelectionStatus();
                this.broadcast('app:loading.stop');
                break;
            }
            // NOUVEAU : Stocke l'état initial d'un onglet nouvellement créé.
            case 'ui:tab.initialized':
                const { tabId, state, elementId, serverRootName, workspaceTabId: initWsId } = payload;
                // Utiliser le workspaceTabId du payload si fourni : currentWorkspaceTabId peut déjà
                // pointer vers un autre onglet si l'utilisateur a cliqué rapidement sur deux onglets.
                const wsStateTarget = initWsId
                    ? (this.tabsState[initWsId] || (this.tabsState[initWsId] = {}))
                    : this._getCurrentWsTabState();
                if (!wsStateTarget[tabId]) {
                    state.elementId = elementId;
                    state.serverRootName = serverRootName;
                    wsStateTarget[tabId] = state;
                } else {
                    // L'état existait déjà (chargements concurrents) : on met tout de même à
                    // jour les compteurs de la barre de statut fournis par le list-manager.
                    wsStateTarget[tabId].pageItemCount = state.pageItemCount;
                    wsStateTarget[tabId].totalItems = state.totalItems;
                }

                // Si l'onglet qui vient d'être initialisé est l'onglet actuellement actif,
                // cela signifie qu'un nouvel onglet vient d'être chargé.
                // On met donc à jour le contexte courant de l'application avec cet état initial.
                if (this.activeTabId === tabId) {
                    // Utiliser wsStateTarget[tabId] directement plutôt que _getActiveTabState() :
                    // _getActiveTabState() crée un état vide via currentWorkspaceTabId, qui peut pointer
                    // vers un autre onglet workspace en cas de chargements concurrents, ce qui préempte
                    // l'entrée et empêche le prochain ui:tab.initialized de stocker son serverRootName.
                    const activeTabState = wsStateTarget[tabId];

                    this.displayState.selectionCount = activeTabState.selectionState.length;
                    // Compteurs initiaux (page + total) fournis par le list-manager.
                    this.displayState.pageItemCount = activeTabState.pageItemCount ?? 0;
                    this.displayState.totalItems = activeTabState.totalItems ?? null;
                    this._publishSelectionStatus(); // Affiche "0 sélection(s)"

                    // On publie le nouveau contexte pour que la toolbar, la barre des totaux et la barre de recherche se mettent à jour.
                    this.broadcast('app:context.changed', {
                        selection: activeTabState.selectionState,
                        numericAttributesAndValues: activeTabState.numericAttributesAndValues,
                        formCanvas: activeTabState.activeTabFormCanvas,
                        isTabSwitch: true, // On signale que c'est un changement d'onglet
                        searchCriteria: activeTabState.searchCriteria,
                        searchCanvas: activeTabState.searchCanvas || null,
                        entiteNom: activeTabState.entiteNom || null,
                        workspaceTabId: this.currentWorkspaceTabId,
                    });
                }

                // Badges « déjà en contexte » du chat IA : une liste ouverte APRÈS
                // l'attache reçoit l'état courant dès son initialisation.
                if (this.assistantContexteActif.length > 0) {
                    this.broadcast('app:assistant.contexte.updated', { objets: this.assistantContexteActif });
                }
                break;
            case 'ui:dialog.content-request':
                this.handleDialogContentRequest(event.detail);
                break;
            // NOUVEAU : Gère la demande de fermeture d'une boîte de dialogue.
            case 'ui:dialog.close-request':
                this.broadcast('app:dialog.do-close', { dialogId: payload.dialogId });
                break;
            case 'ui:note.preview-request':
                this.handleNotePreviewRequest(payload);
                break;
            case 'ui:soa.view-request':
                this.handleSoaViewRequest(payload);
                break;
            case 'ui:soa.copy-link-request':
                this.handleSoaCopyLinkRequest(payload);
                break;
            case 'ui:soa.send-request':
                this.handleSoaSendRequest(payload);
                break;
            case 'client:soa.envoye': // succès d'un envoi du SOA par e-mail via le picker
                this._showNotification(payload.message || 'Relevé de compte envoyé.', 'success');
                break;
            case 'ui:soa.revoke-request':
                this.handleSoaRevokeRequest(payload);
                break;
            case 'ui:soa.docs-picker-request':
                this.handleSoaDocsPickerRequest(payload);
                break;
            case 'client:soa.revoke-execute': // confirmation validée → DELETE effectif
                this._handleSoaRevokeExecute(payload);
                break;
            case 'ui:assistant.add-to-chat': // action « Ajouter au chat avec l'assistant IA » (toolbar / menu contextuel)
                this.handleAssistantAddToChat(payload);
                break;
            case 'ui:assistant.contexte-operation': // cycle de feedback des opérations sur le contexte du chat IA
                this._handleAssistantContexteOperation(payload);
                break;
            case 'ui:bordereau.analysis-request':
                this.handleBordereauAnalysisRequest(payload);
                break;
            case 'ui:invite.resend-request':
                this.handleInviteResendRequest(payload);
                break;
            case 'ui:invite.portefeuille-form-request':
                this.handleInvitePortefeuilleFormRequest(payload);
                break;
            case 'ui:invite.delete-portefeuille':
                this.handleInviteDeletePortefeuille(payload);
                break;
            case 'ui:avenant.piste-derivee-form-request':
                this.handleAvenantPisteDeriveeFormRequest(payload);
                break;
            case 'ui:tranche.signaler-paiement-prime':
                this.handleTrancheSignalerPaiementPrime(payload);
                break;
            case 'ui:avenant.delete-piste-derivee':
                this.handleAvenantDeletePisteDerivee(payload);
                break;
            case 'ui:client.portefeuille-picker-request':
                this.handleClientPortefeuillePickerRequest(payload);
                break;
            case 'ui:portefeuille.client-picker-request':
                this.handlePortefeuilleClientPickerRequest(payload);
                break;
            case 'ui:client.retirer-portefeuille':
                this.handleClientRetirerPortefeuille(payload);
                break;
            case 'client:portefeuille.detach-request': // confirmation validée → DELETE effectif
                this._handleClientPortefeuilleDetach(payload);
                break;
            case 'client:portefeuille.updated': { // succès d'une affectation/transfert via le picker
                this._showNotification(payload.message || 'Portefeuille mis à jour.', 'success');
                // Barre de progression du workspace + squelette de la liste pendant le
                // rafraîchissement (arrêtés par app:list.rendered), comme la pagination.
                const pfUpdatedState = this._getActiveTabState();
                this._publishSelectionStatus('Actualisation de la liste...');
                this.broadcast('app:loading.start', { originatorId: pfUpdatedState.elementId, workspaceTabId: this.currentWorkspaceTabId });
                this._requestListRefresh(this.getActiveTabId());
                break;
            }
            case 'ui:bordereau.edit-linked-note':
                this.handleBordereauEditLinkedNote(payload);
                break;
            case 'bordereau:submit-analysis': // NOUVEAU : Gère la soumission de l'analyse du bordereau
                console.log("[Cerveau] Reçu 'bordereau:submit-analysis'. Délégation à _handleSubmitBordereauAnalysis.");
                this._handleSubmitBordereauAnalysis(payload);
                break;
            case 'bordereau:save-analysis-state': // NOUVEAU : Gère la sauvegarde de l'état de l'analyse du bordereau
                console.log("[Cerveau] Reçu 'bordereau:save-analysis-state'. Délégation à _handleSaveBordereauAnalysisState.");
                this._handleSaveBordereauAnalysisState(payload);
                break;
            case 'ui:icon.request':
                this.handleIconRequest(payload);
                break;
            case 'ui:dialog.closed':
                break;
            case 'ket:mutation.execute':
                // Exécution d'un plan de mutation de l'assistant IA : traitée
                // directement par assistant-chat_controller (qui appelle l'endpoint
                // et rejoue le journal). No-op ici — évite l'avertissement générique.
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
            return { ...this._tabStateTemplate, selectionIds: new Set() };
        }
        const wsState = this._getCurrentWsTabState();
        if (!wsState[this.activeTabId]) {
            wsState[this.activeTabId] = { ...this._tabStateTemplate, selectionIds: new Set(), searchCriteria: {} };
        }
        return wsState[this.activeTabId];
    }

    /**
     * Retourne le sous-dictionnaire d'état pour l'onglet workspace actuellement actif.
     * Clé : currentWorkspaceTabId (ou '__global__' avant tout chargement de rubrique).
     * @returns {Object}
     * @private
     */
    _getCurrentWsTabState() {
        const key = this.currentWorkspaceTabId || '__global__';
        if (!this.tabsState[key]) this.tabsState[key] = {};
        return this.tabsState[key];
    }


    /**
     * NOUVEAU : Charge le contenu HTML pour un onglet de collection et le diffuse.
     * @param {object} payload 
     * @param {string} payload.tabId - L'ID de l'onglet pour la réponse.
     * @param {string} payload.url - L'URL à appeler pour obtenir le contenu.
     * @private
     */
    async _loadTabContent(payload) {
        const { tabId, url, tabName, workspaceTabId } = payload;
        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Échec du chargement du contenu de l'onglet (statut ${response.status}).`);
            }
            const html = await response.text();
            this.broadcast('view-manager:tab-content.loaded', { tabId, html, tabName, workspaceTabId });
        } catch (error) {
            console.error(`[Cerveau] Erreur lors du chargement du contenu pour l'onglet ${tabId}:`, error);
            // `failed: true` : le view-manager ne marquera pas ce contenu comme chargé
            // (persistance) — la prochaine activation de l'onglet retentera le fetch.
            this.broadcast('view-manager:tab-content.loaded', { tabId, html: `<div class="alert alert-danger m-3">${error.message}</div>`, tabName, workspaceTabId, failed: true });
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
        // Lazy-init : sur le dashboard, app:context.initialized n'est jamais émis,
        // donc currentIdEntreprise/currentIdInvite restent null. On les récupère
        // depuis le payload de la première dialog ouverte (ex: dbRenewCtxPiste).
        if (!this.currentIdEntreprise && payload.context?.idEntreprise) {
            this.currentIdEntreprise = payload.context.idEntreprise;
        }
        if (!this.currentIdInvite && payload.context?.idInvite) {
            this.currentIdInvite = payload.context.idInvite;
        }

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
                // Si l'appelant (ex: collection_controller) n'a pas fourni d'originatorId,
                // on cible l'onglet actif. C'est le cas d'une création/édition lancée depuis la
                // toolbar de la liste principale (qui n'envoie pas de contexte). Sans cela,
                // app:entity.saved ne pourrait pas rafraîchir la liste principale après l'enregistrement.
                // Même fallback que pour la suppression (cf. case 'app:delete-request').
                originatorId: payload.context?.originatorId || this.getActiveTabId(),
                idEntreprise: this.currentIdEntreprise, // CORRECTION : Utiliser la propriété correcte
                idInvite: this.currentIdInvite       // CORRECTION : Utiliser la propriété correcte
            },
            // CORRECTION: On passe directement le contexte parent reçu dans le payload.
            // C'est la responsabilité de l'appelant (toolbar, collection_controller) de le fournir correctement.
            // La logique de calcul a été retirée car elle était incorrecte et redondante.
            parentContext: payload.parentContext || null
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
            searchCanvas: activeTabState.searchCanvas || null,
            entiteNom: activeTabState.entiteNom || null,
            formCanvas: activeTabState.activeTabFormCanvas,
            workspaceTabId: this.currentWorkspaceTabId,
        });
    }

    /**
     * Synchronise currentIdEntreprise/currentIdInvite depuis tout événement app:boite-dialogue:init-request.
     * Nécessaire sur le dashboard où app:context.initialized n'est jamais émis :
     * les dialogs ouverts directement (ex: dbRenewCtxPiste) bypassent openDialogBox,
     * donc le lazy-init de openDialogBox ne suffit pas.
     * @private
     */
    _syncContextFromDialog(event) {
        const context = event.detail?.context;
        if (!this.currentIdEntreprise && context?.idEntreprise) {
            this.currentIdEntreprise = context.idEntreprise;
        }
        if (!this.currentIdInvite && context?.idInvite) {
            this.currentIdInvite = context.idInvite;
        }
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
        // Capturer le workspaceTabId AVANT tout await : currentWorkspaceTabId peut changer
        // si l'utilisateur clique sur un second onglet avant que la réponse arrive.
        const workspaceTabId = this.currentWorkspaceTabId;

        // On construit l'URL avec les IDs dans le chemin, comme défini par la route Symfony
        let url = `/espacedetravail/api/load-component/${idInvite}/${idEntreprise}?component=${componentName}`;
        // On ajoute le paramètre 'entity' s'il est fourni
        if (entityName) {
            url += `&entity=${entityName}`;
        }

        // LOG: Vérifier l'URL finale avant l'appel fetch
        console.log(`[${++window.logSequence}] [Cerveau] Appel fetch vers l'URL: ${url} (workspaceTabId: ${workspaceTabId})`);

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Erreur serveur (${response.status}): ${response.statusText}`);
            }
            const html = await response.text();

            // On diffuse le HTML en incluant le workspaceTabId capturé au début de la requête
            this.broadcast('workspace:component.loaded', { html: html, error: null, workspaceTabId });

        } catch (error) {
            console.error(`[Cerveau] Échec du chargement du composant '${componentName}':`, error);
            this.broadcast('workspace:component.loaded', { html: null, error: error.message, workspaceTabId });
        }
    }

    /**
     * Méthode utilitaire pour diffuser un événement à l'échelle de l'application.
     * @param {string} eventName - Le nom de l'événement à diffuser.
     * @param {object} [detail={}] - Le payload à inclure dans `event.detail`.
     * @private
     */
    broadcast(eventName, detail = {}) {
        // Enrichit chaque broadcast avec l'onglet workspace courant pour que les contrôleurs
        // enfants (view-manager, etc.) puissent filtrer les événements qui ne les concernent pas.
        // Le workspaceTabId fourni explicitement dans detail (ex: réponse à une requête async) prime
        // sur this.currentWorkspaceTabId, qui peut avoir changé depuis l'envoi de la requête.
        const enrichedDetail = { workspaceTabId: this.currentWorkspaceTabId, ...detail };
        console.groupCollapsed(`[${++window.logSequence}] - Code: 1986 - 🧠 Cerveau Émet 📤`, `"${eventName}"`);
        console.log(`| Detail:`, enrichedDetail);
        console.groupEnd();

        document.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail: enrichedDetail }));
    }

    /**
     * Récupère l'ID de l'onglet actuellement actif depuis le view-manager.
     * @returns {string|null}
     * @private
     */
    getActiveTabId() {
        // this.activeTabId est maintenu à jour via ui:tab.context-changed / handleTabBecameActive
        // (y compris lors des switches entre onglets workspace).
        return this.activeTabId || 'principal';
    }

    /**
     * Diffuse une demande de rafraîchissement de la liste.
     * @param {string|null} [originatorId=null] - L'ID du composant qui a initié la demande, pour un rafraîchissement ciblé.
     * @param {object} [criteriaPayload={}] - Le payload contenant les critères de recherche.
     * @private
     */
    _requestListRefresh(tabId = null, payload = {}) {
        const targetTabId = tabId || this.activeTabId;
        const tabState = this._getCurrentWsTabState()[targetTabId];


        // La logique de fetch est maintenant directement dans le cerveau.
        const url = this._buildDynamicQueryUrl(tabState);
        if (!url) {
            console.error("Impossible de rafraîchir la liste : URL non trouvée pour l'onglet", targetTabId);
            return;
        }

        // Les critères ACTIFS de l'onglet (dont le périmètre par défaut « Mon
        // portefeuille » amorcé au chargement) sont TOUJOURS retransmis : sans eux,
        // un rafraîchissement (après enregistrement, transfert de portefeuille,
        // refresh manuel…) relançait la requête SANS filtre — un client transféré
        // hors du périmètre restait affiché. Un payload explicite garde la priorité.
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                criteria: tabState.searchCriteria || {},
                // Le contexte parent est dérivé de l'onglet ACTIF : on ne l'ajoute que
                // si c'est bien lui qu'on rafraîchit (cas des onglets de collection).
                parentContext: targetTabId === this.activeTabId ? this._getParentContextForSearch() : null,
                ...payload,
                page: payload.page ?? tabState.currentPage ?? 1,
            }),
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
                // 1. On met à jour l'état interne du cerveau avec les nouvelles données.
                tabState.numericAttributesAndValues = data.numericAttributesAndValues || {};
                if (data.pagination) tabState.currentPage = data.pagination.currentPage;
                // La sélection est perdue après un rafraîchissement, on la réinitialise.
                tabState.selectionState = [];
                tabState.selectionIds.clear();

                // 2. On diffuse l'événement pour que le list-manager mette à jour son contenu HTML et la pagination.
                this.broadcast('app:list.refreshed', {
                    html: data.html,
                    originatorId: tabState.elementId,
                    pagination: data.pagination || null,
                    workspaceTabId: this.currentWorkspaceTabId,
                });

                // 3. CRUCIAL : On diffuse le nouveau contexte pour que la barre des totaux et la barre d'outils
                // se mettent à jour avec les nouvelles informations (nouvelles données numériques et sélection vide).
                this.broadcast('app:context.changed', {
                    selection: tabState.selectionState,
                    numericAttributesAndValues: tabState.numericAttributesAndValues,
                    formCanvas: tabState.activeTabFormCanvas,
                    searchCriteria: tabState.searchCriteria,
                    workspaceTabId: this.currentWorkspaceTabId,
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
        // Cherche le nom de la rubrique dans l'état de l'onglet workspace courant (isolé par onglet),
        // avec fallback sur displayState.rubricName pour la compatibilité sans workspaceTabId.
        const wsState = this.currentWorkspaceTabId ? this.tabsState[this.currentWorkspaceTabId] : null;
        const rubricName = wsState?.__rubricName || this.displayState.rubricName || 'Inconnu';
        const tabName = this.displayState.activeTabName;

        let messageParts = [
            `<span class="fw-bold text-dark">${timestamp}</span>`,
            `<span class="fw-bold text-dark">${rubricName}</span>`
        ];

        if (tabName && tabName.toLowerCase() !== 'principal') {
            messageParts.push(`<span class="fw-bold text-dark">${tabName}</span>`);
        }

        // Si une action transitoire est fournie, elle est affichée. Sinon, on affiche les
        // compteurs de la recherche (éléments affichés sur la page / total) puis la sélection.
        if (action) {
            messageParts.push(`<span>${action}</span>`);
        } else {
            const total = this.displayState.totalItems;
            if (total !== null && total !== undefined) {
                const page = this.displayState.pageItemCount ?? 0;
                messageParts.push(
                    `<span><strong class="text-dark">${page}</strong> affiché(s) sur <strong class="text-dark">${total}</strong></span>`
                );
            }
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
        const { ids, url, originatorId, isFromCollectionWidget } = payload;

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

                // NOUVEAU : Logique de rafraîchissement intelligente basée sur l'origine.
                if (isFromCollectionWidget) {
                    // C'est un widget de collection (dans un formulaire), on diffuse un événement simple.
                    this.broadcast('app:list.refresh-request', { originatorId });
                } else {
                    // C'est une liste principale ou un onglet de collection, on utilise la logique de rafraîchissement d'onglet.
                    this._requestListRefresh(originatorId);
                }

                this.broadcast('ui:confirmation.close');
            })
            .catch(error => {
                console.error("-> ERREUR: Échec de la suppression API.", error);
                // Notifie la boîte de dialogue de confirmation de l'erreur pour qu'elle l'affiche.
                this.broadcast('ui:confirmation.error', { error: error.message || "La suppression a échoué." });
                // La boîte de dialogue de confirmation gérera sa propre fermeture après affichage de l'erreur.
            });
    }

    _handleSetNotRenewable(payload) {
        const { avenantId } = payload;
        if (!avenantId) {
            this.broadcast('ui:confirmation.error', { error: 'ID avenant manquant.' });
            return;
        }
        const fd = new FormData();
        fd.append('avenantId', avenantId);
        fetch('/admin/piste/api/set-not-renewable', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.broadcast('ui:confirmation.close');
                    document.dispatchEvent(new CustomEvent('app:dashboard.sidebar.reload'));
                } else {
                    this.broadcast('ui:confirmation.error', { error: data.message || 'Erreur lors de la mise à jour.' });
                }
            })
            .catch(() => {
                this.broadcast('ui:confirmation.error', { error: 'Erreur de communication avec le serveur.' });
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
        activeState.currentPage = 1; // toute nouvelle recherche repart de la page 1

        // Notifie le début du chargement pour afficher le squelette et la barre de progression.
        this.broadcast('app:loading.start', { originatorId: activeState.elementId });

        // Construit le payload complet pour la requête.
        const refreshPayload = {
            criteria: activeState.searchCriteria,
            // NOUVEAU : La logique complexe de recherche du contexte est maintenant dans sa propre fonction.
            parentContext: this._getParentContextForSearch(),
            page: 1,
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
        const principalState = this._getCurrentWsTabState()['principal'];
        if (principalState) {
            parentFieldName = this._findParentFieldName(principalState.activeTabFormCanvas);
        }

        return {
            id: parentId,
            fieldName: parentFieldName
        };
    }

    /**
     * Gère une demande de contenu HTML pour une boîte de dialogue.
     * Récupère le contenu et le renvoie à l'instance spécifique qui l'a demandé.
     * @param {object} detail - Les détails de l'événement.
     */
    async handleDialogContentRequest(detail) {
        const { dialogId, endpoint, entity, context, entityFormCanvas } = detail.payload;

        // DIAGNOSTIC : On affiche la structure de l'objet pour vérifier la présence de la clé 'icone'.
        console.log(`[Cerveau - DIAGNOSTIC] Structure de 'entityFormCanvas' pour le dialogue '${dialogId}':`, entityFormCanvas);

        try {
            // 1. On commence avec l'URL de base
            let urlString = endpoint;

            // 2. Si c'est une édition, on ajoute l'ID à l'URL
            if (entity && entity.id) {
                urlString += `/${entity.id}`;
            }

            // 3. On crée un objet URL pour gérer facilement les paramètres
            const url = new URL(urlString, window.location.origin);

            // 4. On ajoute les paramètres du contexte à l'URL
            if (context) {
                if (context.defaultValue) {
                    url.searchParams.set(`default_${context.defaultValue.target}`, context.defaultValue.value);
                }
                if (context.idEntreprise) {
                    url.searchParams.set('idEntreprise', context.idEntreprise);
                }
                if (context.idInvite) {
                    url.searchParams.set('idInvite', context.idInvite);
                }
                if (context.idAvenant) {
                    url.searchParams.set('idAvenant', context.idAvenant);
                }
            }

            // 5. On lance la requête avec l'URL finale
            const finalUrl = url.pathname + url.search;
            console.log(`[Cerveau] - Requête de contenu pour ${dialogId} vers ${finalUrl}`);

            const response = await fetch(finalUrl);
            if (!response.ok) {
                throw new Error(`Le serveur a répondu avec une erreur ${response.status}`);
            }

            const html = await response.text();

            // On crée un conteneur temporaire pour pouvoir interroger le HTML sans l'ajouter au DOM.
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = html;
            const contentRoot = tempDiv.querySelector('[data-icon-name]');
            const icon = contentRoot ? contentRoot.dataset.iconName : null; // Extraction de l'alias !


            // NOUVEAU : Déterminer le titre correct en fonction du mode (création/édition)
            const isCreationMode = !(entity && entity.id);
            let title = isCreationMode
                ? (entityFormCanvas.parametres.titre_creation || "Création")
                : (entityFormCanvas.parametres.titre_modification || "Modification de l'élément #%id%").replace('%id%', entity.id);

            // On renvoie le contenu à l'instance de dialogue qui l'a demandé
            this.broadcast('ui:dialog.content-ready', {
                dialogId,
                html,
                title: title,
                icon: icon // On passe l'icône
            });

        } catch (error) {
            console.error(`[Cerveau] Erreur lors de la récupération du contenu pour ${dialogId}:`, error);
            // En cas d'erreur, on la renvoie aussi à l'instance concernée
            this.broadcast('ui:dialog.content-ready', {
                dialogId,
                error: { message: error.message || "Une erreur inconnue est survenue." }
            });
        }
    }

    /**
     * NOUVEAU : Gère une demande de récupération d'icône depuis le serveur.
     * @param {object} payload 
     */
    async handleIconRequest(payload) {
        const { iconName, iconSize = 24, requesterId } = payload;
        if (!iconName) return;

        const cacheKey = `${iconName}::${iconSize}`;

        // Cache hit : promesse en vol ou résolue — une seule requête réseau par clé
        if (this._iconCache.has(cacheKey)) {
            try {
                const html = await this._iconCache.get(cacheKey);
                this.broadcast('app:icon.loaded', { iconName, html, requesterId });
            } catch {
                this._iconCache.delete(cacheKey);
                return this.handleIconRequest(payload);
            }
            return;
        }

        // Cache PERSISTANT (localStorage) : survit aux rechargements de page — aucune
        // requête serveur pour une icône déjà vue lors d'une session précédente.
        // try/catch : localStorage peut être indisponible (mode privé, quota).
        const storageKey = `jsb-icon-v1::${cacheKey}`;
        let stored = null;
        try {
            stored = window.localStorage.getItem(storageKey);
        } catch { /* localStorage indisponible : on retombe sur le fetch */ }
        if (stored) {
            this._iconCache.set(cacheKey, Promise.resolve(stored));
            // Diffusion différée d'un microtask : le demandeur émet souvent sa requête
            // en pleine construction (porte-icône pas encore dans le DOM, écouteur
            // app:icon.loaded pas encore abonné) — une réponse synchrone serait perdue.
            await Promise.resolve();
            this.broadcast('app:icon.loaded', { iconName, html: stored, requesterId });
            return;
        }

        const url = `/api/icon/api/get-icon?name=${encodeURIComponent(iconName)}&size=${iconSize}`;

        // Stocker la promesse immédiatement → requêtes simultanées partagent ce fetch
        const fetchPromise = fetch(url)
            .then(r => {
                if (!r.ok) throw new Error(`Icon fetch failed: ${r.status}`);
                return r.text();
            })
            .catch(err => {
                this._iconCache.delete(cacheKey); // ne pas cacher les erreurs
                throw err;
            });

        this._iconCache.set(cacheKey, fetchPromise);

        try {
            const html = await fetchPromise;
            this._iconCache.set(cacheKey, Promise.resolve(html));
            // Persistance pour les prochaines sessions (silencieux si quota/indisponible).
            try { window.localStorage.setItem(storageKey, html); } catch { /* no-op */ }
            this.broadcast('app:icon.loaded', { iconName, html, requesterId });
        } catch (error) {
            console.error(`[Cerveau] Failed to fetch icon '${iconName}':`, error);
            this.broadcast('app:icon.loaded', {
                iconName,
                html: `<!-- error loading icon ${iconName} -->`,
                requesterId
            });
        }
    }

    /**
     * Gère la demande de prévisualisation d'une note ou le téléchargement d'un PDF.
     * L'URL fournie peut pointer vers l'API Note (/admin/note/api/get-preview-url/{noteId})
     * ou vers l'API Bordereau (/admin/bordereau/api/get-linked-note-preview-url/{bordereauId}).
     * Dans les deux cas, on appelle d'abord l'API pour obtenir la vraie URL de prévisualisation
     * (qui contient l'ID de la note), puis on charge le contenu workspace.
     * @param {object} payload
     * @param {string} payload.url - L'URL de l'API à appeler.
     */
    async handleNotePreviewRequest(payload) {
        if (!payload.url) {
            console.error("[Cerveau] Demande d'action sur la note reçue sans URL.", payload);
            this._showNotification("Impossible de réaliser l'action : URL manquante.", "error");
            return;
        }

        try {
            this._publishSelectionStatus('Chargement de la note…');
            this.broadcast('app:loading.start');

            // 1. Appel de l'URL API pour obtenir la vraie previewUrl (contenant l'ID de la note)
            const apiResponse = await fetch(payload.url);
            const apiResult = await apiResponse.json();
            if (!apiResponse.ok) throw apiResult;

            const previewUrl = apiResult.previewUrl;
            if (!previewUrl) throw new Error("URL de prévisualisation manquante.");

            // 2. Si c'est un téléchargement PDF, ouvrir dans un nouvel onglet
            if (previewUrl.includes('/download-pdf/')) {
                window.open(previewUrl, '_blank');
                this._publishSelectionStatus('Téléchargement en cours.');
                return;
            }

            // 3. Extraire le noteId depuis la previewUrl (/admin/note/apercu/{noteId})
            const noteId = previewUrl.split('/').filter(Boolean).at(-1).split('?')[0];
            const response = await fetch(`/admin/note/workspace-apercu/${noteId}`);
            const result = await response.json();
            if (!response.ok) throw result;

            // 4. Charger le contenu dans un nouvel onglet de la zone de travail principale.
            this.broadcast('app:workspace.inject-html', {
                html:      result.html,
                title:     result.title,
                iconAlias: 'note',
                tabKey:    `note-preview-${noteId}`,
                loadUrl:   `/admin/note/workspace-apercu/${noteId}`,
            });
            this._publishSelectionStatus('Note chargée.');
        } catch (error) {
            console.error("[Cerveau] Erreur lors du chargement de la note :", error);
            this._showNotification(error.message || "Erreur lors du chargement de la note.", "error");
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Ouvre le SOA d'un client dans un onglet de la zone de travail.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/soa/client/{id}/workspace'
     */
    async handleSoaViewRequest(payload) {
        if (!payload.url) {
            console.error('[Cerveau] handleSoaViewRequest() : URL manquante.', payload);
            this._showNotification('Impossible de charger le SOA : URL manquante.', 'error');
            return;
        }
        try {
            this._publishSelectionStatus('Chargement du relevé de compte…');
            this.broadcast('app:loading.start');

            const response = await fetch(payload.url);
            const result = await response.json();
            if (!response.ok) throw result;

            const clientId = payload.url.split('/').filter(Boolean).at(-2);
            this.broadcast('app:workspace.inject-html', {
                html:      result.html,
                title:     result.title,
                iconAlias: 'client',
                tabKey:    `soa-client-${clientId}`,
                loadUrl:   payload.url,
            });
            this._publishSelectionStatus('Relevé de compte chargé.');
        } catch (error) {
            console.error('[Cerveau] Erreur lors du chargement du SOA :', error);
            this._showNotification(error.message || 'Erreur lors du chargement du relevé de compte.', 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Copie dans le presse-papiers le lien PUBLIC du SOA (utilisable par l'assuré
     * sans compte) : le POST crée ou prolonge le jeton d'accès côté serveur (+30 j,
     * même règle que l'envoi par e-mail) et retourne l'URL tokenisée.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/soa/api/client/{id}/lien-public'
     */
    async handleSoaCopyLinkRequest(payload) {
        if (!payload.url) {
            console.error('[Cerveau] handleSoaCopyLinkRequest() : URL manquante.', payload);
            this._showNotification('Impossible de copier le lien : URL manquante.', 'error');
            return;
        }
        try {
            this.broadcast('app:loading.start');
            const response = await fetch(payload.url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.url) throw new Error(data.message || `Erreur serveur ${response.status}`);

            await navigator.clipboard.writeText(data.url);
            this._showNotification(data.message || 'Lien client copié dans le presse-papiers.', 'success');
        } catch (error) {
            console.error('[Cerveau] Erreur lors de la copie du lien SOA :', error);
            this._showNotification(error.message || 'Impossible de copier le lien. Veuillez réessayer.', 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Ouvre le picker « Documents de la police » du SOA (menu contextuel de la
     * section Polices) : tous les documents attachés au pipe de la police (piste,
     * cotation, police, client), avec téléchargement direct. Le contrôleur Stimulus
     * « soa-docs-picker » s'auto-connecte à l'insertion (cf. _openStandalonePicker).
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/soa/api/police/{id}/documents'
     */
    async handleSoaDocsPickerRequest(payload) {
        await this._openStandalonePicker(payload.url, {
            controllerName: 'soa-docs-picker',
            errorLabel: 'les documents de la police',
        });
    }

    /**
     * Demande de révocation du lien public du SOA (action conditionnelle « Révoquer le
     * lien du SOA », visible quand hasLienSoa est vrai). Confirmation via la modale
     * générique — broadcast DIRECT de ui:confirmation.request (même parade que le
     * retrait de portefeuille : action non-delete, l'alerte « irréversible » est
     * masquée car un nouvel envoi recrée un lien).
     * @param {object} payload
     * @param {string} payload.url - '/admin/soa/api/client/{id}/revoquer-lien' (id déjà résolu)
     * @param {Array}  [payload.selection] - fourni par la toolbar / le menu contextuel
     */
    handleSoaRevokeRequest(payload) {
        if (!payload.url) {
            console.error('[Cerveau] handleSoaRevokeRequest() : URL manquante.', payload);
            this._showNotification('Impossible de révoquer le lien : contexte manquant.', 'error');
            return;
        }
        const clientName = payload.selection?.[0]?.name || 'le client sélectionné';
        this.broadcast('ui:confirmation.request', {
            title: 'Révoquer le lien du SOA',
            body: "Le lien d'accès en ligne au relevé de compte sera immédiatement invalidé : les e-mails déjà envoyés ne permettront plus de le consulter. Vous pourrez générer un nouveau lien à tout moment (envoi ou copie).",
            itemDescriptions: [clientName],
            showIrreversible: false,
            onConfirm: {
                type: 'client:soa.revoke-execute',
                payload: { url: payload.url },
            },
        });
    }

    /**
     * Exécute la révocation après confirmation : DELETE, puis notification (message
     * serveur), fermeture de la confirmation et rafraîchissement de la liste active
     * (hasLienSoa se recalcule au refresh → l'action disparaît).
     * @private
     */
    async _handleSoaRevokeExecute(payload) {
        if (!payload.url) {
            this.broadcast('ui:confirmation.error', { error: 'Contexte de révocation manquant.' });
            return;
        }
        try {
            const response = await fetch(payload.url, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            this._showNotification(data.message || 'Lien du relevé de compte révoqué.', 'success');
            const revokeState = this._getActiveTabState();
            this._publishSelectionStatus('Actualisation de la liste...');
            this.broadcast('app:loading.start', { originatorId: revokeState.elementId, workspaceTabId: this.currentWorkspaceTabId });
            this._requestListRefresh(this.getActiveTabId());
            this.broadcast('ui:confirmation.close');
        } catch (error) {
            console.error('[Cerveau] _handleSoaRevokeExecute() failed:', error);
            this.broadcast('ui:confirmation.error', { error: error.message || 'La révocation a échoué.' });
        }
    }

    /**
     * Ouvre la boîte d'ENVOI DU SOA PAR E-MAIL au client (choix du destinataire parmi
     * l'e-mail du client et ceux de ses contacts + message d'accompagnement facultatif).
     * Le contrôleur Stimulus « soa-envoi-picker » s'auto-connecte à l'insertion et porte
     * tout le comportement (cf. _openStandalonePicker).
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/soa/client/{id}/envoi-picker'
     */
    async handleSoaSendRequest(payload) {
        await this._openStandalonePicker(payload.url, {
            controllerName: 'soa-envoi-picker',
            errorLabel: "l'envoi du relevé de compte",
        });
    }

    /**
     * « Ajouter au chat avec l'assistant IA » (toolbar / menu contextuel,
     * multi-sélection) : attache les objets sélectionnés au contexte de la
     * conversation du chat ACTIF en colonne 4 — ou crée une conversation,
     * l'alimente puis l'ouvre s'il n'y a aucun chat. La sécurité (module IA,
     * premium, canRead par objet, scoping entreprise) est re-validée côté
     * serveur, fail-closed : ici on ne fait qu'orchestrer.
     * @param {object} payload
     * @param {Array<object>} payload.selection - Selectos ({id, entityType, entity, ...}).
     */
    async handleAssistantAddToChat(payload) {
        const objets = (payload.selection || [])
            .map(s => ({ type: s.entityType, id: parseInt(s.id, 10) }))
            .filter(o => o.type && Number.isInteger(o.id) && o.id > 0);
        if (objets.length === 0) return;

        // Chat déjà ouvert : priorité au panneau VISIBLE (onglet actif de la
        // col-4), sinon le dernier ouvert. Le chat fait le POST lui-même (il
        // possède son URL) et émet le cycle ui:assistant.contexte-operation —
        // source UNIQUE du feedback (barre + toast), pas de doublon ici.
        const chats = [...document.querySelectorAll('[data-controller="assistant-chat"]')];
        const chatActif = chats.find(el => el.offsetParent !== null) || chats.at(-1);
        if (chatActif) {
            chatActif.dispatchEvent(new CustomEvent('assistant:contexte.attach-request', { detail: { objets } }));
            return;
        }

        // Aucun chat ouvert : create → attach → ouverture du partial en col-4
        // (les puces arrivent déjà rendues côté serveur).
        this._handleAssistantContexteOperation({ phase: 'start' });
        let fin;
        try {
            const createResp = await fetch(`/admin/assistant-ia/api/conversations/${this.currentIdEntreprise}`, { method: 'POST' });
            const conv = await createResp.json().catch(() => ({}));
            if (!createResp.ok) throw new Error(conv.message || 'Création de la conversation impossible.');

            const attachResp = await fetch(`/admin/assistant-ia/api/contextes/${this.currentIdEntreprise}/${conv.id}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ objets }),
            });
            const attach = await attachResp.json().catch(() => ({}));
            if (!attachResp.ok) throw new Error(attach.message || 'Ajout au contexte impossible.');

            const chatResp = await fetch(conv.chatUrl);
            if (!chatResp.ok) throw new Error('Chargement du chat impossible.');
            document.dispatchEvent(new CustomEvent('app:workspace.open-html-in-visualization', {
                detail: {
                    html:      await chatResp.text(),
                    title:     conv.titre || 'Assistant IA',
                    iconAlias: 'assistant-ia',
                    tabKey:    `ia-conv-${conv.id}`,
                    sourceUrl: conv.chatUrl,
                },
            }));

            const attaches = attach.contextes || [];
            fin = {
                phase:  'end',
                objets: attaches.map(c => ({ type: c.entityType, id: c.entityId })),
                level:  'success',
                message: attaches.length === 0
                    ? 'Aucun objet ajouté au contexte (hors périmètre ou introuvable).'
                    : (attaches.length === 1
                        ? `« ${attaches[0].label} » attaché au contexte du chat de l'assistant IA.`
                        : `${attaches.length} objets attachés au contexte du chat de l'assistant IA.`),
            };
            if (attaches.length === 0) {
                fin.level = 'warning';
            } else if (attach.ignores > 0) {
                fin.message += ` ${attach.ignores} objet(s) hors périmètre ignoré(s).`;
                fin.level = 'warning';
            }
        } catch (error) {
            console.error('[Cerveau] handleAssistantAddToChat :', error);
            fin = {
                phase:   'end',
                message: error.message || "L'ajout au chat de l'assistant a échoué.",
                level:   'error',
                objets:  this.assistantContexteActif,
            };
        } finally {
            this._handleAssistantContexteOperation(fin);
        }
    }

    /**
     * Cycle de feedback des opérations sur le contexte du chat IA (attache,
     * retrait individuel, vidage) : start → barre de progression du haut de
     * page ; end → arrêt de la barre + toast + mémorisation de l'état + synchro
     * des badges « déjà en contexte » des listes ; announce → synchro
     * silencieuse (connexion / re-rendu d'un chat, aucun toast).
     * @param {object} payload
     * @param {'start'|'end'|'announce'} payload.phase
     * @param {string} [payload.message] - Toast (phase end).
     * @param {'success'|'error'|'info'|'warning'} [payload.level]
     * @param {Array<{type: string, id: number}>} [payload.objets] - État complet du contexte.
     */
    _handleAssistantContexteOperation(payload) {
        switch (payload.phase) {
            case 'start':
                this.broadcast('app:loading.start');
                break;
            case 'end':
                this.broadcast('app:loading.stop');
                if (payload.message) {
                    this._showNotification(payload.message, payload.level || 'info');
                }
                this._publishAssistantContexte(payload.objets);
                break;
            case 'announce':
                this._publishAssistantContexte(payload.objets);
                break;
        }
    }

    /** Mémorise l'état du contexte du chat actif et le diffuse aux listes (badges). */
    _publishAssistantContexte(objets) {
        if (!Array.isArray(objets)) return;
        this.assistantContexteActif = objets;
        this.broadcast('app:assistant.contexte.updated', { objets });
    }

    /**
     * Renvoie l'email d'invitation à l'invité sélectionné, sur demande de l'utilisateur
     * (action spécifique de la rubrique Invité, déclenchée depuis la barre d'outils ou le
     * menu contextuel). L'envoi réel est délégué côté serveur au MailingSubscriber via
     * l'InvitationEvent ; ici on ne fait que déclencher l'appel et restituer le résultat.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/invite/api/resend-invitation/{id}'
     */
    async handleInviteResendRequest(payload) {
        if (!payload.url) {
            console.error("[Cerveau] handleInviteResendRequest() : URL manquante.", payload);
            this._showNotification("Impossible de renvoyer l'invitation : URL manquante.", 'error');
            return;
        }
        try {
            // Active la barre de progression globale en haut de la page le temps de l'envoi.
            this._publishSelectionStatus("Renvoi de l'invitation...");
            this.broadcast('app:loading.start');

            const response = await fetch(payload.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || result.success === false) {
                throw new Error(result.message || "Erreur lors du renvoi de l'invitation.");
            }

            this._showNotification('Invitation renvoyée avec succès.', 'success');
            this._publishSelectionStatus('Invitation renvoyée.');
        } catch (error) {
            console.error("[Cerveau] Erreur lors du renvoi de l'invitation :", error);
            this._showNotification(error.message || "Erreur lors du renvoi de l'invitation.", 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Gère la demande d'analyse d'un bordereau.
     * Charge le contenu dans un onglet workspace au lieu d'ouvrir un nouvel onglet navigateur.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/bordereau/api/get-analysis-url/{id}' (l'ID est extrait)
     */
    async handleBordereauAnalysisRequest(payload) {
        if (!payload.url) {
            console.error("[Cerveau] Demande d'action sur le bordereau reçue sans URL.", payload);
            this._showNotification("Impossible de réaliser l'action : URL manquante.", "error");
            return;
        }

        try {
            this._publishSelectionStatus("Chargement de l'analyse...");
            this.broadcast('app:loading.start');
            const bordereauId = payload.url.split('/').at(-1);
            const response = await fetch(`/admin/bordereau/workspace-apercu/${bordereauId}`);
            const result = await response.json();
            if (!response.ok) throw result;

            this.broadcast('app:workspace.inject-html', {
                html:      result.html,
                title:     result.title,
                iconAlias: 'bordereau',
                tabKey:    `bordereau-analyse-${bordereauId}`,
                loadUrl:   `/admin/bordereau/workspace-apercu/${bordereauId}`,
            });
            this._publishSelectionStatus('Analyse chargée.');
        } catch (error) {
            console.error("[Cerveau] Erreur lors du chargement de l'analyse bordereau :", error);
            this._showNotification(error.message || "Erreur lors du chargement de l'analyse.", "error");
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Ouvre le dialogue d'édition de la Note liée à un bordereau.
     * Récupère le contexte (entité + canvas) depuis le backend puis délègue à openDialogBox.
     * @param {object} payload
     * @param {string} payload.url - URL retournant { note, formCanvas }
     */
    async handleBordereauEditLinkedNote(payload) {
        if (!payload.url) {
            console.error("[Cerveau] handleBordereauEditLinkedNote() : URL manquante.", payload);
            this._showNotification("Impossible d'ouvrir la note : URL manquante.", 'error');
            return;
        }
        try {
            this.broadcast('app:loading.start');
            const url = new URL(payload.url, window.location.origin);
            if (this.currentIdEntreprise) {
                url.searchParams.set('idEntreprise', this.currentIdEntreprise);
            }
            const response = await fetch(url.toString());
            if (!response.ok) throw new Error(`Erreur serveur ${response.status}`);
            const { note, formCanvas } = await response.json();
            this.openDialogBox({
                entity:          note,
                entityFormCanvas: formCanvas,
                isCreationMode:  false,
                context: {
                    idEntreprise: this.currentIdEntreprise,
                    idInvite:     this.currentIdInvite,
                },
            });
        } catch (error) {
            console.error("[Cerveau] handleBordereauEditLinkedNote() failed:", error);
            this._showNotification(error.message || "Impossible d'ouvrir la note.", 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Ouvre le dialogue du Portefeuille lié à un invité (actions « Ajouter/Éditer le
     * portefeuille » de la rubrique Invités), sur le modèle de handleBordereauEditLinkedNote.
     * Le backend répond selon l'état réel : { mode: 'edit'|'create', inviteId, portefeuille, formCanvas }.
     * En création, le parentContext 'gestionnaire' est transmis au get-form du Portefeuille
     * (mécanique générique de dialog-instance) pour préremplir l'invité gestionnaire.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/invite/api/get-portefeuille-context/{id}'
     */
    async handleInvitePortefeuilleFormRequest(payload) {
        if (!payload.url) {
            console.error("[Cerveau] handleInvitePortefeuilleFormRequest() : URL manquante.", payload);
            this._showNotification("Impossible d'ouvrir le portefeuille : URL manquante.", 'error');
            return;
        }
        try {
            this.broadcast('app:loading.start');
            const url = new URL(payload.url, window.location.origin);
            if (this.currentIdEntreprise) {
                url.searchParams.set('idEntreprise', this.currentIdEntreprise);
            }
            const response = await fetch(url.toString());
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Erreur serveur ${response.status}`);
            const { mode, inviteId, portefeuille, formCanvas } = result;
            const isCreation = mode === 'create';
            this.openDialogBox({
                entity:           isCreation ? {} : portefeuille,
                entityFormCanvas: formCanvas,
                isCreationMode:   isCreation,
                context: {
                    idEntreprise: this.currentIdEntreprise,
                    idInvite:     this.currentIdInvite,
                },
                // Format attendu par dialog-instance ({ id, fieldName }) : il le traduit
                // en query params parent_id/parent_field_name du get-form (préremplissage)
                // et le réinjecte à la soumission (le gestionnaire reste l'invité ciblé).
                parentContext: isCreation
                    ? { fieldName: 'gestionnaire', id: inviteId }
                    : null,
            });
        } catch (error) {
            console.error("[Cerveau] handleInvitePortefeuilleFormRequest() failed:", error);
            this._showNotification(error.message || "Impossible d'ouvrir le portefeuille.", 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Demande de suppression du portefeuille d'un invité (action « Supprimer le
     * portefeuille »). On NE réécrit PAS la suppression : on formate un payload de
     * confirmation et on réutilise le flux générique app:api.delete-request
     * (cf. case 'app:delete-request'), qui fera DELETE {url}/{inviteId} puis
     * rafraîchira la liste — hasPortefeuille est alors recalculé.
     * @param {object} payload
     * @param {string} payload.url - '/admin/invite/api/delete-portefeuille' (sans id)
     * @param {Array}  [payload.selection] - fourni par la toolbar / le menu contextuel
     * @param {number} [payload.id] - fourni par le volet du dialogue d'édition
     */
    handleInviteDeletePortefeuille(payload) {
        const inviteId = payload.selection?.[0]?.id ?? payload.id;
        if (!payload.url || !inviteId) {
            console.error("[Cerveau] handleInviteDeletePortefeuille() : URL ou id manquant.", payload);
            this._showNotification("Impossible de supprimer le portefeuille : contexte manquant.", 'error');
            return;
        }
        const inviteName = payload.selection?.[0]?.name || `Invité #${inviteId}`;
        this._requestDeleteConfirmation({
            onConfirm: {
                type: 'app:api.delete-request',
                payload: {
                    ids: [inviteId],
                    url: payload.url,
                    originatorId: this.getActiveTabId(),
                    isFromCollectionWidget: false,
                },
            },
            title: 'Confirmation de suppression',
            body: "Vous êtes sur le point de supprimer le portefeuille de cet invité. Ses clients seront détachés du portefeuille, pas supprimés.",
            itemDescriptions: [inviteName],
        });
    }

    /**
     * Ouvre le dialogue de la Piste dérivée liée à un avenant (actions « Ajouter/
     * Éditer la piste dérivée » de la rubrique Avenant), miroir de
     * handleInvitePortefeuilleFormRequest. Le backend répond selon l'état réel :
     * { mode:'edit'|'create', avenantId, piste, formCanvas }.
     * En création, on réutilise le préremplissage riche de PisteController : on cible
     * son get-form avec ?idAvenant=… (contexte client/risque/partenaires) et on
     * réinjecte idAvenant au submit via le parentContext (liaison + reconduction du
     * partage, déjà en place côté serveur).
     * @param {object} payload
     * @param {string} payload.url - '/admin/avenant/api/get-piste-derivee-context/{id}'
     */
    async handleAvenantPisteDeriveeFormRequest(payload) {
        if (!payload.url) {
            console.error("[Cerveau] handleAvenantPisteDeriveeFormRequest() : URL manquante.", payload);
            this._showNotification("Impossible d'ouvrir la piste dérivée : URL manquante.", 'error');
            return;
        }
        try {
            this.broadcast('app:loading.start');
            const url = new URL(payload.url, window.location.origin);
            if (this.currentIdEntreprise) {
                url.searchParams.set('idEntreprise', this.currentIdEntreprise);
            }
            const response = await fetch(url.toString());
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Erreur serveur ${response.status}`);
            const { mode, avenantId, piste, formCanvas } = result;
            const isCreation = mode === 'create';

            // idAvenant est transmis via le CONTEXTE du dialogue (et NON baké dans
            // endpoint_form_url : sinon le rechargement en édition après création
            // produirait « get-form?idAvenant=X/{id} », id après la query → route
            // rechargée en mode création, collections invisibles). Le cerveau ajoute
            // context.idAvenant en query au get-form (préremplissage) et dialog-instance
            // fusionne tout le contexte dans le POST du submit (liaison + reconduction).
            this.openDialogBox({
                entity:           isCreation ? {} : piste,
                entityFormCanvas: formCanvas,
                isCreationMode:   isCreation,
                context: {
                    idEntreprise: this.currentIdEntreprise,
                    idInvite:     this.currentIdInvite,
                    ...(isCreation ? { idAvenant: avenantId } : {}),
                },
                parentContext: null,
            });
        } catch (error) {
            console.error("[Cerveau] handleAvenantPisteDeriveeFormRequest() failed:", error);
            this._showNotification(error.message || "Impossible d'ouvrir la piste dérivée.", 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Action « Signaler un paiement de prime » d'une tranche (miroir simplifié de
     * handleAvenantPisteDeriveeFormRequest) : récupère le canevas PaiementPrime auprès
     * du backend (gating fail-closed + scoping), puis ouvre le dialogue de CRÉATION
     * rattaché à la tranche via parentContext {id, fieldName: 'tranche'} — le get-form
     * préremplit le montant avec le solde de prime restant, le submit associe l'enfant.
     * @param {object} payload - { url } avec %id% déjà résolu par la surface appelante.
     */
    async handleTrancheSignalerPaiementPrime(payload) {
        if (!payload.url) {
            console.error("[Cerveau] handleTrancheSignalerPaiementPrime() : URL manquante.", payload);
            this._showNotification("Impossible d'ouvrir le signalement : URL manquante.", 'error');
            return;
        }
        try {
            this.broadcast('app:loading.start');
            const url = new URL(payload.url, window.location.origin);
            if (this.currentIdEntreprise) {
                url.searchParams.set('idEntreprise', this.currentIdEntreprise);
            }
            const response = await fetch(url.toString());
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Erreur serveur ${response.status}`);
            const { trancheId, formCanvas } = result;

            this.openDialogBox({
                entity:           {},
                entityFormCanvas: formCanvas,
                isCreationMode:   true,
                context: {
                    idEntreprise: this.currentIdEntreprise,
                    idInvite:     this.currentIdInvite,
                },
                parentContext: { id: trancheId, fieldName: 'tranche' },
            });
        } catch (error) {
            console.error("[Cerveau] handleTrancheSignalerPaiementPrime() failed:", error);
            this._showNotification(error.message || "Impossible d'ouvrir le signalement de paiement de prime.", 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Demande de suppression de la piste dérivée d'un avenant (action « Supprimer la
     * piste dérivée »), miroir de handleInviteDeletePortefeuille. On réutilise le flux
     * générique app:api.delete-request qui fera DELETE {url}/{avenantId} après
     * confirmation (le backend supprime la piste et conserve l'avenant de base).
     * @param {object} payload
     * @param {string} payload.url - '/admin/avenant/api/delete-piste-derivee' (sans id)
     * @param {Array}  [payload.selection] - fourni par la toolbar / le menu contextuel
     * @param {number} [payload.id] - fourni par le volet du dialogue d'édition
     */
    handleAvenantDeletePisteDerivee(payload) {
        const avenantId = payload.selection?.[0]?.id ?? payload.id;
        if (!payload.url || !avenantId) {
            console.error("[Cerveau] handleAvenantDeletePisteDerivee() : URL ou id manquant.", payload);
            this._showNotification("Impossible de supprimer la piste dérivée : contexte manquant.", 'error');
            return;
        }
        const avenantName = payload.selection?.[0]?.name || `Avenant #${avenantId}`;
        this._requestDeleteConfirmation({
            onConfirm: {
                type: 'app:api.delete-request',
                payload: {
                    ids: [avenantId],
                    url: payload.url,
                    originatorId: this.getActiveTabId(),
                    isFromCollectionWidget: false,
                },
            },
            title: 'Confirmation de suppression',
            body: "Vous êtes sur le point de supprimer la piste dérivée de cet avenant. L'avenant de base est conservé ; la piste et ses cotations/tranches sont supprimées.",
            itemDescriptions: [avenantName],
        });
    }

    /**
     * Ouvre le picker de PORTEFEUILLE cible pour un client (actions « Affecter à un
     * portefeuille » / « Transférer vers un autre portefeuille » de la rubrique Clients).
     * Le contrôleur Stimulus « portefeuille-picker » s'auto-connecte à l'insertion et
     * porte tout le comportement (cf. _openStandalonePicker).
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/client/api/{id}/portefeuille-picker'
     */
    async handleClientPortefeuillePickerRequest(payload) {
        await this._openStandalonePicker(payload.url, {
            controllerName: 'portefeuille-picker',
            errorLabel: "la sélection de portefeuille",
        });
    }

    /**
     * Ouvre le picker de CLIENTS à rattacher au portefeuille sélectionné (action
     * spéciale « Ajouter des clients au portefeuille » de la rubrique Portefeuilles),
     * SANS passer par le dialogue d'édition. Le HTML est demandé en mode standalone
     * (?standalone=1) : le picker embarque alors le contrôleur Stimulus « client-picker »
     * qui porte l'ajout/retrait, notifie le toast à chaque succès et reste ouvert.
     * @param {object} payload
     * @param {string} payload.url - URL de type '/admin/portefeuille/api/{id}/client-picker'
     */
    async handlePortefeuilleClientPickerRequest(payload) {
        const url = payload.url
            ? payload.url + (payload.url.includes('?') ? '&' : '?') + 'standalone=1'
            : null;
        await this._openStandalonePicker(url, {
            controllerName: 'client-picker',
            errorLabel: "la sélection de clients",
        });
    }

    /**
     * Mécanique COMMUNE d'ouverture d'un picker autonome : récupère le HTML du picker
     * et l'insère dans le DOM ; le contrôleur Stimulus dédié (`controllerName`)
     * s'auto-connecte et porte tout le comportement (focus, fermeture, filtre, actions).
     * Gotcha : quand un dialogue Bootstrap est ouvert (action lancée depuis le volet du
     * formulaire d'édition), il piège le focus dans son sous-arbre → on insère le picker
     * DANS la modale ouverte, sinon dans <body> (même parade que collection_controller.openPicker).
     * @param {?string} url - URL du HTML du picker (déjà résolue, %id% remplacé)
     * @param {{controllerName: string, errorLabel: string}} options
     * @private
     */
    async _openStandalonePicker(url, { controllerName, errorLabel }) {
        if (!url) {
            console.error(`[Cerveau] _openStandalonePicker(${controllerName}) : URL manquante.`);
            this._showNotification(`Impossible d'ouvrir ${errorLabel} : URL manquante.`, 'error');
            return;
        }
        // Garde anti double-ouverture (double clic / menu contextuel + toolbar).
        if (document.querySelector(`[data-controller~="${controllerName}"]`)) return;
        try {
            this.broadcast('app:loading.start');
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) throw new Error(`Erreur serveur ${response.status}`);
            const html = await response.text();

            const holder = document.createElement('div');
            holder.innerHTML = html.trim();
            const picker = holder.firstElementChild;
            if (!picker) throw new Error('Contenu du picker vide.');

            // Hôte = la modale ouverte la plus HAUTE (dernière .modal.show réellement
            // visible), sinon <body>. On filtre sur la visibilité : un `.show` résiduel
            // sur une modale masquée détournerait le picker vers un conteneur invisible.
            const host = Array.from(document.querySelectorAll('.modal.show'))
                .filter(m => getComputedStyle(m).display !== 'none')
                .pop() || document.body;
            host.appendChild(picker); // → connect() du contrôleur Stimulus dédié
        } catch (error) {
            console.error(`[Cerveau] _openStandalonePicker(${controllerName}) failed:`, error);
            this._showNotification(error.message || `Impossible d'ouvrir ${errorLabel}.`, 'error');
        } finally {
            this.broadcast('app:loading.stop');
        }
    }

    /**
     * Demande de retrait d'un client de son portefeuille (action « Retirer du
     * portefeuille »). Confirmation via la modale générique — broadcast DIRECT de
     * ui:confirmation.request (PAS _requestDeleteConfirmation, dont le garde exige des
     * ids de suppression) ; l'action étant un détachement réversible, l'alerte
     * « irréversible » est masquée. À la confirmation, la modale renvoie
     * client:portefeuille.detach-request au cerveau (cf. _handleClientPortefeuilleDetach).
     * @param {object} payload
     * @param {string} payload.url - '/admin/client/api/retirer-portefeuille' (sans id)
     * @param {Array}  [payload.selection] - fourni par la toolbar / le menu contextuel
     * @param {number} [payload.id] - fourni par le volet du dialogue d'édition
     */
    handleClientRetirerPortefeuille(payload) {
        const clientId = payload.selection?.[0]?.id ?? payload.id;
        if (!payload.url || !clientId) {
            console.error("[Cerveau] handleClientRetirerPortefeuille() : URL ou id manquant.", payload);
            this._showNotification("Impossible de retirer le client : contexte manquant.", 'error');
            return;
        }
        const clientName = payload.selection?.[0]?.name || `Client #${clientId}`;
        this.broadcast('ui:confirmation.request', {
            title: 'Retirer du portefeuille',
            body: "Ce client sera retiré de son portefeuille actuel. Il n'est pas supprimé et pourra être réaffecté à tout moment.",
            itemDescriptions: [clientName],
            showIrreversible: false,
            onConfirm: {
                type: 'client:portefeuille.detach-request',
                payload: { url: payload.url, clientId },
            },
        });
    }

    /**
     * Exécute le retrait après confirmation : DELETE {url}/{clientId}, puis notification
     * (message serveur), fermeture de la confirmation et rafraîchissement de la liste
     * active (hasPortefeuille et la ligne secondaire se recalculent au refresh).
     * @private
     */
    async _handleClientPortefeuilleDetach(payload) {
        const { url, clientId } = payload;
        if (!url || !clientId) {
            this.broadcast('ui:confirmation.error', { error: 'Contexte de retrait manquant.' });
            return;
        }
        try {
            const response = await fetch(`${url}/${clientId}`, {
                method: 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            this._showNotification(data.message || 'Client retiré du portefeuille.', 'success');
            this._setSelectionState([]); // la sélection ne reflète plus l'état, on la vide
            // Barre de progression du workspace + squelette de la liste pendant le
            // rafraîchissement (arrêtés par app:list.rendered), comme la pagination.
            const detachState = this._getActiveTabState();
            this._publishSelectionStatus('Actualisation de la liste...');
            this.broadcast('app:loading.start', { originatorId: detachState.elementId, workspaceTabId: this.currentWorkspaceTabId });
            this._requestListRefresh(this.getActiveTabId());
            this.broadcast('ui:confirmation.close');
        } catch (error) {
            console.error("[Cerveau] _handleClientPortefeuilleDetach() failed:", error);
            this.broadcast('ui:confirmation.error', { error: error.message || 'Le retrait a échoué.' });
        }
    }

    /**
     * NOUVEAU : Gère la soumission des données mappées pour l'analyse du bordereau au backend.
     * @param {object} payload
     * @param {string} payload.url - L'URL de l'API pour soumettre l'analyse.
     * @param {object} payload.data - Les données à envoyer (sheetName, mappedColumns, sheetsData).
     */
    _handleSubmitBordereauAnalysis(payload) {
        if (!payload.url || !payload.data) {
            console.error("[Cerveau] Demande de soumission d'analyse de bordereau reçue sans URL ou données.", payload);
            this._showNotification("Impossible de soumettre l'analyse : URL ou données manquantes.", "error");
            return;
        }

        console.log("[Cerveau] _handleSubmitBordereauAnalysis() - Soumission de l'analyse à l'API:", payload.url, payload.data);
        this._publishSelectionStatus("Soumission de l'analyse...");
        this.broadcast('app:loading.start'); // Active la barre de progression globale

        fetch(payload.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload.data)
            })
            .then(response => {
                console.log("[Cerveau] _handleSubmitBordereauAnalysis() - Réponse de l'API reçue. Statut:", response.status);
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.error || "Erreur lors de la soumission de l'analyse.") });
                }
                return response.json();
            })
            .then(result => {
                if (!result.analysisResults || result.analysisResults.length === 0) {
                    console.warn("[Cerveau] _handleSubmitBordereauAnalysis() - L'API a retourné une réponse vide pour 'analysisResults' malgré un statut 200 OK.");
                }
                console.log("[Cerveau] _handleSubmitBordereauAnalysis() - Succès. Diffusion de 'bordereau:analysis-completed'.");
                this.broadcast('bordereau:analysis-completed', { analysisResults: result.analysisResults });
            })
            .catch(error => {
                console.error("[Cerveau] _handleSubmitBordereauAnalysis() - Erreur lors de la soumission de l'analyse:", error);
                this._showNotification(error.message || "Erreur lors de la soumission de l'analyse.", "error");
                this.broadcast('bordereau:analysis-failed', { errorMessage: error.message || "Une erreur inconnue est survenue." });
            })
            .finally(() => {
                console.log("[Cerveau] _handleSubmitBordereauAnalysis() - Fin de l'opération. Désactivation de la barre de progression.");
                this.broadcast('app:loading.stop');
            });
    }

    /**
     * Gère la sauvegarde de l'état de l'analyse du bordereau au backend.
     * @param {object} payload
     * @param {string} payload.url - L'URL de l'API pour sauvegarder l'état.
     * @param {object} payload.data - Les données à envoyer (selectedSheetName, mappedColumns, currentAnalysisStep).
     */
    _handleSaveBordereauAnalysisState(payload) {
        if (!payload.url || !payload.data) {
            console.error("[Cerveau] Demande de sauvegarde de l'état de l'analyse de bordereau reçue sans URL ou données.", payload);
            this._showNotification("Impossible de sauvegarder l'état de l'analyse : URL ou données manquantes.", "error");
            return;
        }

        console.log("[Cerveau] _handleSaveBordereauAnalysisState() - Sauvegarde de l'état à l'API:", payload.url, payload.data);
        fetch(payload.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload.data)
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => { throw new Error(err.message || "Erreur lors de la sauvegarde de l'état.") });
                }
                return response.json();
            })
            .then(result => {
                console.log("[Cerveau] _handleSaveBordereauAnalysisState() - État sauvegardé avec succès:", result.message);
                this.broadcast('bordereau:save-state-completed', { message: result.message }); // NOUVEAU: Événement spécifique pour la complétion de la sauvegarde
            })
            .catch(error => {
                console.error("[Cerveau] _handleSaveBordereauAnalysisState() - Erreur lors de la sauvegarde de l'état:", error);
                this.broadcast('bordereau:save-state-failed', { errorMessage: error.message || "Une erreur inconnue est survenue lors de la sauvegarde de l'état." }); // NOUVEAU: Événement spécifique pour l'échec de la sauvegarde
            })
            .finally(() => {
                console.log("[Cerveau] _handleSaveBordereauAnalysisState() - Fin de l'opération. Désactivation de la barre de progression.");
                this.broadcast('app:loading.stop');
            });
    }
}