import BaseController from './base_controller.js';

/**
 * @class AnalysisResultItemController
 * @description Gère l'affichage et les interactions d'une seule ligne de résultat d'analyse.
 * Ce composant est autonome et gère son propre état (déplié/replié) et ses actions.
 */
export default class extends BaseController {
    static targets = [
        "detailsPanel",
        "chevronIcon",
        "actionsContainer",
        "actionButton",
        "actionIcon"
    ];

    static values = {
        actions: Array,
        bordereauId: Number, // NOUVEAU : Pour créer un requesterId unique
        iconPrefix: String, // Préfixe unique pour les requêtes d'icônes
    };

    connect() {
        this.isDetailsVisible = false;
        this.tooltipElement = null;

        // Lier les gestionnaires d'événements pour les infobulles
        this.boundShowTooltip = this.showTooltip.bind(this);
        this.boundHideTooltip = this.hideTooltip.bind(this);

        // NOUVEAU : Écouteur pour la réception du HTML de l'icône.
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('analysis:icon.loaded', this.boundHandleIconLoaded);

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
        document.removeEventListener('analysis:icon.loaded', this.boundHandleIconLoaded);
        if (this.tooltipElement) {
            this.tooltipElement.remove();
        }
    }

    /**
     * Charge les icônes pour les boutons d'action via des événements.
     */
    loadActionIcons() {
        const actionIconMap = {
            'Créer l\'avenant': 'action:add',
            'Mettre à jour': 'action:edit'
        };

        this.actionButtonTargets.forEach((button, index) => {
            const label = button.dataset.tooltipText;
            const iconAlias = actionIconMap[label] || 'action:information';
            
            // NOUVEAU : On envoie un événement au contrôleur parent pour demander l'icône.
            console.log(`%c[Enfant ${this.iconPrefixValue}] 1. Envoi de 'analysis:icon.request' pour l'icône '${iconAlias}'`, 'color: blue;');
            this.dispatch('analysis:icon.request', {
                iconName: iconAlias,
                iconSize: 21, // Taille augmentée de 30% pour une meilleure visibilité
                // On crée un ID de demandeur unique et valide.
                requesterId: `${this.iconPrefixValue}-action-${index}`
            });
        });
    }

    /**
     * NOUVEAU : Gère la réception du HTML de l'icône et l'injecte.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event) {
        console.log(`%c[Enfant ${this.iconPrefixValue}] 2. Reçu 'analysis:icon.loaded'`, 'color: green;', event.detail);
        const { html, requesterId } = event.detail;

        // On vérifie si l'événement nous est bien destiné.
        if (!requesterId || !requesterId.startsWith(this.iconPrefixValue)) {
            console.log(`%c[Enfant ${this.iconPrefixValue}] 2a. Ignoré : requesterId (${requesterId}) ne correspond pas.`, 'color: gray;');
            return;
        }

        // On extrait l'index du bouton depuis le requesterId.
        const parts = requesterId.split('-');
        const buttonIndex = parseInt(parts[parts.length - 1], 10);

        // On cible le bon conteneur d'icône.
        console.log(`%c[Enfant ${this.iconPrefixValue}] 3. Injection de l'icône dans la cible n°${buttonIndex}`, 'color: green;');
        const targetElement = this.actionIconTargets[buttonIndex]; // CORRECTION: Utiliser le tableau `actionIconTargets`

        if (targetElement && html && !html.trim().startsWith('<!--')) {
            targetElement.innerHTML = ''; // Vider avant d'ajouter
            // On utilise un <template> pour parser le HTML de manière sûre.
            const template = document.createElement('template');
            template.innerHTML = html.trim();
            if (template.content.firstChild) {
                template.content.firstChild.classList.add('analysis-action-svg-icon'); // Ajoute une classe spécifique à l'icône SVG
                targetElement.appendChild(template.content.firstChild);
            }
        }
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
        // NOUVEAU : On notifie le cerveau de l'action.
        this.notifyCerveau(button.dataset.eventName, JSON.parse(button.dataset.payload));
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