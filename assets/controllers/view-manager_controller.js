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
     * @property {HTMLTemplateElement} collectionTabTemplateTarget - Le template pour un onglet de collection.
     */
    static targets = ["tabsContainer", "tabContentContainer", "display", "collectionTabTemplate"];
    
    static values = {
        idEntreprise: Number,
        idInvite: Number,
        // NOUVEAU : Pour stocker l'état de chargement d'un onglet
        isLoading: Boolean 
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        console.log("ViewManager connecté avec idEntreprise:", this.idEntrepriseValue, "et idInvite:", this.idInviteValue);
        this.isLoadingValue = false; // Initialisation de l'état de chargement
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

        // NOUVEAU : On notifie immédiatement le cerveau que le contexte initial est l'onglet principal.
        // Ceci est crucial pour que le cerveau connaisse l'onglet actif dès le chargement.
        this.notifyCerveau('ui:tab.context-changed', {
            tabId: this.activeTabId,
            tabName: 'Principal', // Le nom par défaut
            parentId: null
        });

        this.boundHandleSelection = this.handleSelection.bind(this);
        this.boundHandleDisplayUpdate = this.handleDisplayUpdate.bind(this); // NOUVEAU
        // NOUVEAU : Écouteur pour la réponse du cerveau avec le contenu de l'onglet
        this.boundHandleTabContentLoaded = this.handleTabContentLoaded.bind(this);

        document.addEventListener('app:context.changed', this.boundHandleSelection); // CORRIGÉ : On écoute le nouvel événement de contexte global.
        document.addEventListener('app:display.update', this.boundHandleDisplayUpdate); // NOUVEAU
        document.addEventListener('view-manager:tab-content.loaded', this.boundHandleTabContentLoaded); // NOUVEAU
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleSelection); // CORRIGÉ : On supprime l'écouteur pour le bon événement.
        document.removeEventListener('app:display.update', this.boundHandleDisplayUpdate); // NOUVEAU
        document.removeEventListener('view-manager:tab-content.loaded', this.boundHandleTabContentLoaded); // NOUVEAU
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
        // RÔLE : Cette fonction est le "constructeur d'onglets de collection".
        // Elle ne s'exécute que si une sélection a lieu sur l'onglet principal.
        if (this.activeTabId !== 'principal') {
            return;
        }

        const selectos = event.detail.selection || [];
        const entities = selectos.map(s => s.entity);
        const canvas = selectos.length > 0 ? selectos[0].entityCanvas : null;
        const entityType = selectos.length > 0 ? selectos[0].entityType : null;

        const isSingleSelection = entities && entities.length === 1;
        const newParentId = isSingleSelection ? entities[0].id : null;

        // OPTIMISATION : Si l'ID du parent sélectionné n'a pas changé, il n'y a rien à faire.
        // Cela évite de redessiner les onglets inutilement.
        if (newParentId === this.collectionTabsParentId) return;

        // On met à jour l'état du parent et on reconstruit les onglets.
        this.collectionTabsParentId = newParentId;
        this.parentEntityCanvas = isSingleSelection ? canvas : null;
        this.parentEntityType = isSingleSelection ? entityType : null;

        this._removeCollectionTabs();

        if (isSingleSelection && canvas) {
            const collections = this._findCollectionsInCanvas(canvas);
            collections.forEach(collectionInfo => this._createTab(collectionInfo, entities[0], entityType));
        }
    }

    /**
     * Gère le clic sur un onglet pour changer de vue.
     * @param {MouseEvent} event
     */
    async switchTab(event) {
        if (this.isLoadingValue) {
            console.warn(`[${this.nomControleur}] Tentative de changement d'onglet pendant un chargement. Action ignorée.`);
            return;
        }
        event.preventDefault();
        const clickedTab = event.currentTarget;
        const newTabId = clickedTab.dataset.tabId;
        if (newTabId === this.activeTabId) return;
        this.isLoadingValue = true; // Verrouille le changement d'onglet

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

        let newContent = this.tabContentContainerTarget.querySelector(`#${this.activeTabId}`);

        // Si l'onglet cliqué est l'onglet principal, on se contente de l'afficher.
        if (newTabId === 'principal') {
            console.log(`[${++window.logSequence}] [${this.nomControleur}] - switchTab - Affichage de l'onglet principal.`);
            newContent.style.display = 'block';
            this.isLoadingValue = false;
            // Le contenu est déjà là, on peut notifier le changement de contexte immédiatement.
            this.notifyCerveau('ui:tab.context-changed', {
                tabId: this.activeTabId,
                tabName: clickedTab.textContent,
                parentId: this.collectionTabsParentId,
            });
        } else {
            // Pour tout autre onglet (collection), on force le rechargement du contenu.
            console.log(`[${++window.logSequence}] [${this.nomControleur}] - switchTab - Rechargement forcé pour l'onglet '${newTabId}'.`);
            const { tabId, collectionUrl } = clickedTab.dataset;

            if (newContent && collectionUrl) {
                newContent.style.display = 'block'; // On le rend visible
                newContent.innerHTML = this._getListSkeletonHtml(); // On y met un squelette de chargement
                // On notifie le cerveau pour qu'il fasse le fetch, en passant le nom de l'onglet pour plus tard.
                this.notifyCerveau('app:tab-content.load-request', { 
                    tabId, 
                    url: collectionUrl, 
                    tabName: clickedTab.textContent 
                });
            } else {
                console.error(`[${this.nomControleur}] - Impossible de recharger l'onglet: conteneur ou URL manquant.`, { tabId, hasContent: !!newContent, hasUrl: !!collectionUrl });
                this.isLoadingValue = false; // On libère le verrou en cas d'erreur.
            }
        }

        // La notification 'ui:tab.context-changed' est maintenant déplacée.
        // - Pour l'onglet 'principal', elle est envoyée immédiatement ci-dessus.
        // - Pour les onglets de collection, elle sera envoyée dans 'handleTabContentLoaded' une fois le contenu chargé.
    }
    
    /**
     * NOUVEAU : Gère la réception du contenu HTML de l'onglet chargé par le cerveau.
     * @param {CustomEvent} event 
     */
    handleTabContentLoaded(event) {
        const { tabId, html, tabName } = event.detail;
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - handleTabContentLoaded - Code: 100 - Données:`, { tabId, html, tabName });
        const contentContainer = this.tabContentContainerTarget.querySelector(`#${tabId}`);
        if (contentContainer) {
            contentContainer.innerHTML = html;
            // Le contenu est chargé, on peut maintenant notifier le cerveau du changement de contexte.
            this.notifyCerveau('ui:tab.context-changed', {
                tabId: tabId,
                tabName: tabName,
                parentId: this.collectionTabsParentId,
            });
        }
        this.isLoadingValue = false; // Libère le verrou une fois le contenu injecté
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
        const tabId = `collection-${collectionInfo.code}-for-${parentEntity.id}`;
        const tab = document.createElement('button');
        tab.className = 'list-tab';

        // CORRECTION : On passe l'ID comme paramètre de requête pour être compatible
        // avec le typage strict (int) du contrôleur Symfony.
        // L'URL passe de /api/123/contacts/generic à /api/contacts/generic?id=123
        const collectionUrl = `/admin/${parentEntityType.toLowerCase()}/api/${collectionInfo.code}/generic?id=${parentEntity.id}`;

        Object.assign(tab.dataset, {
            tabId: tabId,
            tabType: 'collection',
            action: 'click->view-manager#switchTab',
            collectionUrl: collectionUrl, // L'URL est maintenant une donnée pour le cerveau
            entityName: collectionInfo.targetEntity
        });
        tab.textContent = collectionInfo.intitule;
        this.tabsContainerTarget.appendChild(tab);

        // NOUVEAU : On prépare le conteneur de contenu en clonant le template
        const contentContainer = this.collectionTabTemplateTarget.content.cloneNode(true).firstElementChild;
        contentContainer.id = tabId; // On lui donne l'ID correspondant à l'onglet
        contentContainer.style.display = 'none'; // On le cache par défaut
        this.tabContentContainerTarget.appendChild(contentContainer);
    }

    /**
     * NOUVEAU: Affiche un squelette de chargement pour une liste.
     * @returns {string} Le HTML du squelette.
     * @private
     */
    _getListSkeletonHtml() {
        // On ne peut pas connaître le nombre exact de colonnes à ce stade,
        // on utilise donc une approximation (ex: 3) pour le visuel.
        const columnCount = 3;
        let skeletonTbody = '';
        for (let i = 0; i < 8; i++) { // 8 lignes pour un bon effet visuel
            skeletonTbody += `
                <tr>
                    ${'<td><div class="skeleton-row"></div></td>'.repeat(columnCount)}
                </tr>
            `;
        }

        // On enveloppe le corps du tableau dans la structure de base pour la cohérence.
        return `
            <div class="table-scroll-wrapper flex-grow-1 h-100">
                <table class="table table-hover table-sm table-enhanced">
                    <tbody>
                        ${skeletonTbody}
                    </tbody>
                </table>
            </div>
        `;
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