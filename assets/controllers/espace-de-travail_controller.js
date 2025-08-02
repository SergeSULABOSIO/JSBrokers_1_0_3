import { Controller } from '@hotwired/stimulus';
import { EVEN_NAVIGATION_RUBRIQUE_OPEN_REQUEST, EVEN_NAVIGATION_RUBRIQUE_OPENNED } from './base_controller.js';

export default class extends Controller {
    static targets = ["progressBar", "contentZone", "workspace", "rubriquesTemplate"];

    // Cible pour l'élément de menu actuellement actif
    activeNavItem = null;
    activeRubriqueItem = null;

    connect() {
        this.nomControleur = "Espace de travail";
        // Logique à exécuter lorsque le contrôleur est attaché au DOM
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
            // exe: https://127.0.0.1:8000/espace/de/travail/component/api/load-component?component=_taxes_component.html.twig
            var url = "/espace/de/travail/component/api/load-component?component=" + componentName;
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
        console.log(this.nomControleur + " - updateActiveState:", currentElement);
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
        console.log(this.nomControleur + " - Even lancé: " + EVEN_NAVIGATION_RUBRIQUE_OPENNED);
    }
}