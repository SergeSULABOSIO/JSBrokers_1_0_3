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
        'listContainer', // NOUVEAU
        'emptyStateContainer', // NOUVEAU
        'rowCheckbox',
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
        listUrl: String, // NOUVEAU : URL unique servant de clé pour le stockage
        numericAttributesAndValues: String, // MODIFIÉ : On reçoit maintenant une chaîne JSON
    };

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "LIST-MANAGER";
        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        this.boundHandleListRefreshed = this.handleListRefreshed.bind(this);
        this.boundToggleAll = this.toggleAll.bind(this);
        this.boundHandleContextMenuRequest = this.handleContextMenuRequest.bind(this);
        // REFACTORING : Écoute l'événement de chargement unifié 'app:loading.start'.
        this.boundHandleLoadingStart = this.handleLoadingStart.bind(this);
        document.addEventListener('app:loading.start', this.boundHandleLoadingStart);

        this._initializeAndNotifyState();

        document.addEventListener('app:context.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:list.refreshed', this.boundHandleListRefreshed);
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll);
        this.element.addEventListener('list-manager:context-menu-requested', this.boundHandleContextMenuRequest);

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
        document.removeEventListener('app:loading.start', this.boundHandleLoadingStart);
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
        const initialState = {
            selectionState: [],
            selectionIds: new Set(),
            numericAttributesAndValues: initialNumericData,
            activeTabFormCanvas: this.entityFormCanvasValue
        };

        // CORRECTION : Pour l'onglet principal, le tabId logique est 'principal'.
        // Pour les autres (collections), le tabId est l'ID de l'élément lui-même.
        const isPrincipalTab = this.element.id.startsWith('list-manager-');
        const tabId = isPrincipalTab ? 'principal' : this.element.id;

        // 3. Notifie le cerveau avec l'état initial.
        this.notifyCerveau('ui:tab.initialized', {
            tabId: tabId,
            elementId: this.element.id, // On ajoute l'ID de l'élément pour le cerveau
            serverRootName: this.serverRootNameValue, // On fournit le nom racine pour l'URL
            state: initialState
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
        // CORRECTION : On rend la fonction robuste en fournissant un objet vide par défaut
        // si event.detail est null ou undefined. Cela évite un crash.
        const { originatorId } = event.detail || {};
        
        // On ne réagit que si l'événement nous est destiné.
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
        const { html, originatorId } = event.detail;

        if (originatorId && originatorId !== this.element.id) {
            this._logDebug("Demande de rafraîchissement ignorée (non destinée à cette liste).", { myId: this.element.id, originatorId: event.detail.originatorId });
            return;
        }

        this.donneesTarget.innerHTML = html;

        const hasRows = this.donneesTarget.querySelector('tr') !== null;

        this.listContainerTarget.classList.toggle('d-none', !hasRows);
        this.emptyStateContainerTarget.classList.toggle('d-none', hasRows);

        this._postDataLoadActions();
    }

    /**
     * Exécute les actions post-chargement des données.
     * @private
     */
    _postDataLoadActions() {
        // Notifie le cerveau que le rendu est terminé et fournit le nombre d'éléments.
        this.notifyCerveau('app:list.rendered', {
            itemCount: this.rowCheckboxTargets.length
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