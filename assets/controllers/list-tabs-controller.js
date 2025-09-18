// assets/controllers/list-tabs-controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION } from './base_controller.js';

const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

export default class extends Controller {
    static targets = ["rubriqueIcon", "rubriqueName", "tabsContainer", "tabContentContainer", "display"];
    static values = { entityCanvas: Object }

    connect() {
        this.nomControleur = "LIST-TABS";
        this.activeTabId = 'principal';
        this.tabStates = {}; // Essentiel pour la mémorisation de l'état
        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleStatusNotify = this.handleStatusNotify.bind(this);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.addEventListener('list-status:notify', this.boundHandleStatusNotify);

        // On attend que tous les contrôleurs soient initialisés avant d'envoyer le premier contexte.
        requestAnimationFrame(() => { this.dispatchContextChangeEvent(); });
    }

    disconnect() {
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.removeEventListener('list-status:notify', this.boundHandleStatusNotify);
    }

    /**
     * NOUVEAU : Met à jour la barre de résultats quand un événement est reçu.
     */
    handleStatusNotify(event) {
        if (this.hasDisplayTarget) {
            this.displayTarget.innerHTML = event.detail.message || 'Prêt.';
        }
    }



    /**
     * Gère la sélection depuis la liste principale pour créer les onglets.
     */
    handleSelection(event) {
        this.tabStates[this.activeTabId] = event.detail;
        if (this.activeTabId !== 'principal') return;
        this._removeCollectionTabs();
        const { entities, canvas, entityType } = event.detail;
        if (entities && entities.length === 1) {
            const collections = this._findCollectionsInCanvas(canvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, entities[0], entityType));
        }
    }

    /**
     * Gère le clic sur un onglet pour changer de vue.
     */
    async switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const newTabId = clickedTab.dataset.tabId;
        if (newTabId === this.activeTabId) return;

        const oldTab = this.tabsContainerTarget.querySelector(`[data-tab-id="${this.activeTabId}"]`);
        if (oldTab) oldTab.classList.remove('active');
        const oldContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        if (oldContent) oldContent.style.display = 'none';

        this.activeTabId = newTabId;
        clickedTab.classList.add('active');

        let newContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        if (newContent) {
            newContent.style.display = 'block';
            this.dispatchContextChangeEvent(); // Si le contenu existe déjà, on peut dispatcher l'événement immédiatement.
        } else {
            newContent = await this._loadTabContent(clickedTab);
            this.tabContentContainerTarget.appendChild(newContent);

            requestAnimationFrame(() => {
                this.dispatchContextChangeEvent();
            });
        }
        this._restoreTabState(this.activeTabId);
        // this.dispatchContextChangeEvent();
    }


    _restoreTabState(tabId) {
        const savedState = this.tabStates[tabId];
        const payload = savedState || { entities: [], selection: [], canvas: {}, entityType: '' };
        document.dispatchEvent(new CustomEvent(EVEN_CHECKBOX_PUBLISH_SELECTION, {
            bubbles: true,
            detail: payload
        }));
    }

    /**
     * --- MODIFICATION MAJEURE ---
     * Cette méthode est réécrite. Elle ne scanne plus le 'canvas'.
     * Elle lit les données numériques pré-calculées depuis l'attribut data- que nous avons ajouté.
     */
    dispatchContextChangeEvent() {
        console.log(this.nomControleur + " - Tentative d'envoi de l'événement context-changed.");
        const activeContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        if (!activeContent) return;

        const listControllerElement = activeContent.querySelector('[data-controller~="liste-principale"]');
        if (!listControllerElement) return;

        // --- MODIFICATION ---
        // On lit l'attribut avec le nom correct et cohérent : 'numericAttributes'.
        const numericData = JSON.parse(listControllerElement.dataset.listePrincipaleNumericAttributesValue || '{}');
        const firstItemId = Object.keys(numericData)[0];
        const numericAttributesOptions = {};

        if (firstItemId && numericData[firstItemId]) {
            for (const key in numericData[firstItemId]) {
                numericAttributesOptions[key] = numericData[firstItemId][key].description;
            }
        }

        this.element.dispatchEvent(new CustomEvent(EVT_CONTEXT_CHANGED, {
            bubbles: true,
            detail: {
                // On renomme ici pour plus de clarté dans l'événement, mais la source est correcte.
                numericAttributes: numericAttributesOptions,
                numericData: numericData
            }
        }));
        console.log(this.nomControleur + " - Envoi de l'événement avec les détails:", numericAttributesOptions, numericData);
    }

    // --- Méthodes privées ---

    _saveTabState(tabId, selectedEntities) {
        this.tabStates[tabId] = {
            selectedIds: selectedEntities.map(e => e.id)
        };
    }

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
            action: 'click->list-tabs#switchTab',
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
        content.innerHTML = '<div class="p-5 text-center"><div class="spinner-border" role="status"></div></div>';

        try {
            const response = await fetch(tabElement.dataset.collectionUrl);
            if (!response.ok) throw new Error('Échec du chargement des données.');
            content.innerHTML = await response.text();
        } catch (error) {
            console.error(error);
            content.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
        }
        return content;
    }
}