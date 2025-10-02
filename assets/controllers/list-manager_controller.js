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
     * @property {StringValue} controleurphpValue - Le nom du contrôleur PHP pour les appels API.
     */
    static values = {
        identreprise: Number,
        entityFormCanvas: Object,
        entite: String,
        controleurphp: String,
    };

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "LIST-MANAGER";
        this.urlAPIDynamicQuery = `/admin/${this.controleurphpValue}/api/dynamic-query/${this.identrepriseValue}`;
        // console.log(`${this.nomControleur} - Connecté pour l'entité '${this.entiteValue}'.`);

        this.selectedEntities = [];
        this.selectedIds = [];
        this.selectedEntityType = null;
        this.selectedEntityCanvas = null;

        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        this.boundHandleItemSelectionChange = this.handleItemSelectionChange.bind(this);
        this.boundHandleDBRequest = this.handleDBRequest.bind(this);
        this.boundHandleGlobalRefresh = this.handleGlobalRefresh.bind(this);

        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:list-row.selection-changed:relay', this.boundHandleItemSelectionChange);
        document.addEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.addEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);

        this.publishSelection(); // Publie l'état initial (vide)
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:list-row.selection-changed:relay', this.boundHandleItemSelectionChange);
        document.removeEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.removeEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);
    }

    // --- GESTION DE LA SÉLECTION ---

    /**
     * Gère le clic sur la case "Tout cocher".
     * Coche ou décoche toutes les cases de la liste et met à jour l'état.
     */
    toggleAll() {
        const isChecked = this.selectAllCheckboxTarget.checked;
        this.rowCheckboxTargets.forEach(checkbox => {
            // On ne déclenche l'événement que si l'état change réellement
            if (checkbox.checked !== isChecked) {
                checkbox.checked = isChecked;
                // Déclenche manuellement l'événement 'change' pour que le contrôleur list-row réagisse
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    /**
     * Gère le changement de sélection d'un élément individuel, relayé par le Cerveau.
     * @param {CustomEvent} event - L'événement `app:list-item.selection-changed:relay`.
     */
    handleItemSelectionChange(event) {
        const { id, isChecked, entity, canvas, entityType } = event.detail;
        const idStr = String(id);

        if (isChecked) {
            if (!this.selectedIds.includes(idStr)) {
                this.selectedIds.push(idStr);
                this.selectedEntities.push(entity);
                this.selectedEntityType = entityType;
                this.selectedEntityCanvas = canvas;
            }
        } else {
            const index = this.selectedIds.indexOf(idStr);
            if (index > -1) {
                this.selectedIds.splice(index, 1);
                this.selectedEntities.splice(index, 1);
            }
            if (this.selectedIds.length === 0) {
                this.selectedEntityType = null;
                this.selectedEntityCanvas = null;
            }
        }

        this.updateSelectAllCheckboxState();
        this.publishSelection();
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
        const restoredSelectionIds = new Set((event.detail.selection || []).map(id => String(id)));
        this.selectedIds = Array.from(restoredSelectionIds);

        this.rowCheckboxTargets.forEach(checkbox => {
            const checkboxId = String(checkbox.dataset.listRowIdobjetValue);
            checkbox.checked = restoredSelectionIds.has(checkboxId);
            checkbox.closest('tr')?.classList.toggle('row-selected', checkbox.checked);
        });

        this.updateSelectAllCheckboxState();
    }

    /**
     * Publie l'état de sélection actuel au Cerveau.
     * @fires cerveau:event
     * @private
     */
    publishSelection() {
        // CORRECTION : Enrichir le payload avec les données numériques dès le départ.
        const numericDataRaw = this.element.dataset.listManagerNumericAttributesValue;
        const numericData = numericDataRaw ? JSON.parse(numericDataRaw) : {};
        let numericAttributesOptions = null;

        const firstItemId = Object.keys(numericData)[0];
        if (firstItemId && numericData[firstItemId] && Object.keys(numericData[firstItemId]).length > 0) {
            numericAttributesOptions = {};
            for (const key in numericData[firstItemId]) {
                numericAttributesOptions[key] = numericData[firstItemId][key].description;
            }
        }

        this.notifyCerveau('ui:selection.updated', {
            selection: this.selectedIds,
            entities: this.selectedEntities,
            canvas: this.selectedEntityCanvas,
            entityType: this.selectedEntityType,
            entityFormCanvas: this.entityFormCanvasValue,
            numericAttributes: numericAttributesOptions,
            numericData: numericData,
        });
    }

    // --- GESTION DES DONNÉES ---

    /**
     * Gère une demande de recherche de données.
     * @param {CustomEvent} event - L'événement `app:base-données:sélection-request`.
     */
    async handleDBRequest(event) {
        const { criteria } = event.detail;
        const entityName = this.entiteValue;

        if (!entityName) return;

        this.donneesTarget.innerHTML = `<div class="spinner-container"><div class="custom-spinner"></div></div>`;

        try {
            const response = await fetch(this.urlAPIDynamicQuery, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'text/html' },
                body: JSON.stringify({ entityName, criteria, page: 1, limit: 100 }),
            });

            const html = await response.text();
            if (!response.ok) throw new Error(html || 'Erreur serveur');

            // Affiche les résultats et met à jour l'état
            this.donneesTarget.innerHTML = html;
            this.resetSelection();
            this.notifyCerveau('ui:status.notify', { titre: `Liste chargée. ${this.rowCheckboxTargets.length} éléments.` });

        } catch (error) {
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
            this.notifyCerveau('app:error.api', { error: error.message });
        }
    }

    /**
     * Gère une demande de rafraîchissement globale.
     */
    handleGlobalRefresh() {
        // On ne rafraîchit que la liste principale (pas les listes de collection dans les onglets)
        if (this.element.closest('[data-content-id="principal"]')) {
            console.log(`${this.nomControleur} - Demande de rafraîchissement reçue. Rechargement.`);
            this.handleDBRequest({ detail: { criteria: {} } });
        }
    }

    /**
     * Réinitialise l'état de la sélection après un rechargement des données.
     * @private
     */
    resetSelection() {
        this.selectedIds = [];
        this.selectedEntities = [];
        this.selectedEntityType = null;
        this.selectedEntityCanvas = null;
        this.updateSelectAllCheckboxState();
        this.publishSelection();
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
}