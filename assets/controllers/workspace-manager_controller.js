import { Controller } from '@hotwired/stimulus';

/**
 * @class WorkspaceManagerController
 * @extends Controller
 * @description Gère l'espace de travail principal de l'application, y compris la navigation, le chargement dynamique des composants, la gestion des onglets et la persistance de l'état.
 */
export default class extends Controller {
    static targets = [
        "progressBar",
        "workspace",
        "rubriquesContainer",
        "descriptionContainer",
        "rubriquesTemplate",
        "dashboardItem",
        "visualizationColumn",
        "tabContainer",
        "tabContentContainer",
        "tabTemplate",
        "tabContentTemplate",
        "workspaceTabBar",
        "workspaceTabPanels",
        "workspaceTabTemplate"
    ];


    static values = {
        idEntreprise: Number,
        idInvite: Number
    }

    // Cible pour l'élément de menu actuellement actif
    activeNavItem = null;
    activeGroupNavItem = null;
    activeRubriqueItem = null;
    activeRubriqueState = null;

    // --- Gestion des onglets de l'espace de travail (col-3) ---
    workspaceTabs = [];
    activeWorkspaceTabId = null;
    pendingWorkspaceTabId = null;

    connect() {
        this.nomControleur = "WorkspaceManager";
        this.activeRubriqueState = null;
        this._restoreWorkspaceTabsFromStorage();

        this.boundOpenTabInVisualization = this.openTabInVisualization.bind(this);
        document.addEventListener('app:liste-element:openned', this.boundOpenTabInVisualization);

        // NOUVEAU : Écoute la réponse du Cerveau pour afficher une icône chargée dynamiquement.
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);

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

        this.boundHandleNavigateTo = this.handleNavigateTo.bind(this);
        document.addEventListener('workspace:navigate-to', this.boundHandleNavigateTo);
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
        this.activeRubriqueState = savedState; // Mémoriser l'état au chargement

        // NOUVEAU : Appeler la méthode pour définir le style du groupe actif dès la restauration.
        this.updateActiveGroupState(savedState.group);
        
        // Cas 1 : C'est une rubrique (elle a un groupe parent)
        if (savedState.group) {
            const groupElement = this.element.querySelector(`[data-workspace-manager-group-name-param='${savedState.group}']`);
            if (groupElement) {
                // On clique d'abord sur le groupe pour afficher les rubriques
                groupElement.click();

                requestAnimationFrame(() => {
                    // On cherche la rubrique en utilisant à la fois le composant ET le nom de l'entité.
                    const selector = `[data-workspace-manager-component-name-param='${savedState.component}'][data-workspace-manager-entity-name-param='${savedState.entity}']`;
                    const rubriqueElement = this.rubriquesContainerTarget.querySelector(selector);

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
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
        document.removeEventListener('workspace:component.loaded', this.boundHandleComponentLoaded);
        document.removeEventListener('app:workspace.load-default', this.boundLoadDefault);
        document.removeEventListener('app:loading.start', this.boundHandleLoadingStart);
        document.removeEventListener('app:loading.stop', this.boundHandleLoadingStop);
        document.removeEventListener('workspace:navigate-to', this.boundHandleNavigateTo);
    }

    /**
     * Donne le focus au champ de recherche de l'accordéon quand on clique sur la barre.
     */
    focusSearch(event) {
        // Empêche le focus si on clique sur un bouton
        if (event.target.tagName.toLowerCase() === 'button' || event.target.closest('button')) {
            return;
        }
        // On ne peut pas utiliser de "target" car le champ est dans un template.
        // On le cherche donc par rapport à l'élément cliqué (la barre de recherche).
        const searchInput = event.currentTarget.querySelector('.accordion-search-input');
        if (searchInput) {
            searchInput.focus();
        }
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
        console.log(this.nomControleur + " - Code: 1986 - Création de l'onglet: - this.tabTemplateTarget", this.tabTemplateTarget);

        const tabElement = this.tabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabElement.dataset.entityId = entity.id;
        tabElement.dataset.entityType = entityType;

        const params = entityCanvas.parametres;
        tabElement.title = params.description;

        // NOUVEAU : Logique pour charger l'icône dynamiquement
        const iconContainer = tabElement.querySelector('.tab-item-icon');
        const iconName = params.icone; // ex: 'risque', 'contact'
        if (iconContainer && iconName) {
            // On donne au conteneur un ID unique pour que `handleIconLoaded` puisse le retrouver.
            const requesterId = `tab-icon-${entityType}-${entity.id}`;
            iconContainer.id = requesterId;
            // On demande au Cerveau de nous fournir le HTML de l'icône.
            // La taille de 18px est choisie pour correspondre à la taille des autres icônes d'onglet.
            this.notifyCerveau('ui:icon.request', { iconName: iconName, requesterId: requesterId, iconSize: 18 });
        }

        tabElement.querySelector('[data-role="tab-title"]').textContent = `#${entity.id}`;

        // --- Création du contenu de l'onglet ---
        console.log(this.nomControleur + " - Code: 1986 - Création de l'onglet: - this.tabContentTemplate", this.tabContentTemplateTarget);

        const contentElement = this.tabContentTemplateTarget.content.cloneNode(true).firstElementChild;
        const accordionContainer = contentElement.querySelector('.accordion');

        // NOUVEAU : Création et insertion du panneau de description
        const descriptionPanel = document.createElement('div');
        descriptionPanel.className = 'description-panel';

        // NOUVEAU : On applique l'image de fond dynamiquement si elle est définie dans le canvas.
        const backgroundImage = entityCanvas?.parametres?.background_image;
        if (backgroundImage) {
            descriptionPanel.style.backgroundImage = `url('${backgroundImage}')`;
        }

        descriptionPanel.innerHTML = this._buildDescriptionText(entity, entityType, entityCanvas);

        // On cherche la barre de recherche pour insérer le panneau avant elle.
        const searchBar = contentElement.querySelector('.accordion-search-bar');
        if (searchBar) {
            searchBar.parentNode.insertBefore(descriptionPanel, searchBar);
        } else if (accordionContainer) {
            accordionContainer.parentNode.insertBefore(descriptionPanel, accordionContainer);
        } else {
            contentElement.prepend(descriptionPanel);
        }

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

        // ARIA : pattern tablist/tab/tabpanel (WCAG 4.1.2)
        const tabAriaId = `tab-${entityType}-${entity.id}`;
        tabElement.setAttribute('role', 'tab');
        tabElement.setAttribute('id', tabAriaId);
        tabElement.setAttribute('aria-selected', 'false');
        tabElement.setAttribute('aria-controls', tabId);
        contentElement.setAttribute('role', 'tabpanel');
        contentElement.setAttribute('aria-labelledby', tabAriaId);
        contentElement.setAttribute('tabindex', '0');

        // Ajouter les nouveaux éléments au DOM
        this.tabContainerTarget.appendChild(tabElement);
        this.tabContentContainerTarget.appendChild(contentElement);

        // Activer le nouvel onglet créé
        this.activateTab({ currentTarget: tabElement });

        console.log(this.nomControleur + " - Onglet ouvert:", entity);
    }

    /**
     * NOUVEAU : Gère la réception du HTML d'une icône demandée et l'injecte dans le bon conteneur.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event) {
        const { html, requesterId } = event.detail;
        // On cherche un élément avec l'ID du demandeur dans le périmètre de ce contrôleur.
        // Cela garantit que ce contrôleur ne met à jour que les icônes qu'il a lui-même demandées.
        const iconContainer = this.element.querySelector(`#${requesterId}`);
        if (iconContainer) {
            iconContainer.innerHTML = html;
        }
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
        title.className = 'accordion-title';
        title.dataset.action = 'click->workspace-manager#toggle';
        title.innerHTML = `<span class="accordion-toggle">+</span> ${attribute.intitule}`;

        // ARIA : pattern button/region pour l'accordéon (WCAG 4.1.2)
        const accordionId = `accordion-${entity.id}-${attribute.code}`;
        const contentId = `accordion-content-${entity.id}-${attribute.code}`;
        title.setAttribute('role', 'button');
        title.setAttribute('tabindex', '0');
        title.setAttribute('aria-expanded', 'false');
        title.setAttribute('id', accordionId);
        title.setAttribute('aria-controls', contentId);

        const content = document.createElement('div');
        content.className = 'accordion-content';
        content.setAttribute('id', contentId);

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
                    // CORRECTION : Utiliser le nom de route fourni par le canvas
                    link.dataset.entityType = attribute.targetEntityRouteName;
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
                            // CORRECTION : Utiliser le nom de route fourni par le canvas
                            link.dataset.entityType = attribute.targetEntityRouteName;

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
     * NOUVEAU : Routeur pour construire la description textuelle.
     * Appelle une méthode spécifique si elle existe, sinon une méthode générique.
     * @param {object} entity L'entité.
     * @param {string} entityType Le type de l'entité.
     * @param {object} entityCanvas Le canevas de l'entité.
     * @returns {string} Le HTML de la description.
     * @private
     */
    _buildDescriptionText(entity, entityType, entityCanvas) {
        // NOUVEAU : On cherche un template de description dans le canevas.
        const templateParts = entityCanvas?.parametres?.description_template;

        if (templateParts && Array.isArray(templateParts)) {
            // Si un template (sous forme de tableau) est trouvé, on utilise le nouveau constructeur.
            return this._buildDescriptionFromTemplate(entity, entityCanvas, templateParts);
        }

        // Sinon, on utilise la méthode générique par défaut.
        return this._buildGenericDescription(entity, entityCanvas);
    }

    /**
     * NOUVEAU : Construit une description narrative en se basant sur un template (tableau de clauses).
     * Chaque clause du template est traitée : si elle contient des ancres avec des valeurs valides,
     * elle est ajoutée au texte final.
     * @param {object} entity L'objet de données de l'entité.
     * @param {object} entityCanvas Le canevas de configuration de l'entité.
     * @param {string[]} templateParts Le tableau de clauses du template.
     * @returns {string} Le HTML de la description.
     * @private
     */
    _buildDescriptionFromTemplate(entity, entityCanvas, templateParts) {
        const builtParts = [];
        const anchorRegex = /\[\[(.*?)\]\]/g;

        for (const partTemplate of templateParts) {
            let processedPart = partTemplate;
            let hasContent = false;
            const anchors = [...partTemplate.matchAll(anchorRegex)];

            if (anchors.length === 0) {
                hasContent = true; // C'est une partie statique, on la garde.
            } else {
                for (const anchor of anchors) {
                    const anchorText = anchor[0]; // ex: [[*code]]
                    const anchorContent = anchor[1]; // ex: *code

                    const isImportant = anchorContent.startsWith('*');
                    const code = isImportant ? anchorContent.substring(1) : anchorContent;

                    const attribute = entityCanvas.liste.find(attr => attr.code === code);
                    if (!attribute) {
                        console.warn(`[WorkspaceManager] Ancre "${code}" introuvable dans le canevas.`);
                        processedPart = processedPart.replace(anchorText, ''); // Retire l'ancre inconnue
                        continue;
                    }

                    const value = this._getVal(entity, code, attribute.displayField);
                    let formattedValue = '';

                    if (value !== null && value !== '') {
                        hasContent = true; // Cette clause a du contenu valide.
                        switch (attribute.type) {
                            case 'Relation':
                                const relatedEntity = entity[attribute.code];
                                const link = document.createElement('a');
                                link.href = "#";
                                link.textContent = value;
                                link.dataset.action = "click->workspace-manager#openRelatedEntity";
                                link.dataset.entityId = relatedEntity.id;
                                link.dataset.entityType = this._getCleanEntityName(attribute.targetEntity);
                                formattedValue = link.outerHTML;
                                break;
                            default: // Gère Nombre, Date, Entier, Texte
                                formattedValue = this.formatValue(value, attribute.type, attribute.unite);
                                break;
                        }
                        if (isImportant) {
                            formattedValue = `<strong>${formattedValue}</strong>`;
                        }
                    }
                    processedPart = processedPart.replace(anchorText, formattedValue);
                }
            }

            if (hasContent) {
                builtParts.push(processedPart);
            }
        }

        let finalText = builtParts.join('').trim();
        // Nettoyage final pour la grammaire
        finalText = finalText.replace(/^, /g, '').replace(/^ et /g, ''); // Supprime ", " ou " et " en début de chaîne.
        finalText = finalText.replace(/ \./g, '.').replace(/ ,/g, ','); // " ." -> "." | " ," -> ","

        return `<p>${finalText}</p>`;
    }

    /**
     * NOUVEAU : Construit une description générique pour les entités non spécifiques.
     * @param {object} entity L'entité.
     * @param {object} entityCanvas Le canevas de l'entité.
     * @returns {string} Le HTML de la description.
     * @private
     */
    _buildGenericDescription(entity, entityCanvas) {
        const mainTitleField = entityCanvas?.liste?.find(attr => attr.col_principale)?.texte_principal.attribut_code || 'nom';
        const mainTitle = this._getVal(entity, mainTitleField);
        if (!mainTitle) return '<p class="text-muted">Aucune description disponible pour cet élément.</p>';
        return `<p>Détails pour l'élément : <strong>${mainTitle}</strong> (ID: ${entity.id}).</p>`;
    }

    /**
     * NOUVEAU : Extrait le nom de l'entité à partir de son FQCN (Fully Qualified Class Name).
     * Ex: "App\Entity\Contact" -> "Contact"
     * @param {string} fqcn Le nom complet de la classe.
     * @returns {string} Le nom de l'entité.
     * @private
     */
    _getCleanEntityName(fqcn) {
        if (!fqcn || typeof fqcn !== 'string') {
            return '';
        }
        // Trouve la dernière occurrence de '\' et prend ce qui suit.
        const parts = fqcn.split('\\');
        return parts[parts.length - 1];
    }

    _getVal(entity, code, displayField = null) {
        if (!entity || !code) return null;
        const value = entity[code];
        if (value === null || typeof value === 'undefined') return null;
        if (typeof value === 'object' && displayField && value[displayField]) return value[displayField];
        if (typeof value === 'object' && !displayField) return null;
        return value;
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

        // Synchronise aria-selected sur tous les onglets (WCAG 4.1.2)
        this.tabContainerTarget.querySelectorAll('[role="tab"]').forEach(tab => {
            tab.setAttribute('aria-selected', tab === clickedTab ? 'true' : 'false');
        });

        // Activer l'onglet cliqué et son contenu
        clickedTab.classList.add('active');
        const activeContent = this.tabContentContainerTarget.querySelector(`#${clickedTab.dataset.tabContentId}`);
        if (activeContent) {
            activeContent.classList.add('active');
        }

        // NOUVEAU : Fait défiler l'onglet actif pour qu'il soit centré et visible, améliorant l'UX avec de nombreux onglets.
        clickedTab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
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
            this.rubriquesContainerTarget.innerHTML = '';
            return;
        }
        const groupName = groupElement.dataset.workspaceManagerGroupNameParam; // Garder le nom original
        const groupNameForId = groupName.replace(/ /g, '_');
        const templateContent = this.rubriquesTemplateTarget.content.querySelector(`#rubriques-${groupNameForId}`);

        if (templateContent) {
            this.rubriquesContainerTarget.innerHTML = templateContent.outerHTML;

            // NOUVEAU : Logique pour restaurer l'état actif de la rubrique si elle appartient à ce groupe.
            if (this.activeRubriqueState && this.activeRubriqueState.group === groupName) {
                const selector = `[data-workspace-manager-component-name-param='${this.activeRubriqueState.component}'][data-workspace-manager-entity-name-param='${this.activeRubriqueState.entity}']`;
                const rubriqueElement = this.rubriquesContainerTarget.querySelector(selector);
                if (rubriqueElement) {
                    rubriqueElement.classList.add('active');
                    // On met à jour la référence vers le nouvel élément DOM actif.
                    this.activeRubriqueItem = rubriqueElement;
                }
            }
        } else {
            this.rubriquesContainerTarget.innerHTML = '';
        }
    }

    /**
     * Affiche la description d'un élément de menu au survol.
     * @param {MouseEvent} event
     */
    showItemDescription(event) {
        const description = event.currentTarget.dataset.workspaceManagerDescriptionParam;
        this.descriptionContainerTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
        this.rubriquesContainerTarget.style.display = 'none';
        this.descriptionContainerTarget.style.display = 'block';
    }

    /**
     * Gère le clic sur un groupe de navigation pour afficher ses rubriques.
     * @param {MouseEvent} event
     */
    showGroupRubriques(event) {
        const clickedElement = event.currentTarget;
        this.updateActiveState(clickedElement);
        this.displayRubriquesForGroup(clickedElement);
        this.descriptionContainerTarget.style.display = 'none';
        this.rubriquesContainerTarget.style.display = 'block';
    }

    /**
     * Restaure l'état de la zone de contenu lorsque la souris quitte un élément de navigation de groupe.
     * @param {MouseEvent} event 
     */
    clearDescription(event) {
        if (this.activeNavItem && this.activeNavItem.dataset.workspaceManagerGroupNameParam) {
            // Un groupe est actif, on s'assure que ses rubriques sont visibles.
            this.descriptionContainerTarget.style.display = 'none';
            this.rubriquesContainerTarget.style.display = 'block';
        } else if (this.activeNavItem && this.activeNavItem.dataset.workspaceManagerDescriptionParam) {
            // Un élément de premier niveau (ex: Tableau de bord) est actif, on réaffiche sa description.
            const description = this.activeNavItem.dataset.workspaceManagerDescriptionParam;
            this.descriptionContainerTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
            this.rubriquesContainerTarget.style.display = 'none';
            this.descriptionContainerTarget.style.display = 'block';
        } else {
            // Rien n'est actif, on vide et cache tout.
            this.rubriquesContainerTarget.innerHTML = '';
            this.descriptionContainerTarget.innerHTML = '';
            this.descriptionContainerTarget.style.display = 'none';
            this.rubriquesContainerTarget.style.display = 'none';
        }
    }

    /**
     * Gère le clic sur une rubrique pour la charger dans l'espace de travail principal.
     * Notifie le Cerveau de la demande de chargement.
     * @param {MouseEvent} event
     */
    async loadComponent(event, options = {}) {
        const { isRestoration } = options;
        const clickedElement = event.currentTarget;

        const componentName = clickedElement.dataset.workspaceManagerComponentNameParam;
        const entityName    = clickedElement.dataset.workspaceManagerEntityNameParam || '';
        const groupName     = clickedElement.dataset.workspaceManagerGroupNameParam  || '';
        const description   = clickedElement.dataset.workspaceManagerDescriptionParam || '';
        const iconAlias     = clickedElement.dataset.workspaceManagerIconParam || '';

        if (!componentName) return;

        const isRubrique = clickedElement.classList.contains('rubrique-item');

        // Mettre à jour col-2 (description) uniquement pour les items top-level (pas les rubriques)
        if (!isRubrique) {
            this.descriptionContainerTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
            this.rubriquesContainerTarget.style.display = 'none';
            this.descriptionContainerTarget.style.display = 'block';
        }

        // Récupérer le titre depuis le texte de l'élément cliqué
        const title = (clickedElement.querySelector('.rubrique-text, .nav-text')?.textContent?.trim())
                   || entityName || componentName;

        if (!isRestoration) {
            // Créer un nouvel onglet (avec squelette) et définir pendingWorkspaceTabId
            this.createWorkspaceTab({ componentName, entityName, groupName, title, iconAlias });
        } else {
            // Lors d'une restauration, le panneau existe déjà — on envoie juste l'événement Cerveau
            this.progressBarTarget.style.display = 'block';
            this.notifyCerveau('ui:component.load', {
                componentName,
                entityName,
                idEntreprise: this.idEntrepriseValue,
                idInvite: this.idInviteValue
            });
        }

        this.updateActiveState(clickedElement);
        this.updateActiveGroupState(groupName);
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
        const { html, error } = event.detail;

        if (!this.pendingWorkspaceTabId) {
            this.progressBarTarget.style.display = 'none';
            return;
        }

        const panel = this.workspaceTabPanelsTarget.querySelector(`[data-tab-id="${this.pendingWorkspaceTabId}"]`);
        if (panel) {
            if (error) {
                panel.innerHTML = `<div class="p-4" style="color:#dc3545;">Impossible de charger le contenu : ${error}</div>`;
            } else {
                panel.innerHTML = html;
                panel.dataset.loaded = 'true';
            }
        }

        this.pendingWorkspaceTabId = null;
        this.progressBarTarget.style.display = 'none';
        if (!error) this.notifyCerveau('app:navigation-rubrique:openned', {});
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
        // console.log(this.nomControleur + " - Fin du chargement des données, masquage de la barre de progression.");
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
     * NOUVEAU : Gère la classe 'group-active' pour le groupe de navigation principal.
     * Cette classe applique un style persistant au groupe contenant la rubrique actuellement ouverte.
     * @param {string|null} groupName - Le nom du groupe à activer. Si null, désactive tous les groupes.
     */
    updateActiveGroupState(groupName) {
        // 1. Retire la classe de l'ancien groupe actif, s'il existe.
        if (this.activeGroupNavItem) {
            this.activeGroupNavItem.classList.remove('group-active');
            this.activeGroupNavItem = null;
        }

        // 2. Si un nom de groupe est fourni, trouve l'élément et lui ajoute la classe.
        if (groupName) {
            const groupElement = this.element.querySelector(`[data-workspace-manager-group-name-param='${groupName}']`);
            if (groupElement) {
                groupElement.classList.add('group-active');
                this.activeGroupNavItem = groupElement; // Mémorise le nouvel élément de groupe actif.
            }
        }
    }

    /**
     * Filtre les éléments de l'accordéon en fonction de la saisie de l'utilisateur.
     * Applique un surlignage sur le terme recherché.
     * @param {InputEvent} event
     */
    filter(event) {
        const input = event.currentTarget;
        const searchTerm = input.value.trim().toLowerCase();

        // NOUVELLE LOGIQUE : On ne se fie plus à `this.itemTargets`.
        // On trouve le conteneur de l'onglet parent de l'input qui a déclenché l'événement.
        const tabContent = input.closest('.tab-content');
        if (!tabContent) return;

        // On cherche les éléments et le message de "non trouvé" à l'intérieur de cet onglet spécifique.
        const items = tabContent.querySelectorAll('.accordion-item');
        const noResultsMessage = tabContent.querySelector('[data-workspace-manager-target="noResultsMessage"]');

        let visibleCount = 0;

        items.forEach(item => {
            const titleElement = item.querySelector('.accordion-title');
            if (!titleElement) return;

            const toggleIcon = titleElement.querySelector('.accordion-toggle');
            const iconHtml = toggleIcon ? toggleIcon.outerHTML : '';

            if (!titleElement.dataset.originalTitle) {
                const tempClone = titleElement.cloneNode(true);
                tempClone.querySelector('.accordion-toggle')?.remove();
                titleElement.dataset.originalTitle = tempClone.textContent.trim();
            }

            const originalTitleText = titleElement.dataset.originalTitle;

            if (originalTitleText.toLowerCase().includes(searchTerm)) {
                item.style.display = '';
                visibleCount++;
                const regex = new RegExp(searchTerm.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi');
                titleElement.innerHTML = `${iconHtml} ${searchTerm ? originalTitleText.replace(regex, `<strong class="search-highlight">$&</strong>`) : originalTitleText}`;
            } else {
                item.style.display = 'none';
            }
        });

        if (noResultsMessage) {
            noResultsMessage.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    /**
     * Réinitialise le champ de recherche et le filtre de l'accordéon.
     */
    resetFilter(event) {
        // On ne peut pas utiliser de "target". On cherche l'input par rapport au bouton cliqué.
        const searchBar = event.currentTarget.closest('.accordion-search-bar');
        if (searchBar) {
            const searchInput = searchBar.querySelector('.accordion-search-input');
            if (searchInput) {
                searchInput.value = '';
                // On déclenche manuellement l'événement 'input' pour que la méthode filter() s'exécute.
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    }

    /**
     * Ouvre un nouvel onglet workspace en réponse à l'événement `workspace:navigate-to`.
     * Utilisé par les boutons "Voir plus" du tableau de bord pour éviter un rechargement de page.
     * @param {CustomEvent} event - detail: { component, group, entity }
     */
    handleNavigateTo(event) {
        const { component, group, entity } = event.detail;

        // Chercher le rubrique element dans le template (contient tous les items avec leurs data-attrs)
        const selector = `[data-workspace-manager-component-name-param='${component}'][data-workspace-manager-entity-name-param='${entity}']`;
        const rubriqueEl = this.rubriquesTemplateTarget.content.querySelector(selector);

        if (rubriqueEl) {
            this.loadComponent({ currentTarget: rubriqueEl }, {});
        } else {
            // Fallback si l'élément n'est pas trouvé dans le template
            this.createWorkspaceTab({
                componentName: component,
                entityName: entity,
                groupName: group || '',
                title: entity,
                iconAlias: entity.toLowerCase()
            });
        }
    }

    // =========================================================================
    // Gestion des onglets de l'espace de travail (col-3)
    // =========================================================================

    /**
     * Crée un nouvel onglet dans la barre d'onglets de col-3 et son panneau associé.
     * Le contenu est chargé de façon lazy (au premier `activateWorkspaceTab`).
     */
    createWorkspaceTab({ componentName, entityName, groupName, title, iconAlias }) {
        const tabId = `ws-tab-${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        // --- Tab button ---
        const tabEl = this.workspaceTabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabEl.dataset.tabId = tabId;
        tabEl.dataset.componentName = componentName;
        tabEl.dataset.entityName = entityName || '';
        tabEl.dataset.groupName = groupName || '';
        tabEl.querySelector('.workspace-tab-title').textContent = title || entityName || componentName;

        if (iconAlias) {
            const requesterId = `ws-icon-${tabId}`;
            tabEl.querySelector('.workspace-tab-icon').id = requesterId;
            this.notifyCerveau('ui:icon.request', { iconName: iconAlias, requesterId, iconSize: 16 });
        }

        // --- Tab panel ---
        const panel = document.createElement('div');
        panel.className = 'workspace-tab-panel';
        panel.dataset.tabId = tabId;
        panel.dataset.loaded = 'false';
        panel.innerHTML = this._workspaceSkeletonHtml();

        // Supprimer le squelette initial au premier onglet
        const initialSkeleton = this.workspaceTabPanelsTarget.querySelector('.workspace-initial-skeleton');
        if (initialSkeleton) initialSkeleton.remove();

        this.workspaceTabBarTarget.appendChild(tabEl);
        this.workspaceTabPanelsTarget.appendChild(panel);

        this.workspaceTabs.push({ id: tabId, componentName, entityName: entityName || '', groupName: groupName || '', title: title || entityName || componentName, iconAlias: iconAlias || '' });

        this.pendingWorkspaceTabId = tabId;
        this._activateWorkspaceTabById(tabId);
        this._saveWorkspaceTabsToStorage();
    }

    /**
     * Gestionnaire d'événement : active l'onglet workspace cliqué.
     */
    activateWorkspaceTab(event) {
        event.stopPropagation();
        const tabEl = event.currentTarget;
        const tabId = tabEl.dataset.tabId;
        if (!tabId) return;
        this._activateWorkspaceTabById(tabId);
    }

    /**
     * Active un onglet workspace par son ID (interne, sans event).
     */
    _activateWorkspaceTabById(tabId) {
        // Désactiver tous les tabs et panels
        this.workspaceTabBarTarget.querySelectorAll('.workspace-tab-item').forEach(t => {
            t.classList.remove('active');
            t.setAttribute('aria-selected', 'false');
        });
        this.workspaceTabPanelsTarget.querySelectorAll('.workspace-tab-panel').forEach(p => {
            p.classList.remove('active');
        });

        const tabEl = this.workspaceTabBarTarget.querySelector(`[data-tab-id="${tabId}"]`);
        const panel = this.workspaceTabPanelsTarget.querySelector(`[data-tab-id="${tabId}"]`);
        if (!tabEl || !panel) return;

        tabEl.classList.add('active');
        tabEl.setAttribute('aria-selected', 'true');
        panel.classList.add('active');
        this.activeWorkspaceTabId = tabId;

        // Lazy load : charger le contenu si pas encore chargé
        if (panel.dataset.loaded !== 'true') {
            const tabData = this.workspaceTabs.find(t => t.id === tabId);
            if (tabData) {
                this.pendingWorkspaceTabId = tabId;
                this.progressBarTarget.style.display = 'block';
                this.notifyCerveau('ui:component.load', {
                    componentName: tabData.componentName,
                    entityName: tabData.entityName,
                    idEntreprise: this.idEntrepriseValue,
                    idInvite: this.idInviteValue
                });
            }
        }

        // Synchroniser le menu col-1/col-2 avec cet onglet
        const tabData = this.workspaceTabs.find(t => t.id === tabId);
        if (tabData) this._syncMenuWithTab(tabData);

        this._saveWorkspaceTabsToStorage();
    }

    /**
     * Gestionnaire d'événement : ferme l'onglet workspace ciblé.
     */
    closeWorkspaceTab(event) {
        event.stopPropagation();
        const tabEl = event.currentTarget.closest('.workspace-tab-item');
        if (!tabEl) return;
        const tabId = tabEl.dataset.tabId;

        const panel = this.workspaceTabPanelsTarget.querySelector(`[data-tab-id="${tabId}"]`);
        const wasActive = tabEl.classList.contains('active');

        tabEl.remove();
        if (panel) panel.remove();
        this.workspaceTabs = this.workspaceTabs.filter(t => t.id !== tabId);

        if (wasActive) {
            const remaining = this.workspaceTabBarTarget.querySelectorAll('.workspace-tab-item');
            if (remaining.length > 0) {
                const lastId = remaining[remaining.length - 1].dataset.tabId;
                this._activateWorkspaceTabById(lastId);
            } else {
                this.activeWorkspaceTabId = null;
                this._saveWorkspaceTabsToStorage();
            }
        } else {
            this._saveWorkspaceTabsToStorage();
        }
    }

    /**
     * Synchronise les états actifs de col-1/col-2 selon les métadonnées de l'onglet.
     */
    _syncMenuWithTab(tabData) {
        this.updateActiveGroupState(tabData.groupName || null);

        if (tabData.groupName) {
            const groupEl = this.element.querySelector(`[data-workspace-manager-group-name-param='${tabData.groupName}']`);
            if (groupEl) {
                this.showGroupRubriques({ currentTarget: groupEl });
                requestAnimationFrame(() => {
                    const selector = `[data-workspace-manager-component-name-param='${tabData.componentName}'][data-workspace-manager-entity-name-param='${tabData.entityName}']`;
                    const rubriqueEl = this.rubriquesContainerTarget.querySelector(selector);
                    if (rubriqueEl) this.updateActiveState(rubriqueEl);
                });
            }
        } else if (tabData.componentName) {
            const el = this.element.querySelector(`[data-workspace-manager-component-name-param='${tabData.componentName}']`);
            if (el) {
                this.updateActiveState(el);
                // Afficher la description dans col-2 pour les items de premier niveau (ex: Tableau de bord)
                const description = el.dataset.workspaceManagerDescriptionParam;
                if (description) {
                    this.descriptionContainerTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
                    this.rubriquesContainerTarget.style.display = 'none';
                    this.descriptionContainerTarget.style.display = 'block';
                }
            }
        }
    }

    /**
     * Reconstruit le bouton d'onglet + panel vide (sans chargement) à partir des métadonnées.
     * Utilisé lors de la restauration depuis le localStorage.
     */
    _createTabStructure(tabData) {
        const tabEl = this.workspaceTabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabEl.dataset.tabId = tabData.id;
        tabEl.dataset.componentName = tabData.componentName;
        tabEl.dataset.entityName = tabData.entityName || '';
        tabEl.dataset.groupName = tabData.groupName || '';
        tabEl.querySelector('.workspace-tab-title').textContent = tabData.title || tabData.entityName || tabData.componentName;

        if (tabData.iconAlias) {
            const requesterId = `ws-icon-${tabData.id}`;
            tabEl.querySelector('.workspace-tab-icon').id = requesterId;
            this.notifyCerveau('ui:icon.request', { iconName: tabData.iconAlias, requesterId, iconSize: 16 });
        }

        const panel = document.createElement('div');
        panel.className = 'workspace-tab-panel';
        panel.dataset.tabId = tabData.id;
        panel.dataset.loaded = 'false';
        panel.innerHTML = this._workspaceSkeletonHtml();

        this.workspaceTabBarTarget.appendChild(tabEl);
        this.workspaceTabPanelsTarget.appendChild(panel);
    }

    /**
     * Persiste les métadonnées des onglets en cours dans le localStorage.
     */
    _saveWorkspaceTabsToStorage() {
        const state = { tabs: this.workspaceTabs, activeTabId: this.activeWorkspaceTabId };
        localStorage.setItem(`workspaceTabs_${this.idEntrepriseValue}`, JSON.stringify(state));
    }

    /**
     * Restaure les onglets depuis le localStorage au chargement de la page.
     * Remplace l'ancienne méthode restoreLastState().
     */
    _restoreWorkspaceTabsFromStorage() {
        const savedJSON = localStorage.getItem(`workspaceTabs_${this.idEntrepriseValue}`);

        if (!savedJSON) {
            this._openDefaultTab();
            return;
        }

        let saved;
        try { saved = JSON.parse(savedJSON); } catch { this._openDefaultTab(); return; }

        const { tabs, activeTabId } = saved;
        if (!tabs || tabs.length === 0) { this._openDefaultTab(); return; }

        // Supprimer le squelette initial
        const initialSkeleton = this.workspaceTabPanelsTarget.querySelector('.workspace-initial-skeleton');
        if (initialSkeleton) initialSkeleton.remove();

        // Reconstruire la barre d'onglets
        this.workspaceTabs = tabs;
        tabs.forEach(tabData => this._createTabStructure(tabData));

        // Activer l'onglet précédemment actif (lazy-load déclenché automatiquement)
        const targetId = (activeTabId && tabs.find(t => t.id === activeTabId)) ? activeTabId : tabs[tabs.length - 1].id;
        this._activateWorkspaceTabById(targetId);
    }

    /**
     * Ouvre l'onglet par défaut (tableau de bord).
     */
    _openDefaultTab() {
        if (this.hasDashboardItemTarget) {
            this.dashboardItemTarget.click();
        }
    }

    /**
     * Retourne le HTML du squelette de chargement pour un panneau d'onglet.
     */
    _workspaceSkeletonHtml() {
        return `<div class="workspace-skeleton">
            <div class="skeleton-header">
                <div class="skeleton-icon"></div>
                <div style="flex-grow:1;">
                    <div class="skeleton-line" style="width:40%;height:24px;"></div>
                    <div class="skeleton-line" style="width:80%;margin-top:10px;"></div>
                </div>
            </div>
            <div class="skeleton-line" style="height:40px;width:100%;margin-bottom:1rem;"></div>
            <div class="skeleton-line" style="height:40px;width:100%;margin-bottom:1rem;"></div>
            <div class="skeleton-line" style="height:40px;width:100%;margin-bottom:1rem;"></div>
            <div class="skeleton-row" style="height:300px;"></div>
        </div>`;
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
            title.setAttribute('aria-expanded', 'false');
        } else {
            content.classList.add('open');
            toggleIcon.textContent = '-';
            title.setAttribute('aria-expanded', 'true');
        }
    }
}