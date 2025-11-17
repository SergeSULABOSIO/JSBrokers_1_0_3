import { Controller } from '@hotwired/stimulus';

/**
 * @class ViewManagerController
 * @extends Controller
 * @description Gère la vue principale, y compris la navigation par onglets entre la liste principale
 * et les listes de collections associées. Il maintient l'état de sélection pour chaque onglet.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} tabsContainerTargets - Le conteneur pour les boutons d'onglets.
     * @property {HTMLElement[]} tabContentContainerTargets - Le conteneur pour le contenu des onglets.
     * @property {HTMLElement[]} displayTargets - L'élément où afficher les messages de statut.
     */
    static targets = ["tabsContainer", "tabContentContainer", "display"];
    
    static values = {
        idEntreprise: Number,
        idInvite: Number
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        console.log("ViewManager connecté avec idEntreprise:", this.idEntrepriseValue, "et idInvite:", this.idInviteValue);
        this.nomControleur = "VIEW-MANAGER";
        /**
         * @property {string} activeTabId - L'ID de l'onglet actuellement actif.
         * @private
         */
        this.activeTabId = 'principal';
        /**
         * @property {object} tabStates - Un objet pour mémoriser l'état de sélection de chaque onglet.
         * @private
         */
        this.tabStates = {};
        /**
         * @property {number|null} collectionTabsParentId - L'ID de l'entité parente qui génère les onglets de collection.
         * @private
         */
        this.collectionTabsParentId = null;
        /**
         * @property {object|null} parentEntityCanvas - Le canvas de l'entité parente sélectionnée.
         * @private
         */
        this.parentEntityCanvas = null;
        /**
         * @property {string|null} parentEntityType - Le type de l'entité parente sélectionnée.
         * @private
         */
        this.parentEntityType = null;

        // NOUVEAU : Notifier le cerveau du contexte initial de la rubrique, y compris l'ID de l'entreprise.
        this.notifyCerveau('app:context.initialized', {
            idEntreprise: this.idEntrepriseValue,
            idInvite: this.idInviteValue,
            formCanvas: this.entityCanvasValue
        });

        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleStatusUpdate = this.handleStatusUpdate.bind(this);

        // --- CORRECTION : Écoute les événements diffusés par le Cerveau ---
        document.addEventListener('ui:selection.changed', this.boundHandleSelection);
        document.addEventListener('app:status.updated', this.boundHandleStatusUpdate);

        // Tente de restaurer l'état précédent (onglet actif, etc.)
        this._restoreState();
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleSelection);
        document.removeEventListener('app:status.updated', this.boundHandleStatusUpdate);
    }

    /**
     * Met à jour la barre de statut lorsqu'un événement est reçu du Cerveau.
     * @param {CustomEvent} event - L'événement `app:status.updated`.
     */
    handleStatusUpdate(event) {
        if (this.hasDisplayTarget) {
            this.displayTarget.innerHTML = event.detail.titre || 'Prêt.';
        }
    }

    /**
     * Gère la mise à jour de la sélection pour créer/supprimer les onglets de collection.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleSelection(event) {
        // CORRECTION : Le payload est maintenant un objet. On extrait la propriété 'selection'.
        const selectos = event.detail.selection || [];

        // On mémorise l'état de sélection pour l'onglet actuellement actif.
        if (this.activeTabId) {
            this.tabStates[this.activeTabId] = selectos;
        }

        const selection = selectos.map(s => s.id);
        const entities = selectos.map(s => s.entity);
        const canvas = selectos.length > 0 ? selectos[0].entityCanvas : null;
        const entityType = selectos.length > 0 ? selectos[0].entityType : null;

        // --- CORRECTION : Mettre à jour le display avec le statut de la sélection ---
        if (this.hasDisplayTarget) {
            const selectionCount = selection ? selection.length : 0;
            if (selectionCount === 0) {
                this.displayTarget.innerHTML = 'Prêt.';
            } else if (selectionCount === 1) {
                this.displayTarget.innerHTML = '1 élément sélectionné.';
            } else {
                this.displayTarget.innerHTML = `${selectionCount} éléments sélectionnés.`;
            }
        }

        const isSingleSelection = entities && entities.length === 1;
        const newParentId = isSingleSelection ? entities[0].id : null;

        // On ne met à jour les onglets que si on est sur l'onglet principal
        // et que l'ID de l'entité parente a changé. [cite: 1]
        if (this.activeTabId !== 'principal' || newParentId === this.collectionTabsParentId) {
            this._saveState(); // Sauvegarde même si l'onglet n'est pas principal pour mémoriser la sélection
            return;
        }

        this.collectionTabsParentId = newParentId;
        // NOUVEAU : Mémoriser le canvas de l'entité parente pour la restauration
        this.parentEntityCanvas = isSingleSelection ? canvas : null;
        // NOUVEAU : Mémoriser le type de l'entité parente pour la restauration
        this.parentEntityType = isSingleSelection ? entityType : null;

        this._removeCollectionTabs();

        if (isSingleSelection) {
            const collections = this._findCollectionsInCanvas(canvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, entities[0], entityType));
        }

        // Sauvegarder l'état après modification des onglets
        this._saveState();
    }

    /**
     * Gère le clic sur un onglet pour changer de vue.
     * @param {MouseEvent} event
     */
    async switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const newTabId = clickedTab.dataset.tabId;
        if (newTabId === this.activeTabId) return;

        // Désactivation de l'ancien onglet
        const oldTab = this.tabsContainerTarget.querySelector(`[data-tab-id="${this.activeTabId}"]`);
        if (oldTab) oldTab.classList.remove('active');

        // Masquage de tous les panneaux de contenu
        this.tabContentContainerTarget.childNodes.forEach(node => {
            if (node.nodeType === 1) node.style.display = 'none';
        });

        // Activation du nouvel onglet
        this.activeTabId = newTabId;
        clickedTab.classList.add('active');

        let newContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);

        if (newContent) {
            newContent.style.display = 'block';
        } else {
            newContent = await this._loadTabContent(clickedTab);
            this.tabContentContainerTarget.appendChild(newContent);
        }

        // On restaure l'état de la sélection pour cet onglet et on notifie le Cerveau.
        const savedSelectos = this.tabStates[this.activeTabId] || [];
        const listManager = newContent ? newContent.querySelector('[data-controller="list-manager"]') : null;
        const formCanvas = listManager ? JSON.parse(listManager.dataset.listManagerEntityFormCanvasValue || 'null') : null;

        this.notifyCerveau('ui:tab.context-changed', {
            tabId: this.activeTabId,
            // On envoie l'état de sélection sauvegardé pour ce nouvel onglet.
            selectos: savedSelectos,
            formCanvas: formCanvas // On envoie le canvas du formulaire de l'onglet.
        });

        // Sauvegarder l'état après un changement d'onglet
        this._saveState();
    }

    /**
     * Notifie le Cerveau du contexte actuel de l'onglet actif, en l'enrichissant
     * avec les informations sur les attributs numériques de la liste affichée.
     * @param {object} selectionState - L'état de sélection à diffuser.
     * @fires cerveau:event
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du Cerveau sur le changement de contexte.`);

        this.dispatch('cerveau:event', {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }

    // --- Méthodes privées d'aide ---

    _findCollectionsInCanvas(canvas) {
        if (!canvas || !canvas.liste) return [];
        return canvas.liste.filter(attr => attr.type === 'Collection');
    }

    _removeCollectionTabs() {
        this.tabsContainerTarget.querySelectorAll('[data-tab-type="collection"]').forEach(tab => {
            const content = this.tabContentContainerTarget.querySelector(`[data-content-id="${tab.dataset.tabId}"]`);
            if (content) content.remove();
            tab.remove();
        });
    }

    _createTab(collectionInfo, parentEntity, parentEntityType) {
        const tabId = `${collectionInfo.code}-for-${parentEntity.id}`;
        const tab = document.createElement('button');
        tab.className = 'list-tab';
        // CORRECTION : On ajoute le paramètre 'usage' qui est attendu par le contrôleur PHP.
        // Pour un onglet, l'usage est 'generic'.
        const collectionUrl = `/admin/${parentEntityType.toLowerCase()}/api/${parentEntity.id}/${collectionInfo.code}/generic`;

        Object.assign(tab.dataset, {
            tabId: tabId,
            tabType: 'collection',
            action: 'click->view-manager#switchTab',
            collectionUrl: collectionUrl,
            entityName: collectionInfo.targetEntity
        });
        tab.textContent = collectionInfo.intitule;
        this.tabsContainerTarget.appendChild(tab);
    }

    async _loadTabContent(tabElement) {
        const content = document.createElement('div');
        Object.assign(content.dataset, {
            contentId: tabElement.dataset.tabId,
            entityName: tabElement.dataset.entityName,
            canAdd: 'true'
        });
        content.style.display = 'block';
        content.innerHTML = '<div class="spinner-container"><div class="custom-spinner"></div></div>';

        try {
            const response = await fetch(tabElement.dataset.collectionUrl);
            if (!response.ok) throw new Error('Échec du chargement des données.');
            content.innerHTML = await response.text();
        } catch (error) {
            console.error("Erreur de chargement du contenu de l'onglet:", error);
            content.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
        }
        return content;
    }

    /**
     * Dispatche un événement personnalisé sur le document.
     * @param {string} name - Le nom de l'événement.
     * @param {object} [detail={}] - Les données à attacher à l'événement.
     * @private
     */
    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }

    /**
     * NOUVEAU : Sauvegarde l'état actuel du gestionnaire de vue dans sessionStorage.
     * @private
     */
    _saveState() {
        const state = {
            activeTabId: this.activeTabId,
            collectionTabsParentId: this.collectionTabsParentId,
            parentEntityCanvas: this.parentEntityCanvas,
            parentEntityType: this.parentEntityType, // Sauvegarde du type
            tabStates: this.tabStates
        };
        // Utilise le chemin de l'URL pour une clé unique par rubrique
        const storageKey = `viewManagerState_${window.location.pathname}`;
        sessionStorage.setItem(storageKey, JSON.stringify(state));
    }

    /**
     * NOUVEAU : Restaure l'état du gestionnaire de vue depuis sessionStorage.
     * @private
     */
    _restoreState() {
        const storageKey = `viewManagerState_${window.location.pathname}`;
        const savedStateJSON = sessionStorage.getItem(storageKey);

        if (!savedStateJSON) return;

        const savedState = JSON.parse(savedStateJSON);

        this.activeTabId = savedState.activeTabId || 'principal';
        this.collectionTabsParentId = savedState.collectionTabsParentId || null;
        this.parentEntityCanvas = savedState.parentEntityCanvas || null;
        this.parentEntityType = savedState.parentEntityType || null; // Restauration du type
        this.tabStates = savedState.tabStates || {};

        // Si un parent était sélectionné, on recrée les onglets de collection
        if (this.collectionTabsParentId && this.parentEntityCanvas && this.parentEntityType) {
            const parentEntity = { id: this.collectionTabsParentId }; // On a juste besoin de l'ID
            const parentEntityType = this.parentEntityType; // On utilise le type restauré
            const collections = this._findCollectionsInCanvas(this.parentEntityCanvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, parentEntity, parentEntityType));
        }

        // On active l'onglet qui était actif
        const tabToActivate = this.tabsContainerTarget.querySelector(`[data-tab-id="${this.activeTabId}"]`);
        if (tabToActivate) {
            // On utilise requestAnimationFrame pour s'assurer que le DOM est prêt
            requestAnimationFrame(() => {
                tabToActivate.click();
            });
        }
    }
}