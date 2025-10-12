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
        identreprise: Number,
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
        this.urlAPIDynamicQuery = `/admin/${this.serverRootNameValue}/api/dynamic-query/${this.identrepriseValue}`;

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
        const selectos = event.detail || [];
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
        const idEntreprise = event.detail.idEntreprise || this.identrepriseValue;
        const criteria = event.detail.criteria || {};
        const entityName = this.entiteValue;

        if (!entityName) return;

        // On reconstruit l'URL avec le bon ID à chaque requête pour plus de sûreté.
        // const url = `/admin/${this.controleurphpValue}/api/dynamic-query/${idEntreprise}`;
        const url = `/admin/${this.serverRootNameValue}/api/dynamic-query/${idEntreprise}`;
        console.log(this.nomControleur + " - ICI URL:", url);
        this.donneesTarget.innerHTML = `<div class="spinner-container"><div class="custom-spinner"></div></div>`;

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
            this.notifyCerveau('ui:status.notify', { titre: `Liste chargée. ${this.rowCheckboxTargets.length} éléments.` });

        } catch (error) {
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
            this.notifyCerveau('app:error.api', { error: error.message });
        }
    }

    /**
     * Gère une demande de rafraîchissement globale.
     */
    handleGlobalRefresh(event) {
        // MISSION 3 : On ne rafraîchit que si on est dans la vue principale
        const parentView = this.element.closest('[data-controller="view-manager"]');
        if (parentView) {
            console.log(`${this.nomControleur} - Demande de rafraîchissement global reçue.`, event.detail);
            // On récupère l'ID de l'entreprise directement depuis l'événement envoyé par le Cerveau.
            const idEntreprise = event.detail.idEntreprise;
            const idInvite = event.detail.idInvite;
            // On simule un événement de requête avec les bonnes informations.
            this.handleDBRequest(
                { 
                    detail: 
                    { 
                        criteria: {}, 
                        idEntreprise: idEntreprise,
                        idInvite: idInvite
                    }
                }
            );
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