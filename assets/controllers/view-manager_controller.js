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
     * @property {HTMLElement} selectionContextTarget - La pastille d'entête rappelant l'élément sélectionné (Guidage).
     */
    static targets = ["tabsContainer", "tabContentContainer", "display", "collectionTabTemplate", "selectionContext"];
    
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
         * @property {HTMLElement[]} _tabQueue - File FIFO des demandes d'activation en attente.
         * @private
         */
        this._tabQueue = [];
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

        // Détecter l'onglet workspace parent pour isoler les événements
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;
        console.log(`[VIEW-MANAGER] connecté — idEntreprise: ${this.idEntrepriseValue}, idInvite: ${this.idInviteValue}, workspaceTabId: ${this.workspaceTabId}`);

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
        this.boundHandleDisplayUpdate = this.handleDisplayUpdate.bind(this);
        this.boundHandleTabContentLoaded = this.handleTabContentLoaded.bind(this);
        this.boundHandleTabBecameActive = this.handleTabBecameActive.bind(this);
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);

        document.addEventListener('app:context.changed', this.boundHandleSelection);
        document.addEventListener('app:display.update', this.boundHandleDisplayUpdate);
        document.addEventListener('view-manager:tab-content.loaded', this.boundHandleTabContentLoaded);
        document.addEventListener('workspace:tab-became-active', this.boundHandleTabBecameActive);

        // Affordance de débordement de la barre d'onglets : recalculée quand la
        // barre change de taille (le scroll est couvert par data-action côté template).
        this.tabsOverflowObserver = new ResizeObserver(() => this.updateTabsOverflow());
        this.tabsOverflowObserver.observe(this.tabsContainerTarget);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleSelection);
        document.removeEventListener('app:display.update', this.boundHandleDisplayUpdate);
        document.removeEventListener('view-manager:tab-content.loaded', this.boundHandleTabContentLoaded);
        document.removeEventListener('workspace:tab-became-active', this.boundHandleTabBecameActive);
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
        this.tabsOverflowObserver.disconnect();
    }

    /**
     * Injecte l'icône d'un onglet de collection quand le cerveau la fournit
     * (réponse à ui:icon.request émis par _createTab).
     * @param {CustomEvent} event - L'événement `app:icon.loaded`.
     */
    handleIconLoaded(event) {
        const { html, requesterId } = event.detail;
        if (!requesterId || !String(requesterId).startsWith('tab-icon-') || !html) return;
        const holder = this.tabsContainerTarget.querySelector(`[id="${requesterId}"]`);
        if (!holder) return;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        const svg = tempDiv.querySelector('svg');
        if (svg) {
            svg.setAttribute('aria-hidden', 'true');
            holder.replaceChildren(svg);
        }
    }


    /**
     * NOUVEAU : Met à jour la barre de statut principale avec le contenu HTML fourni par le cerveau.
     * @param {CustomEvent} event - L'événement `app:display.update`.
     */
    handleDisplayUpdate(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { html } = event.detail;
        if (!this.hasDisplayTarget || !html) return;

        this.displayTargets.forEach(target => { target.innerHTML = html; });
    }

    /**
     * Gère la mise à jour de la sélection pour créer/supprimer les onglets de collection.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleSelection(event) {
        // Ignorer les événements destinés à un autre onglet workspace
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
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

        // Guidage : la pastille d'entête rappelle QUEL élément a ouvert les
        // onglets contextuels ; l'affordance de débordement suit le nombre d'onglets.
        this._updateSelectionContext(isSingleSelection && canvas ? entities[0] : null, canvas);
        this.updateTabsOverflow();
    }

    /**
     * Gère le clic sur un onglet : aiguille vers _activateTab ou met en file d'attente.
     * @param {MouseEvent} event
     */
    switchTab(event) {
        event.preventDefault();
        const clickedTab = event.currentTarget;
        if (clickedTab.dataset.tabId === this.activeTabId) return;

        if (this.isLoadingValue) {
            this._tabQueue.push(clickedTab);
            console.log(`[${this.nomControleur}] Onglet '${clickedTab.dataset.tabId}' mis en file d'attente (${this._tabQueue.length} en attente).`);
            return;
        }

        this._activateTab(clickedTab);
    }

    /**
     * Active un onglet : verrouille, affiche le contenu ou déclenche le fetch.
     * Appelé par switchTab ou _processNextQueuedTab.
     * @param {HTMLElement} clickedTab - Le bouton d'onglet à activer.
     * @private
     */
    _activateTab(clickedTab) {
        const newTabId = clickedTab.dataset.tabId;

        // L'onglet a pu être supprimé du DOM (ex: _removeCollectionTabs) avant d'être dépilé.
        if (!this.tabsContainerTarget.contains(clickedTab)) {
            console.warn(`[${this.nomControleur}] L'onglet '${newTabId}' n'est plus dans le DOM. Ignoré.`);
            this._processNextQueuedTab();
            return;
        }
        // L'onglet a pu devenir actif entre-temps (ex: un autre chemin de code).
        if (newTabId === this.activeTabId) {
            this._processNextQueuedTab();
            return;
        }

        this.isLoadingValue = true;

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
        // Synchronise aria-selected + roving tabindex (pattern ARIA « Tabs ») :
        // seul l'onglet actif est dans l'ordre de tabulation, les flèches
        // gauche/droite parcourent les autres (handleTabKeydown).
        this.tabsContainerTarget.querySelectorAll('[role="tab"]').forEach(tab => {
            tab.setAttribute('aria-selected', tab === clickedTab ? 'true' : 'false');
            tab.setAttribute('tabindex', tab === clickedTab ? '0' : '-1');
        });

        const newContent = this.tabContentContainerTarget.querySelector(`#${this.activeTabId}`);

        if (newTabId === 'principal') {
            console.log(`[${++window.logSequence}] [${this.nomControleur}] - _activateTab - Affichage de l'onglet principal.`);
            newContent.style.display = 'block';
            this.isLoadingValue = false;
            this.notifyCerveau('ui:tab.context-changed', {
                tabId: this.activeTabId,
                // trim : le libellé peut être précédé d'une icône (nœuds/espaces).
                tabName: clickedTab.textContent.trim(),
                parentId: this.collectionTabsParentId,
            });
            this._processNextQueuedTab();
        } else {
            const { tabId, collectionUrl } = clickedTab.dataset;
            console.log(`[${++window.logSequence}] [VIEW-MANAGER:${this.workspaceTabId}] _activateTab → fetch tabId=${tabId} url=${collectionUrl}`);

            // Contextualisation IMMÉDIATE : le cerveau bascule son onglet actif dès
            // l'activation (et non après le chargement du contenu) — il restaure
            // l'état mémorisé de l'onglet ou réinitialise le chrome (toolbar,
            // totaux, recherche) pendant le chargement. Sans cela, les barres
            // continuaient de refléter la sélection de l'onglet précédent.
            this.notifyCerveau('ui:tab.context-changed', {
                tabId: newTabId,
                tabName: clickedTab.textContent.trim(),
                parentId: this.collectionTabsParentId,
            });

            if (newContent && newContent.dataset.loaded === 'true') {
                // PERSISTANCE : le contenu de cet onglet a déjà été chargé → on le
                // ré-affiche tel quel (page courante, défilement, lignes cochées
                // conservés), exactement comme l'onglet principal. Le cerveau vient
                // de rediffuser l'état mémorisé (toolbar/totaux/recherche) ; les
                // données se rafraîchissent par les canaux existants : bouton
                // « Actualiser », recherche/pagination, et rafraîchissements
                // automatiques après création/modification/suppression. Les onglets
                // sont détruits quand la sélection parente change (_removeCollectionTabs),
                // donc jamais de contenu d'un autre parent.
                newContent.style.display = 'block';
                this.isLoadingValue = false;
                this._processNextQueuedTab();
            } else if (newContent && collectionUrl) {
                newContent.style.display = 'block';
                newContent.innerHTML = this._getListSkeletonHtml();
                this.notifyCerveau('app:tab-content.load-request', {
                    tabId,
                    url: collectionUrl,
                    tabName: clickedTab.textContent.trim(),
                    workspaceTabId: this.workspaceTabId
                });
                // isLoadingValue reste true ; libéré dans handleTabContentLoaded
            } else {
                console.error(`[${this.nomControleur}] - Impossible de recharger l'onglet: conteneur ou URL manquant.`, { tabId, hasContent: !!newContent, hasUrl: !!collectionUrl });
                this.isLoadingValue = false;
                this._processNextQueuedTab();
            }
        }
    }

    /**
     * Dépile le prochain onglet en attente et l'active.
     * @private
     */
    _processNextQueuedTab() {
        if (this._tabQueue.length === 0) return;
        this._activateTab(this._tabQueue.shift());
    }
    
    /**
     * NOUVEAU : Gère la réception du contenu HTML de l'onglet chargé par le cerveau.
     * @param {CustomEvent} event 
     */
    handleTabContentLoaded(event) {
        const { tabId, html, tabName, workspaceTabId: evtWsId } = event.detail;
        console.log(`[${++window.logSequence}] [VIEW-MANAGER:${this.workspaceTabId}] handleTabContentLoaded — event.tabId=${tabId} | this.activeTabId=${this.activeTabId} | event.wsId=${evtWsId}`);
        if (this.workspaceTabId && evtWsId && evtWsId !== this.workspaceTabId) {
            console.log(`  → ignoré (workspaceTabId mismatch: ${evtWsId} !== ${this.workspaceTabId})`);
            return;
        }
        // Guard : réponse obsolète ou cross-instance — le tabId ne correspond pas à l'onglet en attente
        if (tabId !== this.activeTabId) {
            console.log(`  → ignoré (stale guard: tabId=${tabId} !== activeTabId=${this.activeTabId})`);
            return;
        }
        const contentContainer = this.tabContentContainerTarget.querySelector(`#${tabId}`);
        if (contentContainer) {
            contentContainer.innerHTML = html;
            // PERSISTANCE : marque le contenu comme chargé — les prochaines activations
            // l'afficheront sans re-fetch. En cas d'échec (le cerveau signale `failed`),
            // on ne marque pas : la prochaine activation retentera le chargement.
            if (event.detail.failed) {
                delete contentContainer.dataset.loaded;
            } else {
                contentContainer.dataset.loaded = 'true';
            }
            // Le contenu est chargé, on peut maintenant notifier le cerveau du changement de contexte.
            this.notifyCerveau('ui:tab.context-changed', {
                tabId: tabId,
                tabName: tabName,
                parentId: this.collectionTabsParentId,
            });
        }
        this.isLoadingValue = false;
        this._processNextQueuedTab();
    }

    /**
     * Quand l'onglet workspace parent redevient actif (après un switch), re-publie le
     * contexte courant au Cerveau pour que la toolbar et les totaux se synchronisent.
     */
    handleTabBecameActive(event) {
        if (!this.workspaceTabId || event.detail.workspaceTabId !== this.workspaceTabId) return;
        // Récupère le texte réel du bouton d'onglet actif (ex: "Contacts", "Principal")
        // plutôt que l'ID technique (ex: "collection-contacts-for-1").
        const activeTabBtn = this.tabsContainerTarget.querySelector(`[data-tab-id="${this.activeTabId}"]`);
        const tabName = activeTabBtn
            ? activeTabBtn.textContent.trim()
            : (this.activeTabId === 'principal' ? 'Principal' : this.activeTabId);
        this.notifyCerveau('ui:tab.context-changed', {
            tabId: this.activeTabId,
            tabName: tabName,
            parentId: this.collectionTabsParentId
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
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur || 'Unknown', payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }

    /**
     * Navigation clavier du tablist (pattern ARIA « Tabs », activation manuelle) :
     * flèches gauche/droite (cycliques), Début/Fin déplacent le focus ; Entrée/Espace
     * activent (comportement natif du <button>, rien à câbler).
     * @param {KeyboardEvent} event
     */
    handleTabKeydown(event) {
        const moves = { ArrowLeft: -1, ArrowRight: 1, Home: 0, End: 0 };
        if (!(event.key in moves)) return;

        const tabs = [...this.tabsContainerTarget.querySelectorAll('[role="tab"]')];
        const current = tabs.indexOf(document.activeElement);
        if (current === -1) return;

        event.preventDefault();
        let next;
        if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else next = (current + moves[event.key] + tabs.length) % tabs.length;
        tabs[next].focus();
    }

    /**
     * Affordance de débordement de la barre d'onglets : pose les classes
     * `is-overflowing-left/right` (fondu CSS) selon la position de défilement.
     * Appelé au scroll (data-action), au redimensionnement (ResizeObserver)
     * et après chaque création/suppression d'onglets.
     */
    updateTabsOverflow() {
        const el = this.tabsContainerTarget;
        const canScroll = el.scrollWidth > el.clientWidth + 1;
        el.classList.toggle('is-overflowing-left', canScroll && el.scrollLeft > 1);
        el.classList.toggle('is-overflowing-right', canScroll && el.scrollLeft + el.clientWidth < el.scrollWidth - 1);
    }

    /**
     * Guidage : affiche dans l'entête le libellé de l'élément sélectionné dont
     * les onglets contextuels sont ouverts ; masque la pastille sans sélection.
     * @param {object|null} entity - L'entité sélectionnée (ou null).
     * @param {object|null} canvas - Son canvas, pour trouver l'attribut principal.
     * @private
     */
    _updateSelectionContext(entity, canvas) {
        if (!this.hasSelectionContextTarget) return;
        if (!entity) {
            this.selectionContextTarget.textContent = '';
            this.selectionContextTarget.classList.add('d-none');
            return;
        }
        // Même résolution de l'attribut principal que workspace-manager (_buildGenericDescription).
        const mainField = canvas?.liste?.find(attr => attr.col_principale)?.texte_principal?.attribut_code || 'nom';
        const label = entity[mainField] || entity.nom || entity.libelle || entity.intitule || `#${entity.id}`;
        this.selectionContextTarget.textContent = label;
        this.selectionContextTarget.title = label; // texte complet si la pastille tronque (ellipsis)
        this.selectionContextTarget.classList.remove('d-none');
    }

    // --- Méthodes privées d'aide ---

    _findCollectionsInCanvas(canvas) {
        if (!canvas || !canvas.liste) return [];
        return canvas.liste.filter(attr => attr.type === 'Collection');
    }

    _removeCollectionTabs() {
        this.tabsContainerTarget.querySelectorAll('[data-tab-type="collection"]').forEach(tab => {
            // CORRECTION : les panneaux de contenu sont identifiés par leur `id`
            // (posé dans _createTab), PAS par data-content-id — l'ancien sélecteur
            // ne matchait jamais et les panneaux s'accumulaient dans le DOM.
            // querySelectorAll : purge aussi d'éventuels doublons hérités.
            this.tabContentContainerTarget
                .querySelectorAll(`[id="${tab.dataset.tabId}"]`)
                .forEach(content => content.remove());
            tab.remove();
        });
    }

    _createTab(collectionInfo, parentEntity, parentEntityType) {
        const tabId = `collection-${collectionInfo.code}-for-${parentEntity.id}`;
        const tab = document.createElement('button');
        tab.className = 'list-tab';

        const collectionUrl = '/admin/' + parentEntityType.toLowerCase() + '/api/' + parentEntity.id + '/' + collectionInfo.code + '/generic';
        
        Object.assign(tab.dataset, {
            tabId: tabId,
            tabType: 'collection',
            action: 'click->view-manager#switchTab',
            collectionUrl: collectionUrl, // L'URL est maintenant une donnée pour le cerveau
            entityName: collectionInfo.targetEntity
        });
        // Signifiance : chaque onglet porte l'icône de son entité cible (alias hérité
        // via EntityCanvasProvider) devant son libellé. L'icône est décorative
        // (aria-hidden), injectée par le circuit d'icônes du cerveau (handleIconLoaded).
        if (collectionInfo.icone) {
            const iconHolder = document.createElement('span');
            iconHolder.className = 'list-tab-icon';
            iconHolder.setAttribute('aria-hidden', 'true');
            iconHolder.id = `tab-icon-${tabId}`;
            tab.appendChild(iconHolder);
            this.notifyCerveau('ui:icon.request', { iconName: collectionInfo.icone, iconSize: 18, requesterId: iconHolder.id });
        }
        tab.appendChild(document.createTextNode(collectionInfo.intitule));
        // ARIA : pattern tablist/tab/tabpanel (WCAG 4.1.2)
        tab.setAttribute('role', 'tab');
        tab.setAttribute('id', `tab-${tabId}`);
        tab.setAttribute('aria-selected', 'false');
        tab.setAttribute('aria-controls', tabId);
        // Roving tabindex : seul l'onglet actif est tabbable, les flèches font le reste.
        tab.setAttribute('tabindex', '-1');
        this.tabsContainerTarget.appendChild(tab);

        // On prépare le conteneur de contenu en clonant le template
        const contentContainer = this.collectionTabTemplateTarget.content.cloneNode(true).firstElementChild;
        contentContainer.id = tabId;
        contentContainer.style.display = 'none';
        contentContainer.setAttribute('role', 'tabpanel');
        contentContainer.setAttribute('aria-labelledby', `tab-${tabId}`);
        contentContainer.setAttribute('tabindex', '0');
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