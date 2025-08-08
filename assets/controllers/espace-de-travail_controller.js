import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_LISTE_ELEMENT_OPEN_REQUEST, EVEN_LISTE_ELEMENT_OPENNED, EVEN_NAVIGATION_RUBRIQUE_OPEN_REQUEST, EVEN_NAVIGATION_RUBRIQUE_OPENNED } from './base_controller.js';

export default class extends Controller {
    static targets = [
        "progressBar",
        "contentZone",
        "workspace",
        "rubriquesTemplate",
        "dashboardItem",
        // Nouveaux targets pour le panneau à onglets
        "visualizationColumn",
        "tabContainer",
        "tabContentContainer",
        "tabTemplate",
        "tabContentTemplate",
    ];

    // [MODIFICATION 3] Ajouter les nouvelles values
    // static values = {
    //     entityCanvas: Array
    // }

    // Cible pour l'élément de menu actuellement actif
    activeNavItem = null;
    activeRubriqueItem = null;

    connect() {
        this.nomControleur = "Espace de travail";
        // Logique à exécuter lorsque le contrôleur est attaché au DOM
        // 2. Vérifiez si la cible existe et simulez un clic dessus
        // Ceci va automatiquement déclencher votre méthode loadComponent
        if (this.hasDashboardItemTarget) {
            this.dashboardItemTarget.click();
        }

        this.boundHandleOpenRequest = this.handleOpenRequest.bind(this);
        // [MODIFICATION 4] Ajouter l'écouteur d'événement pour l'ouverture des entités [cite: 7, 23, 745]
        document.addEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);

        // Initialiser un contrôleur pour l'accordéon
        this.accordionController = this.application.getControllerForElementAndIdentifier(this.element, 'accordion');
    }

    disconnect() {
        document.removeEventListener(EVEN_LISTE_ELEMENT_OPEN_REQUEST, this.boundHandleOpenRequest);
    }


    /**
     * Gère la requête d'ouverture d'un élément de la liste principale.
     * @param {CustomEvent} event
     */
    handleOpenRequest(event) {
        // Get both the entity and its canvas from the event detail
        const { entity, entityType, entityCanvas } = event.detail;
        // --- Validation améliorée ---
        // 1. Valider l'entité
        if (!entity || typeof entity !== 'object' || typeof entity.id === 'undefined' || entity.id === null) {
            console.error("Validation échouée : l'objet 'entity' est invalide ou ne contient pas d'ID.", event.detail);
            return;
        }

        // 2. Valider le canvas de l'entité
        if (!Array.isArray(entityCanvas)) {
            console.error("Validation échouée : 'entityCanvas' n'est pas un tableau (Array).", event.detail);
            return;
        }

        // const entityId = entity.id;
        // const entityType = entity.__class_name__; // Supposant que le nom de la classe est transmis

        // Vérifier si un onglet pour cet objet existe déjà 
        const existingTab = this.tabContainerTarget.querySelector(`[data-entity-id='${entity.id}'][data-entity-type='${entityType}']`);

        if (existingTab) {
            // Si l'onglet existe, on l'active simplement [cite: 47]
            this.activateTab({ currentTarget: existingTab });
        } else {
            // Pass the canvas from the event directly to the createTab method
            this.createTab(entity, entityType, entityCanvas);
        }

        // Afficher la colonne de visualisation si elle est cachée [cite: 26]
        this.element.classList.add('visualization-visible');
        this.visualizationColumnTarget.style.display = 'flex';
    }


    /**
     * Crée un nouvel onglet et son contenu.
     * @param {object} entity 
     * @param {string} entityType 
     */
    createTab(entity, entityType, entityCanvas) {
        // --- Création de l'en-tête de l'onglet ---
        const tabElement = this.tabTemplateTarget.content.cloneNode(true).firstElementChild;
        tabElement.dataset.entityId = entity.id;
        tabElement.dataset.entityType = entityType;
        tabElement.querySelector('[data-role="tab-title"]').textContent = `#${entity.id}`;

        // --- Création du contenu de l'onglet (accordéon) ---
        const contentElement = this.tabContentTemplateTarget.content.cloneNode(true).firstElementChild;
        const accordionContainer = contentElement.querySelector('.accordion');

        if (entityCanvas.length > 0) {
            entityCanvas.forEach(attribute => {
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

        // Émettre l'événement de confirmation d'ouverture [cite: 10, 747]
        // const detailsOpenedEvent = new CustomEvent(EVEN_LISTE_ELEMENT_OPENNED, {
        //     bubbles: true,
        //     detail: { entity }
        // });
        // document.dispatchEvent(detailsOpenedEvent);

        buildCustomEventForElement(document, EVEN_LISTE_ELEMENT_OPENNED, true, true, { entity: entity })
    }

    /**
     * Crée un item (ligne) pour l'accordéon.
     * @param {object} attribute - La description de l'attribut depuis entityCanvas
     * @param {object} entity - L'objet de données
     */
    createAccordionItem(attribute, entity) {
        const item = document.createElement('div');
        item.className = 'accordion-item';

        const title = document.createElement('div');
        title.className = 'accordion-title';
        title.dataset.action = 'click->espace-de-travail#toggleAccordion';
        // Titre en gras, avec préfixe "-" par défaut (déplié) [cite: 61, 62, 79]
        title.innerHTML = `<span class="accordion-toggle">-</span> ${attribute.intitule}`;

        const content = document.createElement('div');
        content.className = 'accordion-content';
        // Contenu visible par défaut [cite: 79]
        content.style.display = 'block';

        const rawValue = entity[attribute.code];
        content.innerHTML = this.formatValue(rawValue, attribute.type, attribute.unite); // [cite: 66, 68, 69]

        item.appendChild(title);
        item.appendChild(content);
        return item;
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

        const tabToClose = event.currentTarget.closest('.tab-item');
        const contentToClose = this.tabContentContainerTarget.querySelector(`#${tabToClose.dataset.tabContentId}`);

        // Supprimer l'onglet et son contenu du DOM
        if (tabToClose) tabToClose.remove();
        if (contentToClose) contentToClose.remove();

        // S'il ne reste plus d'onglets, cacher la colonne de visualisation [cite: 26, 31]
        if (this.tabContainerTarget.children.length === 0) {
            this.element.classList.remove('visualization-visible');
            this.visualizationColumnTarget.style.display = 'none';
        } else {
            // Activer le premier onglet restant s'il y en a un
            const firstTab = this.tabContainerTarget.querySelector('.tab-item');
            if (firstTab) {
                this.activateTab({ currentTarget: firstTab });
            }
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

        if (content.style.display === "block") {
            content.style.display = "none";
            toggleIcon.textContent = '+'; // [cite: 61]
        } else {
            content.style.display = "block";
            toggleIcon.textContent = '-'; // [cite: 61]
        }
    }


    /**
     * Formate une valeur en fonction de son type.
     */
    formatValue(value, type, unit = '') {
        if (value === null || typeof value === 'undefined') return 'N/A';

        const unitePrefix = unit ? `${unit} ` : ''; // [cite: 67]

        switch (type) {
            case 'Nombre': // [cite: 66]
                return unitePrefix + new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
            case 'Date': // [cite: 68]
                // Gérer les dates sous forme de string (ex: "2025-06-30T00:00:00+00:00") ou d'objet
                const date = new Date(value);
                if (isNaN(date.getTime())) {
                    return value; // Retourner la valeur originale si la date est invalide
                }
                return date.toLocaleDateString('fr-FR'); // ex: 30/06/2025
            case 'Texte': // [cite: 69]
            default:
                return value;
        }
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
     * [cite_start]Charge un composant Twig dans l'espace de travail. [cite: 233]
     * @param {MouseEvent} event
     */
    async loadComponent(event) {
        event.preventDefault();

        // 1. Sauvegarder l'élément cliqué immédiatement dans une variable.
        const clickedElement = event.currentTarget;
        const componentName = clickedElement.dataset.espaceDeTravailComponentNameParam;
        const description = clickedElement.dataset.espaceDeTravailDescriptionParam;

        if (!componentName) return;
        // On utilise la même classe CSS ici aussi
        if (description) {
            this.contentZoneTarget.innerHTML = `<div class="description-wrapper">${description}</div>`;
        }

        // 1. Dispatcher l'événement de début de chargement et afficher la barre de progression [cite: 243, 246, 235]
        this.dispatchRequestEvent(clickedElement.dataset);
        this.progressBarTarget.style.display = 'block';

        try {
            // exe: https://127.0.0.1:8000/espacedetravail/api/load-component?component=_taxes_component.html.twig
            var url = "/espacedetravail/api/load-component?component=" + componentName;
            console.log(this.nomControleur + " - loadComponent:", url);
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Erreur du serveur: ${response.statusText}`);
            }
            const html = await response.text();
            // 2. Charger le contenu dans l'espace de travail [cite: 228]
            this.workspaceTarget.innerHTML = html;
            // 2. Utiliser la variable sauvegardée au lieu de event.currentTarget.
            this.updateActiveState(clickedElement);
            // 3. Dispatcher l'événement de fin de chargement et cacher la barre de progression [cite: 247, 248, 236]
            this.dispatchOpenedEvent();

        } catch (error) {
            console.error("Erreur lors du chargement du composant:", error);
            this.workspaceTarget.innerHTML = `<div class="p-8 text-red-500">Impossible de charger le contenu.</div>`;
        } finally {
            this.progressBarTarget.style.display = 'none';
        }
    }

    /**
     * Met à jour l'état visuel (gras, couleur) de l'élément de menu sélectionné.
     * @param {HTMLElement} currentElement L'élément qui vient d'être cliqué.
     */
    updateActiveState(currentElement) {
        // console.log(this.nomControleur + " - updateActiveState:", currentElement);
        // Gérer les éléments de la colonne 1
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
        const event = new CustomEvent(EVEN_NAVIGATION_RUBRIQUE_OPEN_REQUEST, {
            bubbles: true,
            detail: {
                nom: detail.espaceDeTravailGroupNameParam,// || detail.textContent.trim(),
                description: detail.espaceDeTravailDescriptionParam,
                composant_twig: detail.espaceDeTravailComponentNameParam
            }
        });
        document.dispatchEvent(event);
    }

    /**
     * [cite_start]Dispatch un CustomEvent pour annoncer la fin du chargement. [cite: 247]
     */
    dispatchOpenedEvent() {
        const event = new CustomEvent(EVEN_NAVIGATION_RUBRIQUE_OPENNED, { bubbles: true });
        document.dispatchEvent(event);
        // console.log(this.nomControleur + " - Even lancé: " + EVEN_NAVIGATION_RUBRIQUE_OPENNED);
    }
}