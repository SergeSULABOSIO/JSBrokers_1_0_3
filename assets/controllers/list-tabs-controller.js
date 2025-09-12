// assets/controllers/list-tabs-controller.js
import { Controller } from '@hotwired/stimulus';

// Événement pour notifier les barres d'outils du changement de contexte
const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

export default class extends Controller {
    static targets = [
        "rubriqueIcon", "rubriqueName",
        "tabsContainer", "tabContentContainer"
    ];

    static values = {
        entityCanvas: Object
    }

    connect() {
        this.tabStates = {}; // Essentiel pour la mémorisation de l'état
        this.activeTabId = 'principal';

        this.boundHandleSelection = this.handleSelection.bind(this);
        document.addEventListener('checkbox:selection-published', this.boundHandleSelection);
        
        // Contexte initial pour les barres d'outils
        this.dispatchContextChangeEvent();
    }

    disconnect() {
        document.removeEventListener('checkbox:selection-published', this.boundHandleSelection);
    }

    /**
     * Gère la sélection depuis la liste principale pour créer les onglets.
     */
    handleSelection(event) {
        const { entities, canvas } = event.detail;

        if (this.activeTabId !== 'principal') return;

        this._saveTabState('principal', entities);
        this._removeCollectionTabs();

        if (entities.length === 1) {
            const selectedEntity = entities[0];
            const collections = this._findCollectionsInCanvas(canvas);

            collections.forEach(collectionInfo => {
                this._createTab(collectionInfo, selectedEntity);
            });
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

        // Désactivation de l'ancien onglet
        const oldTab = this.tabsContainerTarget.querySelector(`[data-tab-id="${this.activeTabId}"]`);
        if (oldTab) oldTab.classList.remove('active');
        const oldContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        if (oldContent) oldContent.style.display = 'none';

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

        this.dispatchContextChangeEvent();
    }

    /**
     * Informe les autres contrôleurs (barres d'outils) du contexte actuel.
     */
    dispatchContextChangeEvent() {
        const activeContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        if (!activeContent) return;
        
        const listControllerElement = activeContent.querySelector('[data-controller]');
        
        const detail = {
            listControllerId: listControllerElement ? listControllerElement.id : null, 
            entityName: activeContent.dataset.entityName || this.entityCanvasValue.parametres.description,
            canAdd: activeContent.dataset.canAdd === 'true' 
        };

        this.element.dispatchEvent(new CustomEvent(EVT_CONTEXT_CHANGED, { bubbles: true, detail }));
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

    _createTab(collectionInfo, parentEntity) {
        const tabId = `${collectionInfo.code}-for-${parentEntity.id}`;
        const tab = document.createElement('button');
        tab.className = 'list-tab';
        tab.dataset.tabId = tabId;
        tab.dataset.tabType = 'collection';
        tab.dataset.action = 'click->list-tabs#switchTab';
        tab.dataset.collectionUrl = `/admin/${parentEntity.entityType.toLowerCase()}/api/${parentEntity.id}/${collectionInfo.code}`;
        tab.dataset.entityName = collectionInfo.targetEntity;
        tab.textContent = collectionInfo.intitule;
        this.tabsContainerTarget.appendChild(tab);
    }

    async _loadTabContent(tabElement) {
        const content = document.createElement('div');
        content.dataset.contentId = tabElement.dataset.tabId;
        content.dataset.entityName = tabElement.dataset.entityName;
        content.dataset.canAdd = 'true';
        content.style.display = 'block';
        content.innerHTML = '<div class="p-5 text-center"><div class="spinner-border" role="status"></div></div>';

        try {
            const response = await fetch(tabElement.dataset.collectionUrl);
            if (!response.ok) throw new Error('Failed to load collection data.');
            content.innerHTML = await response.text();
        } catch (error) {
            console.error(error);
            content.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
        }
        return content;
    }
}