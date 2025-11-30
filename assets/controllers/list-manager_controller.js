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
        // this.urlAPIDynamicQuery = `/admin/${this.controleurphpValue}/api/dynamic-query/${this.identrepriseValue}`;
        this.urlAPIDynamicQuery = `/admin/${this.serverRootNameValue}/api/dynamic-query/${this.idInviteValue}/${this.idEntrepriseValue}`;

        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        this.selectedIds = new Set(); // NOUVEAU : Mémorise les IDs sélectionnés pour cette instance de liste.
        this.boundHandleDBRequest = this.handleDBRequest.bind(this);
        this.boundToggleAll = this.toggleAll.bind(this);
        // NOUVEAU : Écouteur pour la nouvelle logique "B"
        this.boundHandleContextMenuRequest = this.handleContextMenuRequest.bind(this);

        // On notifie le cerveau avec les données numériques initiales.
        // On décode et on parse les données numériques initiales avant de les envoyer au Cerveau.
        let initialNumericData = {};
        try {
            const decodedInitialData = this._decodeHtmlEntities(this.numericAttributesAndValuesValue);
            initialNumericData = JSON.parse(decodedInitialData || '{}');
        } catch (e) {
            console.error(`${this.nomControleur} - Erreur de parsing des données numériques initiales.`, { raw: this.numericAttributesAndValuesValue, error: e });
            // En cas d'erreur, on s'assure que la valeur reste un objet vide.
            initialNumericData = {};
        }
        this.notifyCerveau('app:list.data-loaded', {
            numericAttributesAndValues: initialNumericData
        });

        console.log("LIST-MANAGER - connect - Code:1980 - numericAttributesAndValuesValue (initial from generic component):", this.numericAttributesAndValuesValue);
        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:list.refresh-request', this.boundHandleDBRequest); // CORRECTION : On écoute l'ordre du cerveau, pas la demande directe.
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll); // Écouter l'ordre du Cerveau
        this.element.addEventListener('list-manager:context-menu-requested', this.boundHandleContextMenuRequest);

        if (this.nbElementsValue === 0) {
            this.listContainerTarget.classList.add('d-none');
            this.emptyStateContainerTarget.classList.remove('d-none');
            this._logDebug("Liste initialisée vide par le serveur. Affichage de l'état vide.");
        }

        if (this.element.id !== 'principal') {
            console.log(`${this.nomControleur} - Notification de contexte prêt pour l'onglet: ${this.element.id}`, { formCanvas: this.entityFormCanvasValue });
            this.notifyCerveau('app:list.context-ready', { tabId: this.element.id, formCanvas: this.entityFormCanvasValue });
        }
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:list.refresh-request', this.boundHandleDBRequest);
        document.removeEventListener('app:list.toggle-all-request', this.boundToggleAll);
        this.element.removeEventListener('list-manager:context-menu-requested', this.boundHandleContextMenuRequest);
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
     * Met à jour l'état visuel de la case "Tout cocher" (cochée, décochée, ou indéterminée).
     * @private
     */
    updateSelectAllCheckboxState() {
        if (!this.hasSelectAllCheckboxTarget || this.rowCheckboxTargets.length === 0) return;

        const total = this.rowCheckboxTargets.length;
        const checkedCount = this.rowCheckboxTargets.filter(c => c.checked).length;

        if (checkedCount === 0) {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else if (checkedCount === total) {
            this.selectAllCheckboxTarget.checked = true;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = true;
        }
    }

    /**
     * Gère la mise à jour de la sélection globale venant d'un autre composant (ex: changement d'onglet).
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleGlobalSelectionUpdate(event) {
        const selectos = event.detail.selection || [];
        this.selectedIds = new Set(selectos.map(s => String(s.id))); // NOUVEAU : Mettre à jour notre état de sélection interne.
        const selectionIds = new Set(selectos.map(s => String(s.id)));
        this.rowCheckboxTargets.forEach(checkbox => {
            const checkboxId = String(checkbox.dataset.listRowIdobjetValue);
            checkbox.checked = selectionIds.has(checkboxId);
            checkbox.closest('tr')?.classList.toggle('row-selected', checkbox.checked);
        });

    }

    // --- GESTION DES DONNÉES ---

    /**
     * Gère une demande de recherche de données.
     * @param {CustomEvent} event - L'événement `app:base-données:sélection-request`.
     */
    async handleDBRequest(event) {
        this._showSkeleton();

        console.log(this.nomControleur + " - Code: 1986 - Recherche", event.detail);

        if (event.detail.originatorId && event.detail.originatorId !== this.element.id) {
            this._logDebug("Demande de rafraîchissement ignorée (non destinée à cette liste).", { myId: this.element.id, originatorId: event.detail.originatorId });
            return;
        }

        this._logDebug("Demande de chargement reçue.", event.detail);

        const { idEntreprise, idInvite } = this._getIdsFromEventOrValues(event.detail);
        const criteria = event.detail.criteria || {};
        const entityName = this.entiteValue;

        if (!entityName) return;

        const url = this._buildDynamicQueryUrl(idInvite, idEntreprise);
        this._logDebug("URL de requête:", url);

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/html' },
                body: JSON.stringify({ entityName, criteria, page: 1, limit: 100 }),
            });

            const html = await response.text();
            if (!response.ok) throw new Error(html || 'Erreur serveur');

            const validHtml = `<table><tbody>${html}</tbody></table>`;
            const parser = new DOMParser();
            const doc = parser.parseFromString(validHtml, 'text/html');

            // CORRECTION : On ne sélectionne que les lignes de données réelles (celles avec un data-id)
            const dataRows = Array.from(doc.body.querySelectorAll('tr[data-id]'));
            console.log(`LIST-MANAGER - ${dataRows.length} ligne(s) de données trouvée(s) dans la réponse.`);

            if (dataRows.length > 0) {
                this.listContainerTarget.classList.remove('d-none');
                this.emptyStateContainerTarget.classList.add('d-none');
                // CORRECTION : On injecte uniquement les lignes de données dans le tbody
                this.donneesTarget.innerHTML = dataRows.map(row => row.outerHTML).join('');
                const newContent = dataRows.map(row => row.outerHTML).join('');
                this.donneesTarget.innerHTML = newContent;
                console.log("LIST-MANAGER - Affichage de la liste avec les résultats.");
            } else {
                this.listContainerTarget.classList.add('d-none');
                this.emptyStateContainerTarget.classList.remove('d-none');
                this.donneesTarget.innerHTML = ''; // Vider le tbody
                console.log("LIST-MANAGER - Affichage de l'état vide (aucun résultat).");
            }

            this._postDataLoadActions(doc);

        } catch (error) {
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
            this.emptyStateContainerTarget.classList.add('d-none');
            this.notifyCerveau("app:error.api", { error: error.message });
        }
    }

    /**
     * Réinitialise l'état de la sélection après un rechargement des données.
     * @private
     */
    resetSelection() {
        this.updateSelectAllCheckboxState(); // Met à jour l'état de la case "tout cocher"
    }

    /**
     * NOUVEAU : Notifie la barre de recherche pour réinitialiser la recherche.
     */
    resetSearch() {
        this.notifyCerveau('ui:search.reset-request', { originatorId: this.element.id });
    }

    /**
     * NOUVEAU : Demande au cerveau d'ouvrir le formulaire d'ajout pour l'entité de cette liste.
     */
    requestAddItem() {
        this.notifyCerveau('ui:toolbar.add-request', {
            entityFormCanvas: this.entityFormCanvasValue,
            isCreationMode: true,
            context: {
                originatorId: this.element.id // Pour savoir quelle liste rafraîchir après l'ajout
            }
        });
    }


    /**
     * Récupère les IDs d'entreprise et d'invité depuis l'événement ou les valeurs du contrôleur.
     * @param {object} detail - Le détail de l'événement.
     * @returns {{idEntreprise: number, idInvite: number}}
     * @private
     */
    _getIdsFromEventOrValues(detail) {
        return {
            idEntreprise: detail.idEntreprise || this.idEntrepriseValue,
            idInvite: detail.idInvite || this.idInviteValue,
        };
    }

    /**
     * Construit l'URL pour la requête de recherche dynamique.
     * @param {number} idInvite
     * @param {number} idEntreprise
     * @returns {string}
     * @private
     */
    _buildDynamicQueryUrl(idInvite, idEntreprise) {
        return `/admin/${this.serverRootNameValue}/api/dynamic-query/${idInvite}/${idEntreprise}`;
    }

    /**
     * NOUVEAU : Affiche un squelette de chargement.
     * @private
     */
    _showSkeleton() {
        this.listContainerTarget.classList.remove('d-none');
        this.emptyStateContainerTarget.classList.add('d-none');
        const columnCount = this.element.querySelector('thead tr')?.childElementCount || 1;
        let skeletonHtml = '';
        for (let i = 0; i < 6; i++) { // Affiche 6 lignes de squelette
            skeletonHtml += `
                <tr>
                    ${'<td><div class="skeleton-row"></div></td>'.repeat(columnCount)}
                </tr>
            `;
        }
        this.donneesTarget.innerHTML = skeletonHtml;
    }

    /**
     * Exécute les actions post-chargement des données.
     * @private
     */
    _postDataLoadActions(doc) {
        this.resetSelection();
        this.notifyCerveau('ui:status.notify', { titre: `Liste chargée. ${this.rowCheckboxTargets.length} éléments.` });
        const numericDataPayload = this._extractNumericDataFromResponse(doc); // Renvoie { numericAttributesAndValues: {...} }
        // On envoie le payload tel quel au cerveau
        this.notifyCerveau('app:list.data-loaded', numericDataPayload);
        this.notifyCerveau('app:list.refreshed', { itemCount: this.rowCheckboxTargets.length });
    }

    /**
     * Extrait les données numériques (pour les totaux) du HTML de la réponse.
     * @returns {{numericData: object, numericAttributes: array}}
     * @private
     */
    _extractNumericDataFromResponse(doc) {
        // On cherche d'abord dans la réponse AJAX
        let responseContainer = doc.querySelector('[data-role="response-metadata"]');
        let numericAttributesAndValues = {};

        if (responseContainer && responseContainer.dataset.numericAttributesAndValues) {
            // SOLUTION : On décode la chaîne avant de la parser pour gérer les &quot; etc.
            const decodedAjaxData = this._decodeHtmlEntities(responseContainer.dataset.numericAttributesAndValues);
            try {
                // On s'assure que la chaîne n'est pas vide avant de parser
                if (decodedAjaxData.trim()) {
                    numericAttributesAndValues = JSON.parse(decodedAjaxData);
                }
            } catch (e) {
                console.error("Erreur de parsing des données numériques depuis la réponse AJAX après décodage:", { raw: responseContainer.dataset.numericAttributesAndValues, decoded: decodedAjaxData, error: e });
                numericAttributesAndValues = {};
            }
        } else {
            // Fallback: Si aucune nouvelle donnée n'est trouvée dans la réponse AJAX, on utilise les données initiales.
            // SOLUTION : On décode et on parse la valeur initiale.
            const decodedInitialData = this._decodeHtmlEntities(this.numericAttributesAndValuesValue || '{}');
            try {
                if (decodedInitialData.trim()) {
                    numericAttributesAndValues = JSON.parse(decodedInitialData);
                }
            } catch (e) {
                console.error("Erreur de parsing des données numériques initiales (fallback):", { raw: this.numericAttributesAndValuesValue, decoded: decodedInitialData, error: e });
                numericAttributesAndValues = {};
            }
        }
        const payload = { numericAttributesAndValues: numericAttributesAndValues };
        console.log(this.nomControleur + " - Code: 1980 - _extractNumericDataFromResponse - Extracted payload for Cerveau:", payload);
        return payload;
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