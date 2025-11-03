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
        idEntreprise: Number,
        idInvite: Number,
        entityFormCanvas: Object,
        entite: String,
        serverRootName: String
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
        this.boundHandleGlobalRefresh = this.handleGlobalRefresh.bind(this);
        this.boundToggleAll = this.toggleAll.bind(this); // Lier la méthode toggleAll

        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.addEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.addEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);
        document.addEventListener('app:list.toggle-all-request', this.boundToggleAll); // Écouter l'ordre du Cerveau
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.removeEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);
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
        const isTriggeredByUser = event && event.currentTarget === this.selectAllCheckboxTarget;
        const totalRows = this.rowCheckboxTargets.length;
        const checkedRows = this.rowCheckboxTargets.filter(c => c.checked).length;
        
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
        this.notifyCerveau('ui:list.selection-completed', { selectos: allSelectos });
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
        // CORRECTION : Le payload est maintenant un objet. On extrait la propriété 'selection'.
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
        console.log(this.nomControleur + " - ICI Demande de chargement reçue.", event.detail);
        // --- CORRECTION : On s'assure que l'ID de l'entreprise est toujours correct ---
        // On prend l'ID de l'événement s'il existe, sinon on prend la valeur initiale du contrôleur.
        const idEntreprise = event.detail.idEntreprise || this.idEntrepriseValue;
        const idInvite = event.detail.idInvite || this.idInviteValue;
        const criteria = event.detail.criteria || {};
        const entityName = this.entiteValue;

        if (!entityName) return;

        // On reconstruit l'URL avec le bon ID à chaque requête pour plus de sûreté.
        // const url = `/admin/${this.controleurphpValue}/api/dynamic-query/${idEntreprise}`;
        const url = `/admin/${this.serverRootNameValue}/api/dynamic-query/${idInvite}/${idEntreprise}`;
        console.log(this.nomControleur + " - ICI URL:", url);

        // On récupère le nombre de colonnes depuis l'en-tête du tableau pour que la cellule du spinner puisse s'étendre sur toute la largeur.
        const columnCount = this.element.querySelector('thead tr')?.childElementCount || 1;
        this.donneesTarget.innerHTML = `
            <tr>
                <td colspan="${columnCount}" class="text-center py-5">
                    <div class="spinner-container d-flex justify-content-center align-items-center"><div class="custom-spinner"></div></div>
                </td>
            </tr>
        `;

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
            this.resetSelection();
            // On notifie le Cerveau que la liste est chargée et on lui passe le nombre d'éléments.
            this.notifyCerveau('ui:status.notify', { titre: `Liste chargée. ${this.rowCheckboxTargets.length} éléments.` });
            // NOUVEAU : On notifie le Cerveau avec les données numériques pour la barre des totaux.
            this.notifyCerveau('app:list.data-loaded', {
                numericData: JSON.parse(this.element.dataset.listManagerNumericDataValue || '{}'), // Un objet est correct ici
                numericAttributes: JSON.parse(this.element.dataset.listManagerNumericAttributesValue || '[]') // Doit être un tableau
            });
            this.notifyCerveau('app:list.refreshed', {}); // NOUVEAU : Notifie le Cerveau que l'actualisation est terminée.

        } catch (error) {
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
            this.notifyCerveau('app:error.api', { error: error.message });
        }
    }

    /**
     * Gère une demande de rafraîchissement globale.
     */
    handleGlobalRefresh(event) {
        const { originatorId, idEntreprise, idInvite } = event.detail;

        // Le list-manager doit se rafraîchir si :
        // 1. L'originatorId est null ou undefined (rafraîchissement global, ex: depuis la toolbar principale ou un save général).
        // 2. L'originatorId correspond à l'ID de ce list-manager (rafraîchissement ciblé).
        // De plus, s'assurer que ce list-manager est bien celui de la vue principale (pas une collection imbriquée).
        const isMainListManager = this.element.closest('[data-controller="view-manager"]');

        if (isMainListManager && (originatorId === null || originatorId === undefined || originatorId === this.element.id)) {
            console.log(`${this.nomControleur} - Demande de rafraîchissement reçue.`, event.detail);
            this.handleDBRequest({
                detail: {
                    criteria: {},
                    idEntreprise: idEntreprise,
                    idInvite: idInvite
                }
            });
        }
    }

    /**
     * Réinitialise l'état de la sélection après un rechargement des données.
     * @private
     */
    resetSelection() {
        this.updateSelectAllCheckboxState();
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
}