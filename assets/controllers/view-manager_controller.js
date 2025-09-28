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

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
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

        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleStatusUpdate = this.handleStatusUpdate.bind(this);

        // --- CORRECTION : Écoute les événements diffusés par le Cerveau ---
        document.addEventListener('ui:selection.changed', this.boundHandleSelection);
        document.addEventListener('app:status.updated', this.boundHandleStatusUpdate);
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
        // On mémorise l'état de sélection pour l'onglet actuellement actif.
        if (this.activeTabId) {
            this.tabStates[this.activeTabId] = event.detail;
        }

        const { entities, canvas, entityType } = event.detail;
        const isSingleSelection = entities && entities.length === 1;
        const newParentId = isSingleSelection ? entities[0].id : null;

        // On ne met à jour les onglets que si on est sur l'onglet principal
        // et que l'ID de l'entité parente a changé.
        if (this.activeTabId !== 'principal' || newParentId === this.collectionTabsParentId) {
            return;
        }

        this.collectionTabsParentId = newParentId;
        this._removeCollectionTabs();

        if (isSingleSelection) {
            const collections = this._findCollectionsInCanvas(canvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, entities[0], entityType));
        }
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
        const savedState = this.tabStates[this.activeTabId];
        const payload = savedState || { entities: [], selection: [], canvas: {}, entityType: '' };
        this.notifyCerveau(payload);
    }

    /**
     * Notifie le Cerveau du contexte actuel de l'onglet actif, en l'enrichissant
     * avec les informations sur les attributs numériques de la liste affichée.
     * @param {object} selectionState - L'état de sélection à diffuser.
     * @fires cerveau:event
     * @private
     */
    notifyCerveau(selectionState) {
        console.log(`${this.nomControleur} - Notification du Cerveau sur le changement de contexte.`);
        const activeContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        const listControllerElement = activeContent ? activeContent.querySelector('[data-controller~="liste-principale"]') : null;

        let payload = { ...selectionState, numericAttributes: null, numericData: null };

        if (listControllerElement) {
            const numericData = JSON.parse(listControllerElement.dataset.listePrincipaleNumericAttributesValue || '{}');
            const firstItemId = Object.keys(numericData)[0];

            if (firstItemId && numericData[firstItemId] && Object.keys(numericData[firstItemId]).length > 0) {
                const numericAttributesOptions = {};
                for (const key in numericData[firstItemId]) {
                    numericAttributesOptions[key] = numericData[firstItemId][key].description;
                }
                payload.numericAttributes = numericAttributesOptions;
                payload.numericData = numericData;
            }
        }

        this.dispatch('cerveau:event', {
            type: 'ui:selection.changed',
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
        const collectionUrl = `/admin/${parentEntityType.toLowerCase()}/api/${parentEntity.id}/${collectionInfo.code}`;

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
}