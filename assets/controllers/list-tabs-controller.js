// assets/controllers/list-tabs-controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_DATA_BASE_DONNEES_LOADED } from './base_controller.js';

export default class extends Controller {
    static targets = ["rubriqueIcon", "rubriqueName", "tabsContainer", "tabContentContainer", "display"];
    static values = { entityCanvas: Object }

    connect() {
        this.nomControleur = "LIST-TABS";
        this.activeTabId = 'principal';
        this.tabStates = {}; // Essentiel pour la mémorisation de l'état
        this.collectionTabsParentId = null; // NOUVEAU : Mémorise l'ID de l'entité parente des onglets de collection
        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleStatusNotify = this.handleStatusNotify.bind(this);
        this.boundNotifyCerveau = this.notifyCerveau.bind(this);

        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.addEventListener('list-status:notify', this.boundHandleStatusNotify);
        document.addEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundNotifyCerveau);
        document.addEventListener('totals-bar:request-context', this.boundNotifyCerveau);

        this.notifyCerveau();
    }

    disconnect() {
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.removeEventListener('list-status:notify', this.boundHandleStatusNotify);
        document.removeEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundNotifyCerveau);
        document.removeEventListener('totals-bar:request-context', this.boundNotifyCerveau);
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
        // --- MODIFICATION : On mémorise la sélection pour l'onglet actif ---
        if(this.activeTabId){
            this.tabStates[this.activeTabId] = event.detail;
        }

        const { entities, canvas, entityType } = event.detail;
        const isSingleSelection = entities && entities.length === 1;
        const newParentId = isSingleSelection ? entities[0].id : null;

        // Si on n'est pas sur l'onglet principal, ou si l'ID qui génère les onglets n'a pas changé, on ne fait rien.
        // C'est la correction clé : on ne supprime/recrée que si c'est nécessaire.
        if (this.activeTabId !== 'principal') return;
        if (newParentId === this.collectionTabsParentId) return;

        // La sélection a changé, on met à jour les onglets.
        this.collectionTabsParentId = newParentId;
        this._removeCollectionTabs();

        if (isSingleSelection) {
            const collections = this._findCollectionsInCanvas(canvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, entities[0], entityType));
        } else {
            // Si la sélection est multiple ou vide, on s'assure que l'ID parent est bien nul.
            this.collectionTabsParentId = null;
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

        // On masque TOUS les panneaux de contenu
        this.tabContentContainerTarget.childNodes.forEach(node => {
            if (node.nodeType === 1) { // S'assurer que c'est un élément
                node.style.display = 'none';
            }
        });

        // On active le nouvel onglet
        this.activeTabId = newTabId;
        clickedTab.classList.add('active');

        // On cherche si le contenu de ce nouvel onglet existe déjà
        let newContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);

        if (newContent) {
            // S'il existe, on l'affiche simplement. La sélection est préservée !
            newContent.style.display = 'block';
        } else {
            // S'il n'existe pas, on le charge depuis le serveur.
            newContent = await this._loadTabContent(clickedTab);
            this.tabContentContainerTarget.appendChild(newContent);
        }

        // On restaure l'état de la sélection pour cet onglet.
        this._restoreTabState(this.activeTabId);

        // --- CORRECTION : Notifier la barre des totaux du changement de contexte ---
        // On attend un court instant que le DOM soit à jour avant de notifier.
        setTimeout(() => this.notifyCerveau(), 50);
    }


    _restoreTabState(tabId) {
        const savedState = this.tabStates[tabId];
        const payload = savedState || { entities: [], selection: [], canvas: {}, entityType: '' };
        // --- MODIFICATION : On notifie le cerveau directement au lieu de redéclencher un événement de sélection ---
        this.notifyCerveau(payload);
    }

    /**
     * Notifie le cerveau du contexte actuel de l'onglet actif.
     * @param {object|null} selectionState - L'état de sélection à utiliser. Si null, il sera déduit.
     */
    notifyCerveau(selectionState = null) {
        console.log(this.nomControleur + " - Notification du cerveau sur le changement de contexte.");
        const activeContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);
        const listControllerElement = activeContent ? activeContent.querySelector('[data-controller~="liste-principale"]') : null;

        // L'état de sélection actuel (si non fourni)
        const currentSelection = selectionState || this.tabStates[this.activeTabId] || { entities: [], selection: [], canvas: {}, entityType: '' };

        let payload = {
            ...currentSelection,
            numericAttributes: null,
            numericData: null
        };

        if (!listControllerElement) {
            // Pas de liste, on envoie un payload vide pour que les outils se réinitialisent
        } else {
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

        // Envoi de l'événement au cerveau
        buildCustomEventForElement(document, 'cerveau:event', true, true, { type: 'ui:tab.context-changed', source: this.nomControleur, payload: payload, timestamp: Date.now() });
        console.log(this.nomControleur + " - Événement 'ui:tab.context-changed' envoyé au cerveau avec le payload:", payload);
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

        // --- MODIFICATION : Utiliser le nouveau spinner stylisé ---
        content.innerHTML = '<div class="spinner-container"><div class="custom-spinner"></div></div>';
        // -------------------------------------------------------

        try {
            // MODIFICATION CLÉ : On attend (await) la réponse du fetch
            const response = await fetch(tabElement.dataset.collectionUrl);
            if (!response.ok) throw new Error('Échec du chargement des données.');
            // Le contenu du spinner est remplacé SEULEMENT APRES la fin du chargement
            content.innerHTML = await response.text();
        } catch (error) {
            console.error("Erreur de chargement du contenu de l'onglet:", error);
            content.innerHTML = `<div class="alert alert-danger m-3">${error.message}</div>`;
        }
        return content;
    }
}