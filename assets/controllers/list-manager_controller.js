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
        this.boundHandleDBRequest = this.handleDBRequest.bind(this);
        this.boundToggleAll = this.toggleAll.bind(this); // Lier la méthode toggleAll
        
        // On notifie le cerveau avec les données numériques initiales
        this.notifyCerveau('app:list.data-loaded', {
            numericAttributesAndValues: JSON.parse(this.numericAttributesAndValuesValue || '{}')
        });

        console.log("LIST-MANAGER - connect - Code:1980 - numericAttributesAndValuesValue (initial from generic component):", this.numericAttributesAndValuesValue);
        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll); // Écouter l'ordre du Cerveau
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
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

            // Affiche les résultats et met à jour l'état
            this.donneesTarget.innerHTML = html;
            this._postDataLoadActions();

        } catch (error) {
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
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
    _postDataLoadActions() {
        this.resetSelection();
        this.notifyCerveau('ui:status.notify', { titre: `Liste chargée. ${this.rowCheckboxTargets.length} éléments.` });
        const numericDataPayload = this._extractNumericDataFromResponse(); // Renvoie { numericAttributesAndValues: {...} }
        // On envoie le payload tel quel au cerveau
        this.notifyCerveau('app:list.data-loaded', numericDataPayload);
        this.notifyCerveau('app:list.refreshed', {});
    }

    /**
     * Extrait les données numériques (pour les totaux) du HTML de la réponse.
     * @returns {{numericData: object, numericAttributes: array}}
     * @private
     */
    _extractNumericDataFromResponse() {
        const responseContainer = this.donneesTarget.querySelector('[data-numeric-attributes-and-values]');
        let numericAttributesAndValues = {};

        if (responseContainer) {
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
}