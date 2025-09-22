// assets/controllers/list-tabs-controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_DATA_BASE_DONNEES_LOADED } from './base_controller.js';

const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

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
        // --- CORRECTION : Écouter l'événement qui signale la fin du chargement d'une liste ---
        this.boundDispatchContextChangeEvent = this.dispatchContextChangeEvent.bind(this);

        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.addEventListener('list-status:notify', this.boundHandleStatusNotify);
        document.addEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundDispatchContextChangeEvent);
        document.addEventListener('totals-bar:request-context', this.boundDispatchContextChangeEvent); // --- CORRECTION : Répondre à la demande de la barre des totaux

        this.dispatchContextChangeEvent();
    }

    disconnect() {
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
        document.removeEventListener('list-status:notify', this.boundHandleStatusNotify);
        document.removeEventListener(EVEN_DATA_BASE_DONNEES_LOADED, this.boundDispatchContextChangeEvent);
        document.removeEventListener('totals-bar:request-context', this.boundDispatchContextChangeEvent); // --- CORRECTION : Nettoyer l'écouteur
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
        setTimeout(() => this.dispatchContextChangeEvent(), 50);
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
    dispatchContextChangeEvent() { // [1]
        console.log(this.nomControleur + " - Tentative d'envoi de l'événement context-changed.");
        const activeContent = this.tabContentContainerTarget.querySelector(`[data-content-id="${this.activeTabId}"]`);

        // --- CORRECTION : Gérer le cas où il n'y a pas de contenu actif ---
        // S'il n'y a pas de contenu ou de contrôleur de liste, on envoie un événement vide
        // pour forcer la barre des totaux à se masquer.
        const listControllerElement = activeContent ? activeContent.querySelector('[data-controller~="liste-principale"]') : null;
        if (!listControllerElement) {
            this.element.dispatchEvent(new CustomEvent(EVT_CONTEXT_CHANGED, {
                bubbles: true,
                detail: { numericAttributes: null, numericData: null } // [2]
            }));
            return;
        }

        // --- MODIFICATION ---
        // On lit l'attribut avec le nom correct et cohérent : 'numericAttributes'.
        const numericData = JSON.parse(listControllerElement.dataset.listePrincipaleNumericAttributesValue || '{}');
        const firstItemId = Object.keys(numericData)[0]; // [3]
        let numericAttributesOptions = null;
        let finalNumericData = null;

        // --- CORRECTION : On ne construit les options que si on a des données valides ---
        if (firstItemId && numericData[firstItemId] && Object.keys(numericData[firstItemId]).length > 0) {
            numericAttributesOptions = {};
            finalNumericData = numericData;
            for (const key in finalNumericData[firstItemId]) {
                numericAttributesOptions[key] = finalNumericData[firstItemId][key].description;
            }
        }

        this.element.dispatchEvent(new CustomEvent(EVT_CONTEXT_CHANGED, {
            bubbles: true,
            detail: {
                // On renomme ici pour plus de clarté dans l'événement, mais la source est correcte.
                numericAttributes: numericAttributesOptions,
                numericData: finalNumericData
            }
        }));
        console.log(this.nomControleur + " - Envoi de l'événement avec les détails:", numericAttributesOptions, finalNumericData);
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