import { Controller } from '@hotwired/stimulus';

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
        "accordionSearchInput",
    ];

    // Cible pour l'élément de menu actuellement actif
    activeNavItem = null;
    activeRubriqueItem = null;

    connect() {
        this.nomControleur = "Espace de travail";
        this.restoreLastState(); // NOUVELLE MÉTHODE POUR LA RESTAURATION

        this.boundOpenTab = this.openTab.bind(this);
        document.addEventListener('app:liste-element:openned', this.boundOpenTab);

        // NOUVEAU : Écoute la réponse du Cerveau pour afficher le composant chargé.
        this.boundHandleComponentLoaded = this.handleComponentLoaded.bind(this);
        document.addEventListener('workspace:component.loaded', this.boundHandleComponentLoaded);

        // NOUVEAU : Écoute l'ordre du cerveau pour revenir au tableau de bord.
        this.boundLoadDefault = this.loadDefaultComponent.bind(this);
        document.addEventListener('app:workspace.load-default', this.boundLoadDefault);

        // --- NOUVEAU : Gestion de la barre de progression pour l'actualisation de la liste ---
        // Écoute la demande de rafraîchissement pour afficher la barre.
        this.boundHandleListRefreshRequest = this.handleListRefreshRequest.bind(this);
        document.addEventListener('app:list.refresh-request', this.boundHandleListRefreshRequest);
        // Écoute la fin du chargement des données pour cacher la barre.
        this.boundHandleListRefreshCompleted = this.handleListRefreshCompleted.bind(this);
        document.addEventListener('app:base-données:données-loaded', this.boundHandleListRefreshCompleted);
        this.accordionController = this.application.getControllerForElementAndIdentifier(this.element, 'accordion');
    }

    /**
     * NOUVEAU : Restaure le dernier état de la page.
     */
    restoreLastState() {
        const savedStateJSON = sessionStorage.getItem('lastActiveState');
        if (!savedStateJSON) {
            this.loadDefaultComponent();
            return;
        }

        const savedState = JSON.parse(savedStateJSON);

        // Cas 1 : C'est une rubrique (elle a un groupe parent)
        if (savedState.group) {
            const groupElement = this.element.querySelector(`[data-espace-de-travail-group-name-param='${savedState.group}']`);
            if (groupElement) {
                // On clique d'abord sur le groupe pour afficher les rubriques
                groupElement.click();

                // Ensuite, on cherche et clique sur la rubrique elle-même (qui est maintenant visible)
                // requestAnimationFrame s'assure que le DOM a eu le temps de se mettre à jour
                requestAnimationFrame(() => {
                    const rubriqueElement = this.contentZoneTarget.querySelector(`[data-espace-de-travail-component-name-param='${savedState.component}']`);
                    if (rubriqueElement) {
                        rubriqueElement.click();
                    } else {
                        this.loadDefaultComponent(); // Sécurité si la rubrique n'est pas trouvée
                    }
                });
            } else {
                this.loadDefaultComponent(); // Sécurité si le groupe n'est pas trouvé
            }
        }
        // Cas 2 : C'est un élément principal (Tableau de bord, Paramètres)
        else if (savedState.component) {
            const elementToClick = this.element.querySelector(`[data-espace-de-travail-component-name-param='${savedState.component}']`);
            if (elementToClick) {
                elementToClick.click();
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
     * NOUVEAU: Méthode pour charger le tableau de bord par défaut
     * afin d'éviter la répétition du code.
     */
    loadDefaultComponent() {
        if (this.hasDashboardItemTarget) {
            this.dashboardItemTarget.click();
        }
    }

    disconnect() {
        // --- CORRECTION : Nettoyage complet des écouteurs ---
        document.removeEventListener('app:liste-element:openned', this.boundOpenTab);
        document.removeEventListener('workspace:component.loaded', this.boundHandleComponentLoaded);
        document.removeEventListener('app:workspace.load-default', this.boundLoadDefault);
        document.removeEventListener('app:list.refresh-request', this.boundHandleListRefreshRequest);
        document.removeEventListener('app:base-données:données-loaded', this.boundHandleListRefreshCompleted);
    }


    /**
     * Filtre les éléments de l'accordéon en fonction de la saisie.
     * Déclenché par l'événement "input".
     */
    filterAccordion(event) {
        const input = event.currentTarget;
        const searchTerm = input.value.trim(); // Pas besoin de toLowerCase() ici
        const tabContent = input.closest('.tab-content');
        const accordion = tabContent.querySelector('.accordion');
        const noResultsMessage = tabContent.querySelector('.no-results-message');
        const items = accordion.querySelectorAll('.accordion-item');
        let visibleCount = 0;

        items.forEach(item => {
            const titleElement = item.querySelector('.accordion-title');
            const toggleIcon = titleElement.querySelector('.accordion-toggle');

            // On s'assure que l'icône existe avant de continuer
            if (!toggleIcon) return;

            // Étape 1 : Stocker le titre original PROPRE (sans l'icône) une seule fois.
            if (!titleElement.dataset.originalTitle) {
                // On clone l'élément, on enlève l'icône, puis on prend le texte restant.
                const tempClone = titleElement.cloneNode(true);
                tempClone.querySelector('.accordion-toggle').remove();
                titleElement.dataset.originalTitle = tempClone.textContent.trim();
            }

            const originalTitleText = titleElement.dataset.originalTitle;

            // La recherche se fait en ignorant la casse
            if (originalTitleText.toLowerCase().includes(searchTerm.toLowerCase())) {
                item.style.display = '';
                visibleCount++;

                // Étape 2 : Appliquer le surlignage uniquement si un terme est recherché
                if (searchTerm) {
                    // Regex pour trouver le terme de recherche sans être sensible à la casse
                    const regex = new RegExp(searchTerm.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&'), 'gi');
                    // '$&' dans la chaîne de remplacement réinsère la correspondance originale (préservant la casse)
                    const highlightedText = originalTitleText.replace(regex, `<strong class="search-highlight">$&</strong>`);
                    titleElement.innerHTML = `${toggleIcon.outerHTML} ${highlightedText}`;
                } else {
                    // Pas de terme de recherche, on restaure le titre original
                    titleElement.innerHTML = `${toggleIcon.outerHTML} ${originalTitleText}`;
                }
            } else {
                item.style.display = 'none';
            }
        });

        // Gérer le message "aucun résultat"
        noResultsMessage.style.display = visibleCount === 0 ? 'block' : 'none';
    }

    /**
     * Réinitialise le champ de recherche et le filtre.
     */
    resetFilter(event) {
        const input = event.currentTarget.closest('.accordion-search-bar').querySelector('.accordion-search-input');
        if (input) {
            input.value = '';
            // Déclenche manuellement l'événement 'input' pour mettre à jour la liste
            input.dispatchEvent(new Event('input'));
        }
    }

    /**
     * Donne le focus au champ de recherche quand on clique sur la barre.
     */
    focusSearch(event) {
        // Empêche le focus si on clique sur un bouton
        if (event.target.tagName.toLowerCase() === 'button' || event.target.closest('button')) {
            return;
        }
        event.currentTarget.querySelector('.accordion-search-input').focus();
    }



    /**
     * Gère la requête d'ouverture d'un élément de la liste principale.
     * @param {CustomEvent} event
     */
    openTab(event) {
        const { entity, entityType, entityCanvas } = event.detail;
        if (!entity || typeof entity !== 'object' || typeof entity.id === 'undefined' || entity.id === null) {
            console.error("Validation échouée : l'objet 'entity' est invalide ou ne contient pas d'ID.", event.detail);
            return;
        }
        if (!entityCanvas || typeof entityCanvas.parametres !== 'object' || !Array.isArray(entityCanvas.liste)) {
            console.error("Validation échouée : 'entityCanvas' n'a pas la bonne structure ({paramètres:{...}, liste:[...]}).", event.detail);
            return;
        }
        const existingTab = this.tabContainerTarget.querySelector(`[data-entity-id='${entity.id}'][data-entity-type='${entityType}']`);
        if (existingTab) {
            this.activateTab({ currentTarget: existingTab });
        } else {
            this.createTab(entity, entityType, entityCanvas);
        }

        this.element.classList.add('visualization-visible');
        this.visualizationColumnTarget.style.display = 'flex';
    }


    /**
     * Crée un nouvel onglet et son contenu.
     * @param {object} entity
     * @param {string} entityType
     * @param {object} entityCanvas - La nouvelle structure avec "paramètres" et "liste"
     */
    createTab(entity, entityType, entityCanvas) {
        // --- Création de l'en-tête de l'onglet ---
        const tabElement = this.tabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabElement.dataset.entityId = entity.id;
        tabElement.dataset.entityType = entityType;
        tabElement.querySelector('[data-role="tab-title"]').textContent = `#${entity.id}`;
        // --- NOUVELLE LOGIQUE D'ICÔNE PAR CLONAGE ---
        const params = entityCanvas.parametres;
        tabElement.title = params.description;
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

        this.dispatch('app:liste-element:openned', { entity: entity });
        console.log(this.nomControleur + " - Onglet ouvert:", entity);
    }

    /**
     * Crée un item (ligne) pour l'accordéon.
     * @param {object} attribute - La description de l'attribut depuis entityCanvas
     * @param {object} entity - L'objet de données
     * @returns {HTMLElement} L'élément DOM de l'item d'accordéon.
     */
    createAccordionItem(attribute, entity) {
        console.log(this.nomControleur + " - Données de l'attribut:", attribute);

        const item = document.createElement('div');
        item.className = 'accordion-item';
        const title = document.createElement('div');
        title.className = 'accordion-title';
        title.dataset.action = 'click->espace-de-travail#toggleAccordion';
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

                    link.dataset.action = "click->espace-de-travail#openRelatedEntity";
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

                            link.dataset.action = "click->espace-de-travail#openRelatedEntity";
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
            contentValueElement.dataset.controller = "tooltip-manager";
            contentValueElement.dataset.tooltipContent = attribute.description;
            // Optionnel: vous pouvez ajouter une classe pour indiquer que l'élément a un tooltip
            contentValueElement.classList.add('has-tooltip');
        }

        item.appendChild(title);
        item.appendChild(content);
        return item;
    }


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

            // Une fois les détails reçus, on déclenche l'événement standard d'ouverture d'onglet
            this.dispatch('app:liste-element:openned', details);

        } catch (error) {
            console.error("Impossible de charger les détails de l'entité liée :", error);
            // Afficher une notification d'erreur à l'utilisateur ici
        } finally {
            // 2. Cacher la barre de progression (toujours exécuté, même en cas d'erreur)
            this.progressBarTarget.style.display = 'none';
        }
    }

    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }


    /**
     * Active un onglet spécifique et affiche son contenu. [cite: 50]
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
     * Ferme un onglet et son contenu. [cite: 53]
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
     * Bascule l'affichage d'un item d'accordéon. [cite: 60]
     * @param {Event} event 
     */
    toggleAccordion(event) {
        const title = event.currentTarget;
        const content = title.nextElementSibling;
        const toggleIcon = title.querySelector('.accordion-toggle');

        // Vérifie si le panneau est déjà ouvert
        const isOpen = content.classList.contains('open');

        if (isOpen) {
            // Si oui, on le ferme
            content.classList.remove('open');
            toggleIcon.textContent = '+';
        } else {
            // Si non, on l'ouvre
            content.classList.add('open');
            toggleIcon.textContent = '-';
        }
    }


    /**
     * Formate une valeur en fonction de son type.
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
     * NOUVEAU: Affiche les rubriques pour un élément de groupe donné.
     * @param {HTMLElement} groupElement 
     */
    displayRubriquesForGroup(groupElement) {
        if (!groupElement || !groupElement.dataset.espaceDeTravailGroupNameParam) {
            this.contentZoneTarget.innerHTML = '';
            return;
        }
        const groupName = groupElement.dataset.espaceDeTravailGroupNameParam.replace(/ /g, '_');
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
        const description = event.currentTarget.dataset.espaceDeTravailDescriptionParam;
        this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
    }

    /**
     * MISE À JOUR: Utilise la nouvelle fonction pour afficher les rubriques.
     * @param {MouseEvent} event
     */
    showGroupRubriques(event) {
        const clickedElement = event.currentTarget;
        this.updateActiveState(clickedElement);
        this.displayRubriquesForGroup(clickedElement);
    }

    /**
     * LOGIQUE MISE À JOUR: Restaure l'état de l'élément actif au lieu d'effacer.
     * Déclenché lorsque la souris quitte un groupe.
     * @param {MouseEvent} event 
     */
    clearDescription(event) {
        if (this.activeNavItem) {
            // Si l'élément actif est un groupe, on réaffiche ses rubriques
            if (this.activeNavItem.dataset.espaceDeTravailGroupNameParam) {
                this.displayRubriquesForGroup(this.activeNavItem);
            }
            // Si c'est un autre type d'élément (Tableau de bord, Paramètres), on réaffiche sa description
            else if (this.activeNavItem.dataset.espaceDeTravailDescriptionParam) {
                const description = this.activeNavItem.dataset.espaceDeTravailDescriptionParam;
                this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
            }
        } else {
            // S'il n'y a aucun élément actif, on vide la zone.
            this.contentZoneTarget.innerHTML = '';
        }
    }

    /**
     * Charge un composant Twig dans l'espace de travail.
     * @param {MouseEvent} event
     */
    async loadComponent(event) {
        const clickedElement = event.currentTarget;
        const componentName = clickedElement.dataset.espaceDeTravailComponentNameParam;
        const description = clickedElement.dataset.espaceDeTravailDescriptionParam;

        if (!componentName) return;

        if (description) {
            this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
        }

        this.dispatchRequestEvent(clickedElement.dataset);
        this.progressBarTarget.style.display = 'block';

        buildCustomEventForElement(document, 'cerveau:event', true, true,
            {
                type: 'ui:component.load',
                source: 'espace-de-travail',
                payload: { componentName: componentName },
                timestamp: Date.now()
            }
        );
        console.log(this.nomControleur + " - cerveau:event envoyé.");

        // La sauvegarde de l'état est faite immédiatement après le clic.
        // Le chargement effectif du contenu sera géré par handleComponentLoaded.
        if (componentName) {
            const stateToSave = {
                component: componentName,
                group: clickedElement.dataset.espaceDeTravailGroupNameParam || null
            };
            sessionStorage.setItem('lastActiveState', JSON.stringify(stateToSave));
        }

        this.updateActiveState(clickedElement);
    }

    /**
     * NOUVEAU : Gère la réception du HTML du composant chargé par le Cerveau.
     * @param {CustomEvent} event 
     */
    handleComponentLoaded(event) {
        const { html, error } = event.detail;

        if (error) {
            console.error("Erreur lors du chargement du composant via le Cerveau:", error);
            this.workspaceTarget.innerHTML = `<div class="p-8 text-red-500">Impossible de charger le contenu : ${error}</div>`;
        } else {
            this.workspaceTarget.innerHTML = html;
            this.dispatchOpenedEvent();
        }

        this.progressBarTarget.style.display = 'none';
    }

    /**
     * NOUVEAU : Affiche la barre de progression lors d'une demande d'actualisation de liste.
     */
    handleListRefreshRequest() {
        console.log(this.nomControleur + " - Demande d'actualisation détectée, affichage de la barre de progression.");
        this.progressBarTarget.style.display = 'block';
    }

    /**
     * NOUVEAU : Cache la barre de progression une fois les données de la liste chargées.
     */
    handleListRefreshCompleted() {
        console.log(this.nomControleur + " - Fin du chargement des données, masquage de la barre de progression.");
        this.progressBarTarget.style.display = 'none';
    }

    /**
     * Met à jour l'état visuel (gras, couleur) de l'élément de menu sélectionné.
     * @param {HTMLElement} currentElement L'élément qui vient d'être cliqué.
     */
    updateActiveState(currentElement) {
        if (currentElement.closest('.menu-col-1')) {
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
        // Gérer les rubriques de la colonne 2
        else if (currentElement.closest('.menu-col-2')) {
            if (this.activeRubriqueItem) {
                this.activeRubriqueItem.classList.remove('active');
            }
            currentElement.classList.add('active');
            this.activeRubriqueItem = currentElement;
        }
    }


    /**
     * [cite_start]Dispatch un CustomEvent pour annoncer une demande d'ouverture. [cite: 243]
     * @param {object} detail Les données à propager.
     */
    dispatchRequestEvent(detail) {
        this.dispatch('app:navigation-rubrique:open-request', {
            nom: detail.espaceDeTravailGroupNameParam,
            description: detail.espaceDeTravailDescriptionParam,
            composant_twig: detail.espaceDeTravailComponentNameParam
        });
    }

    dispatchOpenedEvent() {
        this.dispatch('app:navigation-rubrique:openned');
    }
}