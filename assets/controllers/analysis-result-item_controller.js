import { Controller } from '@hotwired/stimulus';

/**
 * @class AnalysisResultItemController
 * @description Gère l'affichage et les interactions d'une seule ligne de résultat d'analyse.
 * Ce composant est autonome et gère son propre état (déplié/replié) et ses actions.
 */
export default class extends Controller {
    static targets = [
        "detailsPanel",
        "chevronIcon",
        "actionsContainer",
        "actionButton",
        "actionIcon"
    ];

    static values = {
        actions: Array,
        iconPrefix: String, // Préfixe unique pour les requêtes d'icônes
    };

    connect() {
        this.isDetailsVisible = false;
        this.tooltipElement = null;

        // Lier les gestionnaires d'événements pour les infobulles
        this.boundShowTooltip = this.showTooltip.bind(this);
        this.boundHideTooltip = this.hideTooltip.bind(this);

        this.actionButtonTargets.forEach(button => {
            button.addEventListener('mouseenter', this.boundShowTooltip);
            button.addEventListener('mouseleave', this.boundHideTooltip);
        });

        this.loadActionIcons();
    }

    disconnect() {
        this.actionButtonTargets.forEach(button => {
            button.removeEventListener('mouseenter', this.boundShowTooltip);
            button.removeEventListener('mouseleave', this.boundHideTooltip);
        });
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
    }

    /**
     * Charge les icônes pour les boutons d'action via des événements.
     */
    loadActionIcons() {
        const actionIconMap = {
            'Créer l\'avenant': 'lucide:plus-circle',
            'Mettre à jour': 'lucide:edit'
        };

        this.actionButtonTargets.forEach((button, index) => {
            const label = button.dataset.tooltipText;
            const iconName = actionIconMap[label] || 'lucide:help-circle';
            const iconContainer = this.actionIconTargets[index];

            // Utilise un template pour injecter l'icône de manière sécurisée
            const iconHtml = `<twig:UX:Icon name="${iconName}" width="16px" height="16px" />`;
            const template = document.createElement('template');
            template.innerHTML = iconHtml;
            iconContainer.innerHTML = ''; // Vider avant d'ajouter
            iconContainer.appendChild(template.content.firstChild);
        });
    }

    /**
     * Affiche ou masque le panneau de détails (comportement d'accordéon).
     */
    toggleDetails() {
        this.isDetailsVisible = !this.isDetailsVisible;
        this.detailsPanelTarget.classList.toggle('is-open', this.isDetailsVisible);
        this.chevronIconTarget.classList.toggle('is-rotated', this.isDetailsVisible);
    }

    /**
     * Affiche le conteneur des boutons d'action.
     */
    showActions() {
        this.actionsContainerTarget.classList.add('is-visible');
    }

    /**
     * Masque le conteneur des boutons d'action.
     */
    hideActions() {
        this.actionsContainerTarget.classList.remove('is-visible');
    }

    /**
     * Gère le clic sur un bouton d'action.
     * Pour l'instant, affiche simplement les informations dans la console.
     */
    handleAction(event) {
        event.stopPropagation(); // Empêche le déclenchement de toggleDetails
        const button = event.currentTarget;
        console.log("Action cliquée :", {
            eventName: button.dataset.eventName,
            payload: JSON.parse(button.dataset.payload)
        });
    }

    // Les méthodes showTooltip et hideTooltip sont copiées de collection_controller.js
    // pour rendre ce composant autonome.
    showTooltip(event) {
        const target = event.currentTarget;
        const tooltipText = target.dataset.tooltipText;
        if (!tooltipText) return;
        this.tooltipElement = document.createElement('div');
        this.tooltipElement.className = 'canvas-tooltip is-visible'; // Directement visible
        this.tooltipElement.textContent = tooltipText;
        document.body.appendChild(this.tooltipElement);
        const targetRect = target.getBoundingClientRect();
        this.tooltipElement.style.left = `${targetRect.left + (targetRect.width / 2) - (this.tooltipElement.offsetWidth / 2)}px`;
        this.tooltipElement.style.top = `${targetRect.top - this.tooltipElement.offsetHeight - 5}px`;
    }

    hideTooltip() {
        if (this.tooltipElement) {
            this.tooltipElement.remove();
            this.tooltipElement = null;
        }
    }
}