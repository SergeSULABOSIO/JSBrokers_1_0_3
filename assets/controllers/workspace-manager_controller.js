import { Controller } from '@hotwired/stimulus';

/**
 * @class WorkspaceManagerController
 * @extends Controller
 * @description Gère l'espace de travail principal de l'application, y compris la navigation, le chargement dynamique des composants, la gestion des onglets et la persistance de l'état.
 */
export default class extends Controller {
    static targets = [
        "progressBar",
        "contentZone",
        "workspace",
        "rubriquesTemplate",
        "dashboardItem",
        "visualizationColumn",
        "tabContainer",
        "tabContentContainer",
        "tabTemplate",
        "tabContentTemplate",
        "item", 
        "searchInput", 
        "noResultsMessage"
    ];


    static values = {
        idEntreprise: Number,
        idInvite: Number
    }

    // Cible pour l'élément de menu actuellement actif
    activeNavItem = null;
    activeRubriqueItem = null;

    connect() {
        this.nomControleur = "WorkspaceManager";
        this.restoreLastState();

        this.boundOpenTabInVisualization = this.openTabInVisualization.bind(this);
        document.addEventListener('app:liste-element:openned', this.boundOpenTabInVisualization);

        // NOUVEAU : Écoute la réponse du Cerveau pour afficher le composant chargé.
        this.boundHandleComponentLoaded = this.handleComponentLoaded.bind(this);
        document.addEventListener('workspace:component.loaded', this.boundHandleComponentLoaded);

        // NOUVEAU : Écoute l'ordre du cerveau pour revenir au tableau de bord.
        this.boundLoadDefault = this.loadDefaultComponent.bind(this);
        document.addEventListener('app:workspace.load-default', this.boundLoadDefault);

        // --- NOUVEAU : Gestion de la barre de progression pour l'actualisation de la liste ---
        // Écoute les ordres du Cerveau pour afficher/masquer la barre de progression.
        this.boundHandleLoadingStart = this.handleLoadingStart.bind(this);
        document.addEventListener('app:loading.start', this.boundHandleLoadingStart);

        this.boundHandleLoadingStop = this.handleLoadingStop.bind(this);
        document.addEventListener('app:loading.stop', this.boundHandleLoadingStop);
    }

    /**
     * Restaure la dernière vue active en se basant sur les informations stockées en session.
     */
    restoreLastState() {
        const savedStateJSON = sessionStorage.getItem(`lastActiveState_${this.idEntrepriseValue}`);
        if (!savedStateJSON) {
            this.loadDefaultComponent();
            return;
        }

        const savedState = JSON.parse(savedStateJSON);

        // Cas 1 : C'est une rubrique (elle a un groupe parent)
        if (savedState.group) {
            const groupElement = this.element.querySelector(`[data-workspace-manager-group-name-param='${savedState.group}']`);
            if (groupElement) {
                // On clique d'abord sur le groupe pour afficher les rubriques
                groupElement.click();

                requestAnimationFrame(() => {
                    // On cherche la rubrique en utilisant à la fois le composant ET le nom de l'entité.
                    const selector = `[data-workspace-manager-component-name-param='${savedState.component}'][data-workspace-manager-entity-name-param='${savedState.entity}']`;
                    const rubriqueElement = this.contentZoneTarget.querySelector(selector);

                    if (rubriqueElement) {
                        this.loadComponent({ currentTarget: rubriqueElement }, { isRestoration: true });
                    } else {
                        console.error("WorkspaceManager: Rubrique non trouvée pour la restauration.", savedState);
                        this.loadDefaultComponent(); // Sécurité si la rubrique n'est pas trouvée
                    }
                });
            } else {
                this.loadDefaultComponent(); // Sécurité si le groupe n'est pas trouvé
            }
        }
        // Cas 2 : C'est un élément principal (Tableau de bord, Paramètres)
        else if (savedState.component) {
            const elementToClick = this.element.querySelector(`[data-workspace-manager-component-name-param='${savedState.component}']`);
            if (elementToClick) {
                this.loadComponent({ currentTarget: elementToClick }, { isRestoration: true });
            } else {
                this.loadDefaultComponent();
            }
        }
        // Cas par défaut
        else {
            this.loadDefaultComponent();
        }
    }



    /**
     * Charge le composant par défaut (généralement le tableau de bord).
     * @private
     */
    loadDefaultComponent() {
        if (this.hasDashboardItemTarget) {
            this.dashboardItemTarget.click();
        }
    }

    disconnect() {
        document.removeEventListener('app:liste-element:openned', this.boundOpenTabInVisualization);
        document.removeEventListener('workspace:component.loaded', this.boundHandleComponentLoaded);
        document.removeEventListener('app:workspace.load-default', this.boundLoadDefault);
        document.removeEventListener('app:loading.start', this.boundHandleLoadingStart);
        document.removeEventListener('app:loading.stop', this.boundHandleLoadingStop);
    }

    /**
     * Donne le focus au champ de recherche de l'accordéon quand on clique sur la barre.
     */
    focusSearch(event) {
        // Empêche le focus si on clique sur un bouton
        if (event.target.tagName.toLowerCase() === 'button' || event.target.closest('button')) {
            return;
        }
        this.searchInputTarget.focus();


        // On cherche le contrôleur de l'accordéon et on lui demande de focus son input
        // const accordionController = this.application.getControllerForElementAndIdentifier(event.currentTarget.nextElementSibling, 'accordion');
        // console.log(this.nomControleur + " - FocusSearch - Code: 1986 - Data:", accordionController);
        // if (accordionController && accordionController.hasSearchInputTarget) {
        //     accordionController.searchInputTarget.focus();
        // }
    }



    /**
     * Gère la requête d'ouverture d'un élément dans la colonne de visualisation.
     * @param {CustomEvent} event
     */
    openTabInVisualization(event) {
        // On utilise requestAnimationFrame pour s'assurer que le navigateur a le temps
        // d'afficher la barre de progression (déclenchée par le Cerveau) avant
        // d'exécuter le code (potentiellement lourd) de création de l'onglet.
        requestAnimationFrame(() => {
            // Le Cerveau envoie l'objet "selecto" complet. On le déstructure pour en extraire les informations nécessaires.
            const { entity, entityType, entityCanvas } = event.detail;
    
            // Validation robuste des données reçues.
            if (!entity || typeof entity.id === 'undefined' || !entityType || !entityCanvas) {
                console.error("WorkspaceManager - Validation échouée : l'objet 'selecto' reçu est invalide ou incomplet.", event.detail);
                // On notifie le cerveau d'arrêter le chargement même en cas d'erreur.
                this.notifyCerveau('app:loading.stop', {});
                return;
            }
    
            // On vérifie si un onglet pour cette entité (même ID et même type) existe déjà.
            const existingTab = this.tabContainerTarget.querySelector(`[data-entity-id='${entity.id}'][data-entity-type='${entityType}']`);
            if (existingTab) {
                this.activateTab({ currentTarget: existingTab });
            } else {
                // On passe le 'entityCanvas' qui contient la structure correcte pour l'accordéon.
                this.createTab(entity, entityType, entityCanvas);
            }
    
            this.element.classList.add('visualization-visible');
            this.visualizationColumnTarget.style.display = 'flex';
    
            // NOUVEAU : Notifier le cerveau que l'onglet est ouvert pour qu'il puisse arrêter le chargement.
            this.notifyCerveau('app:tab.opened', { entityId: entity.id, entityType: entityType });
        });
    }


    /**
     * Crée un nouvel onglet et son contenu (un accordéon) dans la colonne de visualisation.
     * @param {object} entity
     * @param {string} entityType
     * @param {object} entityCanvas - La nouvelle structure avec "paramètres" et "liste"
     */
    createTab(entity, entityType, entityCanvas) {
        const tabElement = this.tabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabElement.dataset.entityId = entity.id;
        tabElement.dataset.entityType = entityType;

        const params = entityCanvas.parametres;
        tabElement.title = params.description;

        // On ne modifie plus l'icône, on laisse celle par défaut du template.
        tabElement.querySelector('[data-role="tab-title"]').textContent = `#${entity.id}`;

        // --- Création du contenu de l'onglet (accordéon) ---
        const contentElement = this.tabContentTemplateTarget.content.cloneNode(true).firstElementChild;
        const accordionContainer = contentElement.querySelector('.accordion');
        // --- MODIFICATION 3 : Utiliser entityCanvas.liste pour l'accordéon ---
        const accordionList = entityCanvas.liste; // 
        if (accordionList.length > 0) {
            accordionList.forEach(attribute => {
                const accordionItem = this.createAccordionItem(attribute, entity);
                accordionContainer.appendChild(accordionItem);
            });
        } else {
            accordionContainer.innerHTML = `<div class="p-4 text-muted">Aucun champ à afficher pour cet objet.</div>`;
        }
        // Lier le contenu à l'onglet via un ID unique
        const tabId = `tab-content-${entityType}-${entity.id}`;
        contentElement.id = tabId;
        tabElement.dataset.tabContentId = tabId;

        // Ajouter les nouveaux éléments au DOM
        this.tabContainerTarget.appendChild(tabElement);
        this.tabContentContainerTarget.appendChild(contentElement);

        // Activer le nouvel onglet créé
        this.activateTab({ currentTarget: tabElement });

        console.log(this.nomControleur + " - Onglet ouvert:", entity);
    }

    /**
     * Crée un item (une ligne) pour l'accordéon d'un onglet.
     * @param {object} attribute - La description de l'attribut depuis entityCanvas
     * @param {object} entity - L'objet de données
     * @returns {HTMLElement} L'élément DOM de l'item d'accordéon.
     */
    createAccordionItem(attribute, entity) {
        console.log(this.nomControleur + " - Données de l'attribut:", attribute);

        const item = document.createElement('div'); // Cet élément sera la target "item" pour le contrôleur accordion
        item.dataset.workspaceManagerTarget = "item"; // L'élément est une cible pour le contrôleur workspace-manager
        item.className = 'accordion-item';
        const title = document.createElement('div');
        title.className = 'accordion-title'; // Ensure this class is set
        title.dataset.action = 'click->workspace-manager#toggle'; // L'action appelle la méthode toggle de ce contrôleur
        title.innerHTML = `<span class="accordion-toggle">-</span> ${attribute.intitule}`;

        const content = document.createElement('div');
        content.className = 'accordion-content open';

        let contentValueElement; // NOUVEAU : Element qui va contenir la valeur et sur lequel on attachera le tooltip

        switch (attribute.type) {
            case 'Relation':
                const relatedEntity = entity[attribute.code];
                if (relatedEntity && relatedEntity.id) {
                    const link = document.createElement('a');
                    link.href = "#";

                    const displayText = relatedEntity[attribute.displayField];
                    link.textContent = (displayText !== undefined && displayText !== null) ? displayText : 'Information non disponible';

                    link.dataset.action = "click->workspace-manager#openRelatedEntity";
                    link.dataset.entityId = relatedEntity.id;
                    link.dataset.entityType = attribute.targetEntity;
                    content.appendChild(link);
                    contentValueElement = link; // NOUVEAU : Attacher le tooltip au lien
                } else {
                    content.innerHTML = 'N/A';
                }
                break;

            case 'Collection':
                const collection = entity[attribute.code];
                if (collection && collection.length > 0) {
                    const ol = document.createElement('ol');
                    ol.className = 'accordion-collection-list';

                    collection.forEach(item => {
                        if (item && item.id) {
                            const li = document.createElement('li');
                            const link = document.createElement('a');
                            link.href = "#";

                            const itemDisplayText = item[attribute.displayField];
                            link.textContent = (itemDisplayText !== undefined && itemDisplayText !== null) ? itemDisplayText : 'Information non disponible';

                            link.dataset.action = "click->workspace-manager#openRelatedEntity";
                            link.dataset.entityId = item.id;
                            link.dataset.entityType = attribute.targetEntity;

                            li.appendChild(link);
                            ol.appendChild(li);
                        }
                    });
                    content.appendChild(ol);
                } else {
                    content.innerHTML = 'Aucun élément.';
                }
                break;

            case 'Calcul':
                const calculatedValue = entity[attribute.code];
                const formatAs = attribute.format || 'Texte'; // Lit le format désiré
                if (formatAs === 'ArrayAssoc' && typeof calculatedValue === 'object' && calculatedValue !== null) {
                    const list = document.createElement('ul');
                    list.className = 'accordion-key-value-list';

                    for (const [key, value] of Object.entries(calculatedValue)) {
                        const item = document.createElement('li');

                        // On vérifie si la valeur est un nombre pour la formater
                        const formattedValue = typeof value === 'number'
                            ? new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
                            : value;

                        item.innerHTML = `<strong>${key} :</strong> <span>${formattedValue}</span>`;
                        list.appendChild(item);
                    }
                    content.appendChild(list);
                    contentValueElement = list; // NOUVEAU : Attacher le tooltip à la liste complète
                } else {
                    content.innerHTML = this.formatValue(calculatedValue, formatAs, attribute.unite);
                    contentValueElement = content; // NOUVEAU : Attacher le tooltip au conteneur de valeur
                }
                break;

            default: // Gère 'Nombre', 'Date', 'Texte'
                const rawValue = entity[attribute.code];
                content.innerHTML = this.formatValue(rawValue, attribute.type, attribute.unite);
                contentValueElement = content; // NOUVEAU : Attacher le tooltip au conteneur de valeur
                break;
        }

        // NOUVEAU : Ajout conditionnel des attributs pour le TooltipManager
        if (attribute.description && contentValueElement) {
            // contentValueElement peut être l'élément 'content' lui-même, un 'a', ou un 'ul'
            contentValueElement.dataset.controller = "tooltip";
            contentValueElement.dataset.tooltipContent = attribute.description;
            // Optionnel: vous pouvez ajouter une classe pour indiquer que l'élément a un tooltip
            contentValueElement.classList.add('has-tooltip');
        }

        item.appendChild(title);
        item.appendChild(content);
        return item;
    }


    /**
     * Gère le clic sur une entité liée dans un accordéon pour l'ouvrir dans un nouvel onglet.
     * @param {MouseEvent} event
     */
    async openRelatedEntity(event) {
        event.preventDefault(); // Empêche le lien de remonter en haut de la page
        event.stopPropagation(); // Empêche d'autres clics de se déclencher

        const link = event.currentTarget;
        const entityId = link.dataset.entityId;
        const entityType = link.dataset.entityType;

        // 1. Afficher la barre de progression
        this.progressBarTarget.style.display = 'block';

        try {
            // Appel AJAX pour récupérer les détails complets (entité + canvas)
            const response = await fetch(`/espacedetravail/api/get-entity-details/${entityType}/${entityId}`);
            if (!response.ok) {
                throw new Error("Réponse du serveur non valide.");
            }
            const details = await response.json();

            // Notifie le Cerveau pour qu'il relaie la demande d'ouverture d'onglet.
            // Le WorkspaceManager écoutera cet événement (app:liste-element:openned)
            // pour effectivement créer l'onglet.
            this.notifyCerveau('ui:related-entity.open-request', details);

        } catch (error) {
            console.error("Impossible de charger les détails de l'entité liée :", error);
            // Afficher une notification d'erreur à l'utilisateur ici
        } finally {
            // 2. Cacher la barre de progression (toujours exécuté, même en cas d'erreur)
            this.progressBarTarget.style.display = 'none';
        }
    }

    /**
     * Active un onglet spécifique et affiche son contenu.
     * @param {Event} event 
     */
    activateTab(event) {
        const clickedTab = event.currentTarget;

        // Désactiver tous les autres onglets
        this.tabContainerTarget.querySelectorAll('.tab-item').forEach(tab => {
            tab.classList.remove('active');
            const content = this.tabContentContainerTarget.querySelector(`#${tab.dataset.tabContentId}`);
            if (content) {
                content.classList.remove('active');
            }
        });

        // Activer l'onglet cliqué et son contenu [cite: 41, 44]
        clickedTab.classList.add('active');
        const activeContent = this.tabContentContainerTarget.querySelector(`#${clickedTab.dataset.tabContentId}`);
        if (activeContent) {
            activeContent.classList.add('active');
        }
    }


    /**
     * Ferme un onglet et son contenu.
     * @param {Event} event 
     */
    closeTab(event) {
        event.stopPropagation(); // Empêche l'événement de "bubbler" vers activateTab
        let tabToClose;
        let contentToClose;

        // Détermine si le clic vient de l'en-tête ou de la barre de recherche
        const headerButton = event.currentTarget.closest('.tab-item');
        if (headerButton) {
            tabToClose = headerButton;
            contentToClose = this.tabContentContainerTarget.querySelector(`#${tabToClose.dataset.tabContentId}`);
        } else {
            contentToClose = event.currentTarget.closest('.tab-content');
            tabToClose = this.tabContainerTarget.querySelector(`[data-tab-content-id='${contentToClose.id}']`);
        }

        if (tabToClose) tabToClose.remove();
        if (contentToClose) contentToClose.remove();

        if (this.tabContainerTarget.children.length === 0) {
            this.element.classList.remove('visualization-visible');
            this.visualizationColumnTarget.style.display = 'none';
        } else {
            const lastTab = this.tabContainerTarget.querySelector('.tab-item:last-child');
            if (lastTab) this.activateTab({ currentTarget: lastTab });
        }
    }

    /**
     * Formate une valeur en fonction de son type.
     * @param {*} value - La valeur à formater.
     * @param {string} type - Le type de la valeur.
     * @param {string} [unit=''] - L'unité à ajouter (ex: '€').
     * @returns {string} La valeur formatée.
     */
    formatValue(value, type, unit = '') {
        if (value === null || typeof value === 'undefined') {
            return 'N/A';
        }

        let formattedValue;

        switch (type) {
            case 'Entier':
                formattedValue = new Intl.NumberFormat('fr-FR', {
                    maximumFractionDigits: 0 // La clé est ici : pas de décimales
                }).format(value);
                break;

            case 'Nombre':
                formattedValue = new Intl.NumberFormat('fr-FR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }).format(value);
                break;

            case 'Date':
                const date = new Date(value);
                formattedValue = isNaN(date.getTime()) ? value : date.toLocaleDateString('fr-FR');
                break;

            case 'Texte':
            default:
                formattedValue = value;
                break;
        }

        if (unit) {
            return `${unit} ${formattedValue}`;
        }

        return formattedValue;
    }


    /**
     * Affiche les rubriques (sous-menus) pour un groupe de navigation donné.
     * @param {HTMLElement} groupElement 
     */
    displayRubriquesForGroup(groupElement) {
        if (!groupElement || !groupElement.dataset.workspaceManagerGroupNameParam) {
            this.contentZoneTarget.innerHTML = '';
            return;
        }
        const groupName = groupElement.dataset.workspaceManagerGroupNameParam.replace(/ /g, '_');
        const templateContent = this.rubriquesTemplateTarget.content.querySelector(`#rubriques-${groupName}`);

        if (templateContent) {
            this.contentZoneTarget.innerHTML = templateContent.outerHTML;
        } else {
            this.contentZoneTarget.innerHTML = '';
        }
    }


    /**
     * Affiche la description d'un groupe au survol.
     * @param {MouseEvent} event
     */
    showGroupDescription(event) {
        const description = event.currentTarget.dataset.workspaceManagerDescriptionParam;
        this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
    }

    /**
     * Gère le clic sur un groupe de navigation pour afficher ses rubriques.
     * @param {MouseEvent} event
     */
    showGroupRubriques(event) {
        const clickedElement = event.currentTarget;
        this.updateActiveState(clickedElement);
        this.displayRubriquesForGroup(clickedElement);
    }

    /**
     * Restaure l'état de la zone de contenu lorsque la souris quitte un élément de navigation.
     * Déclenché lorsque la souris quitte un groupe.
     * @param {MouseEvent} event 
     */
    clearDescription(event) {
        if (this.activeNavItem) {
            // Si l'élément actif est un groupe, on réaffiche ses rubriques
            if (this.activeNavItem.dataset.workspaceManagerGroupNameParam) {
                this.displayRubriquesForGroup(this.activeNavItem);
            }
            // Si c'est un autre type d'élément (Tableau de bord, Paramètres), on réaffiche sa description
            else if (this.activeNavItem.dataset.workspaceManagerDescriptionParam) {
                const description = this.activeNavItem.dataset.workspaceManagerDescriptionParam;
                this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
            }
        } else {
            // S'il n'y a aucun élément actif, on vide la zone.
            this.contentZoneTarget.innerHTML = '';
        }
    }

    /**
     * Gère le clic sur une rubrique pour la charger dans l'espace de travail principal.
     * Notifie le Cerveau de la demande de chargement.
     * @param {MouseEvent} event
     */
    async loadComponent(event, options = {}) {
        const { isRestoration } = options;

        // CORRECTION : On nettoie l'état des composants enfants si ce n'est PAS une restauration.
        // Le `isRestoration` est `false` (ou `undefined`) lors d'un clic, ce qui déclenche le nettoyage.
        // Il est `true` lors d'un F5, ce qui préserve l'état.
        if (!isRestoration) {
            this._clearWorkspaceComponentStates();
        }

        // this._showWorkspaceSkeleton(); // SUPPRIMÉ : L'appel est redondant car le squelette est déjà présent dans le template Twig initial.
        
        console.log(
            `[${++window.logSequence}] [${this.nomControleur}] - loadComponent - Code: 100 - Données:`, 
            { 
                componentName: event.currentTarget.dataset.workspaceManagerComponentNameParam,
                entityName: event.currentTarget.dataset.workspaceManagerEntityNameParam,
                isRestoration: isRestoration
            }
        );
        const clickedElement = event.currentTarget;

        // Si ce n'est PAS une restauration, on nettoie l'état.
        

        const componentName = clickedElement.dataset.workspaceManagerComponentNameParam;
        const entityName = clickedElement.dataset.workspaceManagerEntityNameParam; // NOUVEAU : Récupérer le nom de l'entité

        const groupName = clickedElement.dataset.workspaceManagerGroupNameParam;
        const description = clickedElement.dataset.workspaceManagerDescriptionParam;

        if (!componentName) return;

        // On détermine si l'élément cliqué est une rubrique (dans la colonne 2)
        const isRubrique = clickedElement.classList.contains('rubrique-item');

        // On met à jour la colonne 2 (description) SEULEMENT si ce n'est PAS une rubrique.
        if (!isRubrique) {
            this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
        }

        this.progressBarTarget.style.display = 'block';

        // La sauvegarde de l'état est faite immédiatement après le clic.
        // Le chargement effectif du contenu sera géré par handleComponentLoaded.
        if (componentName) {
            const stateToSave = {
                component: componentName,
                group: groupName || null,
                entity: entityName || null // CORRECTION : Ajouter l'entityName à l'état sauvegardé
            };
            sessionStorage.setItem(`lastActiveState_${this.idEntrepriseValue}`, JSON.stringify(stateToSave));
        }

        // Cet événement est spécifiquement destiné au Cerveau pour charger le composant
        this.notifyCerveau('ui:component.load', { 
            componentName: componentName,
            entityName: entityName, // NOUVEAU : Envoyer le nom de l'entité
            idEntreprise: this.idEntrepriseValue,
            idInvite: this.idInviteValue
        });
        this.updateActiveState(clickedElement);
    }

    /**
     * Affiche un squelette de chargement dans la zone de travail.
     * @private
     */
    _showWorkspaceSkeleton() {
        const skeletonHtml = `
            <div class="workspace-skeleton">
                <div class="skeleton-header">
                    <div class="skeleton-icon"></div>
                    <div style="flex-grow: 1;">
                        <div class="skeleton-line" style="width: 40%; height: 24px;"></div>
                        <div class="skeleton-line" style="width: 80%; margin-top: 10px;"></div>
                    </div>
                </div>
                <div class="skeleton-line" style="height: 40px; width: 100%; margin-bottom: 1rem;"></div>
                <div class="skeleton-line" style="height: 40px; width: 100%; margin-bottom: 1rem;"></div>
                <div class="skeleton-row" style="height: 300px;"></div>
            </div>
        `;
        this.workspaceTarget.innerHTML = skeletonHtml;
    }

    /**
     * NOUVEAU : Vide les clés de sessionStorage liées aux composants de l'espace de travail.
     * C'est une étape cruciale pour s'assurer qu'une nouvelle rubrique est chargée
     * dans un état propre, sans hériter de l'état de la rubrique précédente.
     * @private
     */
    _clearWorkspaceComponentStates() {
        console.log(`[${this.nomControleur}] Nettoyage des états des composants en session.`);
        // On cible les clés que l'on sait être utilisées par nos composants.
        const prefixesToClear = ['viewManagerState_', 'listContent_', `lastSearchCriteria_`];
        for (let i = 0; i < sessionStorage.length; i++) {
            const key = sessionStorage.key(i);
            // On ne supprime PAS la clé de l'état actif global
            if (key.startsWith(`lastActiveState_`)) continue;

            if (prefixesToClear.some(prefix => key.startsWith(prefix))) {
                sessionStorage.removeItem(key);
                console.log(` -> Clé supprimée : ${key}`);
            }
        }
    }

    /**
     * Gère la réception du HTML du composant chargé par le Cerveau.
     * @param {CustomEvent} event 
     */
    handleComponentLoaded(event) {
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - handleComponentLoaded - Code: 100 - Données:`, event.detail);
        const { html, error } = event.detail;

        if (error) {
            // En cas d'erreur, on ne laisse pas le squelette affiché.
            // On pourrait aussi afficher un message d'erreur plus élaboré.
            this.workspaceTarget.innerHTML = '';
            console.error("Erreur lors du chargement du composant via le Cerveau:", error);
            this.workspaceTarget.innerHTML = `<div class="p-8 text-red-500">Impossible de charger le contenu : ${error}</div>`;
        } else {
            this.workspaceTarget.innerHTML = html;
            this.notifyCerveau('app:navigation-rubrique:openned', {}); // Notify Cerveau that a rubrique has been opened
        }

        this.progressBarTarget.style.display = 'none';
    }

    /**
     * Affiche la barre de progression lors d'une demande d'actualisation de liste.
     */
    handleLoadingStart() {
        console.log(this.nomControleur + " - Demande d'actualisation détectée, affichage de la barre de progression.");
        this.progressBarTarget.style.display = 'block';
    }

    /**
     * Cache la barre de progression sur ordre du Cerveau.
     */
    handleLoadingStop() {
        console.log(this.nomControleur + " - Fin du chargement des données, masquage de la barre de progression.");
        this.progressBarTarget.style.display = 'none';
    }

    /**
     * Met à jour l'état visuel (classe 'active') de l'élément de menu sélectionné.
     * @param {HTMLElement} currentElement L'élément qui vient d'être cliqué.
     */
    updateActiveState(currentElement) {
        if (currentElement.closest('.menu-col-1')) { // Main navigation items
            if (this.activeNavItem) {
                this.activeNavItem.classList.remove('active');
            }
            currentElement.classList.add('active');
            this.activeNavItem = currentElement;
            // Si on a cliqué sur un élément de la col1, on déselectionne la rubrique de la col2
            if (this.activeRubriqueItem) {
                this.activeRubriqueItem.classList.remove('active');
                this.activeRubriqueItem = null;
            }
        }
        // Handle rubrique items in column 2
        else if (currentElement.closest('.menu-col-2')) { // Rubrique items
            if (this.activeRubriqueItem) {
                this.activeRubriqueItem.classList.remove('active');
            }
            currentElement.classList.add('active');
            this.activeRubriqueItem = currentElement;
        }
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type de l'événement.
     * @param {object} [payload={}] - Les données associées à l'événement.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        // console.log(`${this.nomControleur} - Notification du Cerveau: ${type}`, payload);
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }


    /**
     * Filtre les éléments de l'accordéon en fonction de la saisie de l'utilisateur.
     * Applique un surlignage sur le terme recherché.
     * @param {InputEvent} event
     */
    filter(event) {
        const input = event.currentTarget;
        const searchTerm = input.value.trim().toLowerCase();

        console.log(this.nomControleur + " - Filter - Code: 1986 - Data:", event.currentTarget);

        let visibleCount = 0;

        this.itemTargets.forEach(item => {
            const titleElement = item.querySelector('.accordion-title');
            if (!titleElement) return;

            const toggleIcon = titleElement.querySelector('.accordion-toggle');
            const iconHtml = toggleIcon ? toggleIcon.outerHTML : '';

            // Stocke le titre original une seule fois pour la performance
            if (!titleElement.dataset.originalTitle) {
                const tempClone = titleElement.cloneNode(true);
                tempClone.querySelector('.accordion-toggle')?.remove();
                titleElement.dataset.originalTitle = tempClone.textContent.trim();
            }

            const originalTitleText = titleElement.dataset.originalTitle;

            if (originalTitleText.toLowerCase().includes(searchTerm)) {
                item.style.display = '';
                visibleCount++;

                if (searchTerm) {
                    const regex = new RegExp(searchTerm.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi');
                    const highlightedText = originalTitleText.replace(regex, `<strong class="search-highlight">$&</strong>`);
                    titleElement.innerHTML = `${iconHtml} ${highlightedText}`;
                } else {
                    titleElement.innerHTML = `${iconHtml} ${originalTitleText}`;
                }
            } else {
                item.style.display = 'none';
            }
        });

        if (this.hasNoResultsMessageTarget) {
            this.noResultsMessageTarget.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    /**
     * Réinitialise le champ de recherche et le filtre de l'accordéon.
     */
    resetFilter() {
        if (this.hasSearchInputTarget) {
            this.searchInputTarget.value = '';
            this.searchInputTarget.dispatchEvent(new Event('input'));
        }
    }

    /**
     * Bascule l'affichage du contenu d'un item d'accordéon (ouvert/fermé).
     * @param {MouseEvent} event
     */
    toggle(event) {
        const title = event.currentTarget;
        const content = title.nextElementSibling;
        const toggleIcon = title.querySelector('.accordion-toggle');

        if (!content || !toggleIcon) return;

        const isOpen = content.classList.contains('open');

        if (isOpen) {
            content.classList.remove('open');
            toggleIcon.textContent = '+';
        } else {
            content.classList.add('open');
            toggleIcon.textContent = '-';
        }
    }
}