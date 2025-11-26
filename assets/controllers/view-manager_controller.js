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

        // MODIFIÉ : On ne notifie que le contexte global (ID entreprise/invité),
        // sans le formCanvas. C'est le list-manager qui sera responsable de notifier son propre contexte de formulaire.
        this.notifyCerveau('app:context.initialized', {
            idEntreprise: this.idEntrepriseValue,
            idInvite: this.idInviteValue
        });

        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleDisplayUpdate = this.handleDisplayUpdate.bind(this); // NOUVEAU

        document.addEventListener('app:context.changed', this.boundHandleSelection); // CORRIGÉ : On écoute le nouvel événement de contexte global.
        document.addEventListener('app:display.update', this.boundHandleDisplayUpdate); // NOUVEAU
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleSelection); // CORRIGÉ : On supprime l'écouteur pour le bon événement.
        document.removeEventListener('app:display.update', this.boundHandleDisplayUpdate); // NOUVEAU
    }


    /**
     * NOUVEAU : Met à jour la barre de statut principale avec le contenu HTML fourni par le cerveau.
     * @param {CustomEvent} event - L'événement `app:display.update`.
     */
    handleDisplayUpdate(event) {
        const { html } = event.detail;
        if (!this.hasDisplayTarget || !html) return;

        this.displayTarget.innerHTML = html;
    }

    /**
     * Gère la mise à jour de la sélection pour créer/supprimer les onglets de collection.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleSelection(event) {
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - handleSelection - Code: 100 - Données:`, event.detail);
        // CORRECTION : Le payload est maintenant un objet. On extrait la propriété 'selection'.
        const selectos = event.detail.selection || [];


        const selection = selectos.map(s => s.id);
        const entities = selectos.map(s => s.entity);
        const canvas = selectos.length > 0 ? selectos[0].entityCanvas : null;
        const entityType = selectos.length > 0 ? selectos[0].entityType : null;
        // La mise à jour du display est maintenant gérée par le cerveau via handleDisplayUpdate

        const isSingleSelection = entities && entities.length === 1;
        const newParentId = isSingleSelection ? entities[0].id : null;

        if (this.activeTabId !== 'principal' || (event.detail.originatorId && event.detail.originatorId !== 'principal')) {
            // this._saveState(); // Désactivé: La sauvegarde d'état est gérée globalement ou non souhaitée pour l'instant.
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
        // this._saveState(); // Désactivé: La sauvegarde d'état est gérée globalement par le workspace-manager ou non souhaitée pour l'instant.
    }

    /**
     * Gère le clic sur un onglet pour changer de vue.
     * @param {MouseEvent} event
     */
    async switchTab(event) {
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - switchTab - Code: 100 - Données:`, { tabId: event.currentTarget.dataset });
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

        this.notifyCerveau('ui:tab.context-changed', {
            tabId: this.activeTabId,
            parentId: this.collectionTabsParentId,
        });
    }

    /**
     * Notifie le Cerveau du contexte actuel de l'onglet actif, en l'enrichissant
     * avec les informations sur les attributs numériques de la liste affichée.
     * @param {object} selectionState - L'état de sélection à diffuser.
     * @fires cerveau:event
     * @private
     */
    notifyCerveau(type, payload = {}) {
        console.log(`[${++window.logSequence}] ${this.nomControleur} - Notification du Cerveau sur le changement de contexte.`);

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
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - _loadTabContent - Code: 100 - Données:`, { url: tabElement.dataset.collectionUrl });
        const content = document.createElement('div');
        Object.assign(content.dataset, {
            contentId: tabElement.dataset.tabId,
            entityName: tabElement.dataset.entityName,
            canAdd: 'true'
        });
        content.style.display = 'block';
        content.innerHTML = `
            <div class="table-scroll-wrapper flex-grow-1">
                <table class="table table-hover table-sm table-enhanced">
                    <tbody>
                        ${'<tr><td><div class="skeleton-row"></div></td></tr>'.repeat(6)}
                    </tbody>
                </table>
            </div>
        `;

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