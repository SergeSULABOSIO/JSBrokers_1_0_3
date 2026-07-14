import BaseController from './base_controller.js';

/**
 * @class ListManagerController
 * @extends Controller
 * @description Gère une liste de données, y compris la sélection, la récupération des données
 * et la communication de l'état de la liste au reste de l'application via le Cerveau.
 */
export default class extends BaseController {

    /**
     * @property {HTMLElement[]} donneesTargets - Le conteneur (<tbody>) où les lignes de données sont affichées.
     * @property {HTMLInputElement[]} selectAllCheckboxTargets - La case à cocher dans l'en-tête pour tout sélectionner.
     * @property {HTMLInputElement[]} rowCheckboxTargets - L'ensemble des cases à cocher de chaque ligne.
     */
    static targets = [
        'donnees',
        'selectAllCheckbox',
        'listContainer',
        'emptyStateContainer',
        'rowCheckbox',
        'paginationContainer',
        'controlsBar',
    ];

    /**
     * @property {ObjectValue} entityFormCanvasValue - La configuration (canvas) du formulaire d'édition/création.
     * @property {StringValue} entiteValue - Le nom de l'entité gérée par la liste (ex: 'Sinistre').
     * @property {StringValue} serverRootNameValue - Le nom racine du contrôleur PHP pour les appels API.
     */
    static values = {
        nbElements: Number,
        entite: String,
        serverRootName: String,
        idEntreprise: Number,
        idInvite: Number,
        entityFormCanvas: Object,
        listUrl: String,
        numericAttributesAndValues: String,
        pagination: Object,
        // Critères actifs par défaut au premier chargement (JSON). Amorce l'état de
        // recherche du Cerveau pour cet onglet (badge + persistance en pagination).
        initialCriteria: Object,
        // Canvas de recherche de l'entité listée : mémorisé dans l'état d'onglet du
        // Cerveau pour recontextualiser la barre de recherche à chaque activation.
        searchCanvas: Array,
    };

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "LIST-MANAGER";
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        this.boundHandleListRefreshed = this.handleListRefreshed.bind(this);
        this.boundToggleAll = this.toggleAll.bind(this);
        this.boundHandleContextMenuRequest = this.handleContextMenuRequest.bind(this);
        // REFACTORING : Écoute l'événement de chargement unifié 'app:loading.start'.
        this.boundHandleLoadingStart = this.handleLoadingStart.bind(this);
        document.addEventListener('app:loading.start', this.boundHandleLoadingStart);

        this.boundHandlePaginationClick = this._handlePaginationClick.bind(this);
        this.element.addEventListener('click', this.boundHandlePaginationClick);
        this.boundHandlePaginationJump = this._handlePaginationJump.bind(this);
        this.element.addEventListener('change', this.boundHandlePaginationJump);

        // Defer so child controllers (list-summary) connect and register listeners before the first broadcast.
        requestAnimationFrame(() => this._initializeAndNotifyState());
        this._lastPagination = this.paginationValue || {}; // méta courante pour les compteurs
        this._renderPagination(this.paginationValue);

        document.addEventListener('app:context.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:list.refreshed', this.boundHandleListRefreshed);
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll);
        this.element.addEventListener('list-manager:context-menu-requested', this.boundHandleContextMenuRequest);

        // Badges « déjà dans le contexte du chat IA » : synchronisés sur l'état
        // diffusé par le cerveau (pas de filtre par onglet : le chat actif est
        // global au workspace).
        this.boundHandleAssistantContexteUpdated = this.handleAssistantContexteUpdated.bind(this);
        document.addEventListener('app:assistant.contexte.updated', this.boundHandleAssistantContexteUpdated);

        // CORRECTION : On se base sur la présence réelle de lignes dans le DOM,
        // et non plus sur la valeur 'nbelements'. C'est plus robuste et cohérent
        // avec la logique de handleListRefreshed.
        const hasRows = this.donneesTarget.querySelector('tr') !== null;
        this.listContainerTarget.classList.toggle('d-none', !hasRows);
        this.emptyStateContainerTarget.classList.toggle('d-none', hasRows);
        if (!hasRows) {
            this._logDebug("Liste initialisée vide. Affichage de l'état vide.");
        }
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:list.refreshed', this.boundHandleListRefreshed);
        document.removeEventListener('app:list.toggle-all-request', this.boundToggleAll);
        this.element.removeEventListener('list-manager:context-menu-requested', this.boundHandleContextMenuRequest);
        document.removeEventListener('app:assistant.contexte.updated', this.boundHandleAssistantContexteUpdated);
        document.removeEventListener('app:loading.start', this.boundHandleLoadingStart);
        this.element.removeEventListener('click', this.boundHandlePaginationClick);
        this.element.removeEventListener('change', this.boundHandlePaginationJump);
    }

    /**
     * NOUVEAU : Construit l'état initial de la liste et le notifie au cerveau.
     * Cette méthode est appelée à la connexion du contrôleur et agit comme le
     * point de départ pour l'enregistrement et la publication de l'état d'un onglet.
     * @private
     */
    _initializeAndNotifyState() {
        // 1. Décode les données numériques initiales.
        let initialNumericData = {};
        try {
            const decodedInitialData = this._decodeHtmlEntities(this.numericAttributesAndValuesValue);
            initialNumericData = JSON.parse(decodedInitialData || '{}');
        } catch (e) {
            console.error(`${this.nomControleur} - Erreur de parsing des données numériques initiales.`, { raw: this.numericAttributesAndValuesValue, error: e });
            initialNumericData = {};
        }

        // CORRECTION : Si la liste est initialisée sans aucun élément, on force les données numériques à être vides.
        // Cela corrige le cas où le serveur envoie une "structure" de données numériques même pour une liste vide,
        // ce qui faussait l'affichage initial de la barre des totaux.
        if (this.nbElementsValue === 0) {
            initialNumericData = {};
        }

        // 2. Construit l'objet d'état complet.
        // Amorce les critères de recherche avec le filtre par défaut fourni par le serveur
        // (ex. périmètre « Mon portefeuille » de la rubrique Clients). Ainsi la barre de
        // recherche affiche le badge correspondant et la pagination conserve le filtre.
        const initialCriteria = (this.initialCriteriaValue && !Array.isArray(this.initialCriteriaValue))
            ? this.initialCriteriaValue
            : {};
        // Compteurs initiaux : éléments affichés sur la première page (rendus côté serveur)
        // et total de la recherche (méta de pagination), pour la barre de statut.
        const initialTotal = (this.paginationValue && this.paginationValue.totalItems != null)
            ? this.paginationValue.totalItems
            : this.nbElementsValue;
        const initialState = {
            selectionState: [],
            selectionIds: new Set(),
            numericAttributesAndValues: initialNumericData,
            activeTabFormCanvas: this.entityFormCanvasValue,
            searchCriteria: initialCriteria,
            pageItemCount: this.nbElementsValue,
            totalItems: initialTotal,
            // Contexte de la barre de recherche : critères de l'entité de CET onglet.
            searchCanvas: this.hasSearchCanvasValue ? this.searchCanvasValue : [],
            entiteNom: this.entiteValue,
        };

        // CORRECTION : Pour l'onglet principal, le tabId logique est 'principal'.
        // Pour les autres (collections), le tabId est l'ID de l'élément lui-même.
        const isPrincipalTab = this.element.id.startsWith('list-manager-');
        const tabId = isPrincipalTab ? 'principal' : this.element.id;

        // 3. Notifie le cerveau avec l'état initial.
        // Chercher le panel workspace parent pour transmettre le workspaceTabId explicitement.
        // Sans cela, Cerveau utilise currentWorkspaceTabId qui peut pointer vers un autre onglet
        // si l'utilisateur a cliqué rapidement sur plusieurs onglets workspace.
        const workspacePanel = this.element.closest('[data-tab-id]');
        const workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.notifyCerveau('ui:tab.initialized', {
            tabId: tabId,
            elementId: this.element.id,
            serverRootName: this.serverRootNameValue,
            state: initialState,
            workspaceTabId: workspaceTabId
        });
    }

    // --- GESTION DE LA SÉLECTION ---

    /**
     * NOUVEAU : Gère le clic sur une case à cocher d'une ligne enfant.
     * Met à jour l'état de sélection interne et notifie le cerveau avec l'état complet.
     * @param {Event} event
     */
    handleRowSelection(event) {
        // On met à jour l'état visuel de la case "Tout cocher"
        this._notifySelectionChange();
    }

    /**
     * NOUVEAU : Gère la demande de menu contextuel venant d'une ligne.
     * C'est le point de départ de la séquence garantie.
     * @param {CustomEvent} event
     */
    handleContextMenuRequest(event) {
        const { selecto, menuX, menuY } = event.detail;

        // 1. On s'assure que la ligne cliquée est bien sélectionnée.
        const checkbox = this.element.querySelector(`#check_${selecto.id}`);
        if (checkbox && !checkbox.checked) {
            checkbox.checked = true;
            // On met à jour l'état visuel de la ligne.
            checkbox.closest('tr')?.classList.add('row-selected');
        }

        // 2. On notifie le cerveau avec la sélection complète ET la position de la souris.
        this._notifySelectionChange({ contextMenuPosition: { menuX, menuY } });
    }

    /**
     * Gère le clic sur la case "Tout cocher" ou une demande externe du Cerveau.
     * Coche ou décoche toutes les cases de la liste et notifie le Cerveau avec l'état final.
     */
    toggleAll(event) {
        if (event && event.detail && this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const isTriggeredByUser = event && this.hasSelectAllCheckboxTarget && event.currentTarget === this.selectAllCheckboxTarget;
        const totalRows = this.rowCheckboxTargets.length;
        const checkedRows = this.rowCheckboxTargets.filter(c => c.checked).length;

        const shouldCheck = isTriggeredByUser ? this.selectAllCheckboxTarget.checked : checkedRows < totalRows;

        this.rowCheckboxTargets.forEach(checkbox => {
            checkbox.checked = shouldCheck;
            checkbox.closest('tr')?.classList.toggle('row-selected', shouldCheck);
        });

        this._notifySelectionChange();
    }

    /**
     * NOUVEAU : Centralise la logique de collecte et de notification de la sélection au cerveau.
     * @param {object} [extraPayload={}] - Données additionnelles à envoyer (ex: contextMenuPosition).
     * @private
     */
    _notifySelectionChange(extraPayload = {}) {
        this.updateSelectAllCheckboxState();
        // On reconstruit l'état complet de la sélection
        const allSelectos = [];
        this.rowCheckboxTargets.forEach(checkbox => {
            if (checkbox.checked) {
                const listRowController = this.application.getControllerForElementAndIdentifier(checkbox.closest('[data-controller="list-row"]'), 'list-row');
                if (listRowController) {
                    const selecto = listRowController.buildSelectoPayload();
                    if (selecto) {
                        allSelectos.push(selecto);
                    }
                }
            }
        });

        // On notifie le cerveau avec la liste complète et les données additionnelles.
        this.notifyCerveau('ui:list.selection-completed', {
            selectos: allSelectos,
            ...extraPayload // Ajoute contextMenuPosition si présent
        });
    }

    /**
     * Gère la mise à jour de la sélection globale venant d'un autre composant (ex: changement d'onglet).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleGlobalSelectionUpdate(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const selectos = event.detail.selection || [];
        const selectionIds = new Set(selectos.map(s => String(s.id)));
        this.rowCheckboxTargets.forEach(checkbox => {
            const checkboxId = String(checkbox.dataset.listRowIdobjetValue);
            checkbox.checked = selectionIds.has(checkboxId);
            checkbox.closest('tr')?.classList.toggle('row-selected', checkbox.checked);
        });

        this.updateSelectAllCheckboxState();
    }

    /**
     * Met à jour l'état visuel de la case "Tout cocher" (cochée, décochée, ou indéterminée).
     * @private
     */
    updateSelectAllCheckboxState() {
        if (!this.hasSelectAllCheckboxTarget) return;

        const total = this.rowCheckboxTargets.length;
        const checkedCount = this.rowCheckboxTargets.filter(c => c.checked).length;

        if (total === 0 || checkedCount === 0) {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else if (checkedCount === total) {
            this.selectAllCheckboxTarget.checked = true;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else {
            // Cas où certains, mais pas tous, sont cochés
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = true;
        }
    }

    // --- GESTION DES DONNÉES ---

    /**
     * MISSION 2 : Affiche un squelette de chargement dans la liste lorsque le cerveau le demande.
     * @param {CustomEvent} event
     */
    handleLoadingStart(event) {
        if (this.workspaceTabId && event.detail?.workspaceTabId !== this.workspaceTabId) return;
        const { originatorId } = event.detail || {};
        if (originatorId === this.element.id) {
            this.donneesTarget.innerHTML = this._getListSkeletonHtml();
            // On s'assure que le conteneur de la liste est visible et que le message d'état vide est caché.
            this.listContainerTarget.classList.remove('d-none');
            this.emptyStateContainerTarget.classList.add('d-none');
        }
    }

    /**
     * MISSION 2 : Génère le HTML pour un squelette de chargement de liste.
     * @returns {string} Le HTML des lignes du squelette.
     * @private
     */
    _getListSkeletonHtml() {
        // On essaie d'être intelligent en comptant les colonnes de l'en-tête pour un rendu plus fidèle.
        const columnCount = this.element.querySelectorAll('thead th').length || 5; // Fallback à 5 colonnes si l'en-tête n'est pas trouvé.
        let skeletonTbody = '';
        // On génère 10 lignes pour un bon effet visuel.
        for (let i = 0; i < 10; i++) {
            skeletonTbody += `
                <tr>
                    ${'<td><div class="skeleton-row"></div></td>'.repeat(columnCount)}
                </tr>
            `;
        }
        return skeletonTbody;
    }

    /**
     * Gère la réception des nouvelles données de la liste envoyées par le cerveau.
     * @param {CustomEvent} event - L'événement `app:list.refreshed`.
     */
    handleListRefreshed(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { html, originatorId, pagination } = event.detail;

        if (originatorId && originatorId !== this.element.id) {
            this._logDebug("Demande de rafraîchissement ignorée (non destinée à cette liste).", { myId: this.element.id, originatorId: event.detail.originatorId });
            return;
        }

        this.donneesTarget.innerHTML = html;

        const hasRows = this.donneesTarget.querySelector('tr') !== null;

        this.listContainerTarget.classList.toggle('d-none', !hasRows);
        this.emptyStateContainerTarget.classList.toggle('d-none', hasRows);

        if (pagination) {
            this._renderPagination(pagination);
            this._lastPagination = pagination; // mémorisé pour les compteurs de la barre de statut
        }

        this._postDataLoadActions();
    }

    /**
     * Exécute les actions post-chargement des données.
     * @private
     */
    _postDataLoadActions() {
        // Notifie le cerveau que le rendu est terminé et fournit le nombre d'éléments.
        // On compte les lignes réelles (data-item-id) et non les cases à cocher : en
        // collection embarquée (dialog), la colonne case à cocher n'est pas rendue.
        const itemCount = this.donneesTarget.querySelectorAll('[data-item-id]').length;
        const totalItems = (this._lastPagination && this._lastPagination.totalItems != null)
            ? this._lastPagination.totalItems
            : itemCount;
        this.notifyCerveau('app:list.rendered', { itemCount, totalItems });

        // Les lignes viennent d'être re-rendues (badges masqués côté serveur) :
        // ré-applique l'état « déjà dans le contexte du chat IA » mémorisé.
        this._applyAssistantContexteBadges();
    }

    /**
     * Synchronise les badges « déjà dans le contexte du chat IA » des lignes
     * avec l'état diffusé par le cerveau (attache, retrait, vidage, annonce).
     * @param {CustomEvent} event - app:assistant.contexte.updated {objets: [{type, id}]}
     */
    handleAssistantContexteUpdated(event) {
        this._assistantContexte = event.detail?.objets || [];
        this._applyAssistantContexteBadges();
    }

    /**
     * Applique l'état mémorisé du contexte du chat IA aux lignes rendues :
     * badge visible si (type d'entité + id) figure dans le contexte actif.
     * @private
     */
    _applyAssistantContexteBadges() {
        const keys = new Set((this._assistantContexte || []).map(o => `${o.type}#${o.id}`));
        this.rowCheckboxTargets.forEach(checkbox => {
            const rowElement = checkbox.closest('[data-controller~="list-row"]');
            if (!rowElement) return;
            const rowController = this.application.getControllerForElementAndIdentifier(rowElement, 'list-row');
            rowController?.setContexteBadge(keys.has(`${checkbox.dataset.entityType}#${checkbox.dataset.listRowIdobjetValue}`));
        });
    }

    /**
     * NOUVEAU : Gère la demande d'ajout d'un nouvel élément, typiquement depuis l'état vide.
     * Notifie le cerveau en utilisant le même événement que la barre d'outils.
     */
    requestAddItem() {
        this._logDebug("Demande d'ajout reçue depuis l'état vide.");
        this.notifyCerveau('ui:toolbar.add-request', {
            formCanvas: this.entityFormCanvasValue,
            // On ajoute le contexte du parent si on est dans une collection
            context: {
                originatorId: this.element.id
            }
        });
    }

    /**
     * NOUVEAU : Gère la demande de réinitialisation de la recherche, typiquement depuis l'état vide.
     * Notifie le cerveau.
     */
    resetSearch() {
        this._logDebug("Demande de réinitialisation de la recherche reçue depuis l'état vide.");
        this.notifyCerveau('ui:search.reset-request', {});
    }

    /**
     * Rend la barre de pagination dans le conteneur dédié.
     * N'affiche rien s'il n'y a qu'une seule page ou si les métadonnées sont absentes.
     * @param {object} meta - { currentPage, totalPages, totalItems, itemsPerPage }
     * @private
     */
    _renderPagination(meta) {
        if (!this.hasPaginationContainerTarget) return;
        if (!meta || !meta.totalPages || (meta.totalPages <= 1 && meta.totalItems <= (meta.itemsPerPage || 20))) {
            this.paginationContainerTarget.innerHTML = '';
            this.element.classList.remove('list-manager-has-pagination');
            requestAnimationFrame(() => this._updateControlsBarHeight());
            return;
        }
        const { currentPage, totalPages, totalItems, itemsPerPage } = meta;
        const limit     = itemsPerPage || 20;
        const rangeFrom = (currentPage - 1) * limit + 1;
        const rangeTo   = Math.min(currentPage * limit, totalItems);
        const iconPrev = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>`;
        const iconNext = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>`;
        this.paginationContainerTarget.innerHTML = `
            <div class="d-flex align-items-center justify-content-between px-3 py-2 small gap-3">
                <span class="text-muted text-nowrap">
                    <strong style="color:#0047AB;">${rangeFrom}&nbsp;–&nbsp;${rangeTo}</strong>
                    &nbsp;sur&nbsp;
                    <strong style="color:#0047AB;">${totalItems}</strong>&nbsp;élément(s)
                </span>
                <div class="d-flex align-items-center gap-2 flex-wrap justify-content-end">
                    <label class="d-flex align-items-center gap-1 text-muted mb-0" style="white-space:nowrap;">
                        Page
                        <input type="number"
                               class="form-control form-control-sm text-center"
                               style="width:52px;"
                               min="1" max="${totalPages}" value="${currentPage}"
                               data-pagination-jump-input
                               aria-label="Aller à la page">
                        / <strong>${totalPages}</strong>
                    </label>
                    <nav class="d-flex gap-1" aria-label="Navigation par page">
                        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                data-pagination-page="${currentPage - 1}"
                                ${currentPage <= 1 ? 'disabled' : ''}
                                aria-label="Page précédente">${iconPrev} Préc.</button>
                        <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                data-pagination-page="${currentPage + 1}"
                                ${currentPage >= totalPages ? 'disabled' : ''}
                                aria-label="Page suivante">Suiv. ${iconNext}</button>
                    </nav>
                </div>
            </div>`;
        this.element.classList.add('list-manager-has-pagination');
        requestAnimationFrame(() => this._updateControlsBarHeight());
    }

    _updateControlsBarHeight() {
        if (this.hasControlsBarTarget) {
            const h = this.controlsBarTarget.offsetHeight;
            this.element.style.setProperty('--jsb-pgbar-h', `${h}px`);
            this.element.classList.add('list-manager-has-controls');
        }
    }

    /**
     * Gère les clics sur les boutons de pagination via délégation d'événement.
     * @param {Event} event
     * @private
     */
    _handlePaginationClick(event) {
        const btn = event.target.closest('[data-pagination-page]');
        if (!btn || btn.disabled) return;
        const page = parseInt(btn.dataset.paginationPage, 10);
        if (isNaN(page) || page < 1) return;
        this.notifyCerveau('ui:pagination.page-changed', { page });
    }

    _handlePaginationJump(event) {
        const input = event.target.closest('[data-pagination-jump-input]');
        if (!input) return;
        const page = parseInt(input.value, 10);
        const max  = parseInt(input.max, 10) || 1;
        if (isNaN(page) || page < 1 || page > max) {
            input.value = input.defaultValue;
            return;
        }
        this.notifyCerveau('ui:pagination.page-changed', { page });
    }

    /**
     * NOUVEAU : Décode les entités HTML d'une chaîne de caractères.
     * @param {string} str La chaîne à décoder.
     * @returns {string} La chaîne décodée.
     * @private
     */
    _decodeHtmlEntities(str) {
        if (!str) return str;
        const textarea = document.createElement('textarea');
        textarea.innerHTML = str;
        return textarea.value;
    }

    /**
     * Méthode de log pour le débogage.
     * @param {string} message
     * @param {*} [data]
     * @private
     */
    _logDebug(message, data = null) {
        console.log(`${this.nomControleur} - ${message}`, data);
    }
}