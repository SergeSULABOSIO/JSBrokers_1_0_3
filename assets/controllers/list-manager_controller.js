import { Controller } from '@hotwired/stimulus';

/**
 * @class ListManagerController
 * @extends Controller
 * @description Gère une liste de données, y compris la sélection, la récupération des données
 * et la communication de l'état de la liste au reste de l'application via le Cerveau.
 */
export default class extends Controller {
    
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
        this.boundToggleAll = this.toggleAll.bind(this); // Lier la méthode toggleAll
        
        // On notifie le cerveau avec les données numériques initiales
        this.notifyCerveau('app:list.data-loaded', {
            numericAttributesAndValues: JSON.parse(this.numericAttributesAndValuesValue || '{}')
        });

        console.log("LIST-MANAGER - connect - Code:1980 - numericAttributesAndValuesValue (initial from generic component):", this.numericAttributesAndValuesValue);
        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:list.refresh-request', this.boundHandleDBRequest); // CORRECTION : On écoute l'ordre du cerveau, pas la demande directe.
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll); // Écouter l'ordre du Cerveau

        // NOUVEAU : Tente de restaurer l'état de la liste depuis sessionStorage.
        if (!this._restoreState()) {
            // CORRECTION : Si la restauration échoue, on vérifie l'état initial.
            // Si la liste a été rendue vide par le serveur, on affiche l'état vide.
            if (this.nbElementsValue === 0) {
                this.listContainerTarget.classList.add('d-none');
                this.emptyStateContainerTarget.classList.remove('d-none');
                this._logDebug("Liste initialisée vide par le serveur. Affichage de l'état vide.");
            }
        }

        // NOUVEAU : Notifier le cerveau que ce contexte de liste est prêt.
        // Cela permet au cerveau de mettre à jour la barre d'outils avec le bon formCanvas.
        if (this.element.id !== 'principal') {
            console.log(`${this.nomControleur} - Notification de contexte prêt pour l'onglet: ${this.element.id}`);
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
    }

    // --- GESTION DE LA SÉLECTION ---

    /**
     * Gère le clic sur la case "Tout cocher" ou une demande externe du Cerveau.
     * Coche ou décoche toutes les cases de la liste et notifie le Cerveau avec l'état final.
     */
    toggleAll(event) {
        // Si l'événement vient de la case à cocher de l'en-tête, on utilise son état.
        // Sinon (demande du Cerveau), on détermine s'il faut cocher ou décocher.
        const isTriggeredByUser = event && this.hasSelectAllCheckboxTarget && event.currentTarget === this.selectAllCheckboxTarget;
        const totalRows = this.rowCheckboxTargets.length; // Utilise la propriété Stimulus
        const checkedRows = this.rowCheckboxTargets.filter(c => c.checked).length; // Utilise la propriété Stimulus

        // Détermine l'action : si tout est déjà coché, on décoche. Sinon, on coche.
        const shouldCheck = isTriggeredByUser ? this.selectAllCheckboxTarget.checked : checkedRows < totalRows;

        const allSelectos = [];
        this.rowCheckboxTargets.forEach(checkbox => {
            checkbox.checked = shouldCheck;
            checkbox.closest('tr')?.classList.toggle('row-selected', shouldCheck);
            if (shouldCheck) {
                // Construit et ajoute le "selecto" à la liste
                const listRowController = this.application.getControllerForElementAndIdentifier(checkbox.closest('[data-controller="list-row"]'), 'list-row');
                if (listRowController) {
                    allSelectos.push(listRowController.buildSelectoPayload());
                }
            }
        });

        // Notifie le Cerveau UNE SEULE FOIS avec la liste complète des sélections.
        this.notifyCerveau('ui:list.selection-completed', { selectos: allSelectos }); // Cet événement est spécifique à la complétion de la sélection
        this.updateSelectAllCheckboxState();
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

        // NOUVEAU : Sauvegarder l'état chaque fois que la sélection change.
        this._saveState();
    }

    // --- GESTION DES DONNÉES ---

    /**
     * Gère une demande de recherche de données.
     * @param {CustomEvent} event - L'événement `app:base-données:sélection-request`.
     */
    async handleDBRequest(event) {
        console.log(this.nomControleur + " - Code: 1986 - Recherche", event.detail);

        // CORRECTION : On vérifie si la demande de rafraîchissement nous est destinée.
        // L'ID de l'élément du list-manager doit correspondre à l'originatorId envoyé par le cerveau.
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

        this._showLoadingSpinner();

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/html' },
                body: JSON.stringify({ entityName, criteria, page: 1, limit: 100 }),
            });

            const html = await response.text();
            if (!response.ok) throw new Error(html || 'Erreur serveur');

            // Log pour débogage : voir la réponse brute du serveur
            // console.log("LIST-MANAGER - Réponse HTML brute du serveur:", html);

            // CORRECTION : On enveloppe le HTML dans une structure de tableau valide
            // pour que le DOMParser interprète correctement les balises <tr>.
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
                // CORRECTION : Sauvegarder l'état après une recherche réussie
                this._saveState();
            } else {
                this.listContainerTarget.classList.add('d-none');
                this.emptyStateContainerTarget.classList.remove('d-none');
                this.donneesTarget.innerHTML = ''; // Vider le tbody
                this._saveState(); // Sauvegarder l'état vide également
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
        // La publication est maintenant gérée par le Cerveau
    }

    /**
     * NOUVEAU : Notifie la barre de recherche pour réinitialiser la recherche.
     */
    resetSearch() {
        // CORRECTION : On notifie le cerveau d'une intention de réinitialisation globale.
        // Le cerveau saura quelle est la liste active et ciblera la réinitialisation.
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

    // --- COMMUNICATION ---

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type d'événement pour le Cerveau (ex: 'ui:selection.updated').
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

    // --- MÉTHODES PRIVÉES DE REFACTORISATION ---

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
     * Affiche un spinner de chargement dans le tableau.
     * @private
     */
    _showLoadingSpinner() {
        const columnCount = this.element.querySelector('thead tr')?.childElementCount || 1;
        this.donneesTarget.innerHTML = `
            <tr>
                <td colspan="${columnCount}" class="text-center py-5">
                    <div class="spinner-container d-flex justify-content-center align-items-center"><div class="custom-spinner"></div></div>
                </td>
            </tr>
        `;
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
        const responseContainer = doc.querySelector('[data-role="response-metadata"]');
        let numericAttributesAndValues = {};

        if (responseContainer && responseContainer.dataset.numericAttributesAndValues) {
            numericAttributesAndValues = JSON.parse(responseContainer.dataset.numericAttributesAndValues || '{}');
        } else {
            // Fallback: Si aucune nouvelle donnée n'est trouvée dans la réponse AJAX, on utilise les données initiales.
            numericAttributesAndValues = this.numericAttributesAndValuesValue;
        }
        const payload = { numericAttributesAndValues: numericAttributesAndValues };
        console.log(this.nomControleur + " - Code: 1980 - _extractNumericDataFromResponse - Extracted payload for Cerveau:", payload);
        return payload;
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

    /**
     * NOUVEAU : Sauvegarde le contenu HTML actuel de la liste dans sessionStorage.
     * @private
     */
    _saveState() {
        console.log(this.nomControleur + " - Code: 1986 - _saveState: Sauvegarde de l'état de la liste." + this.listUrlValue);
        
        if (!this.listUrlValue) return; // Ne rien faire si l'URL n'est pas définie
        const storageKey = `listContent_${this.listUrlValue}`;
        const state = {
            html: this.donneesTarget.innerHTML,
            selectedIds: Array.from(this.selectedIds)
        };

        try {
            sessionStorage.setItem(storageKey, JSON.stringify(state));
            this._logDebug(`État de la liste sauvegardé pour la clé : ${storageKey}`);
        } catch (e) {
            console.error("Erreur lors de la sauvegarde de l'état de la liste dans sessionStorage:", e);
        }
    }

    /**
     * NOUVEAU : Restaure le contenu de la liste depuis sessionStorage.
     * @returns {boolean} True si la restauration a réussi, sinon false.
     * @private
     */
    _restoreState() {
        console.log(this.nomControleur + " - Code: 1986 - _restoreState: Restauration de l'état de la liste." + this.listUrlValue);
        if (!this.listUrlValue) return false;
        const storageKey = `listContent_${this.listUrlValue}`;
        const savedStateJSON = sessionStorage.getItem(storageKey);

        if (savedStateJSON) {
            const savedState = JSON.parse(savedStateJSON);
            const { html, selectedIds = [] } = savedState;

            this._logDebug(`Restauration de l'état depuis la clé : ${storageKey}`);
            this.donneesTarget.innerHTML = html;
            
            const hasContent = html.trim() !== '';
            this.listContainerTarget.classList.toggle('d-none', !hasContent);
            this.emptyStateContainerTarget.classList.toggle('d-none', hasContent);

            // NOUVEAU : Restaurer la sélection visuelle et notifier le cerveau.
            const restoredSelectos = [];
            const selectedIdsSet = new Set(selectedIds);
            this.rowCheckboxTargets.forEach(checkbox => {
                const rowId = String(checkbox.dataset.listRowIdobjetValue);
                const isSelected = selectedIdsSet.has(rowId);
                checkbox.checked = isSelected;
                checkbox.closest('tr')?.classList.toggle('row-selected', isSelected);
                if (isSelected) {
                    const listRowController = this.application.getControllerForElementAndIdentifier(checkbox.closest('[data-controller="list-row"]'), 'list-row');
                    if (listRowController) {
                        restoredSelectos.push(listRowController.buildSelectoPayload());
                    }
                }
            });
            this.notifyCerveau('ui:list.selection-completed', { selectos: restoredSelectos });

            // On simule le post-chargement pour notifier le cerveau et mettre à jour les sélections/totaux
            const doc = new DOMParser().parseFromString(`<table><tbody>${html}</tbody></table>`, 'text/html');
            this._postDataLoadActions(doc);
            return true;
        }
        return false;
    }
}