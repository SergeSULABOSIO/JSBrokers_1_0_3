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
        actions:     Array,
        bordereauId: Number,
        iconPrefix: String, // Préfixe unique pour les requêtes d'icônes
        idEntreprise: Number,
        idInvite: Number,
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
        const { html, requesterId } = event.detail;

        // On vérifie si l'événement nous est bien destiné.
        if (!requesterId || !requesterId.startsWith(this.iconPrefixValue)) {
            return;
        }

        // On extrait l'index du bouton depuis le requesterId.
        const parts = requesterId.split('-');
        const buttonIndex = parseInt(parts[parts.length - 1], 10);

        // On cible le bon conteneur d'icône.
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
     * Lance la simulation du traitement de la ligne.
     */
    async handleAction(event) {
        event.stopPropagation();
        const button = event.currentTarget;
        const eventName = button.dataset.eventName;
        const payload   = JSON.parse(button.dataset.payload || '{}');

        console.log('[AnalysisResultItem] handleAction() - Action cliquée:', {
            eventName,
            payload
        });

        // Désactiver tous les boutons de cet item pendant le traitement
        this.actionButtonTargets.forEach(btn => btn.disabled = true);

        // NOUVEAU : Notifier le parent du début de l'opération (pour barre de progression)
        console.log('[AnalysisResultItem] handleAction() - Envoi de action:start');
        this.dispatch('action:start');

        try {
            // Appel à la route de simulation
            // TODO: Quand la vraie logique métier sera implémentée, cette URL
            //       pointera vers la même route mais le serveur effectuera
            //       réellement la création ou la mise à jour en base.
            const bordereauId = this.bordereauIdValue;
            const response = await fetch(
                `/admin/bordereau/api/simulate-action/${bordereauId}`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action_type:       payload.avenant_id ? 'discrepancy' : 'new',
                        avenant_id:        payload.avenant_id ?? null,
                        excel_data:        payload.excel_data ?? {},
                        row_index:         payload.row_index ?? null,
                        reference_police:  payload.reference_police ?? null,
                        idEntreprise:      this.idEntrepriseValue,
                        idInvite:          this.idInviteValue,
                    })
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(result.message || 'Erreur lors du traitement.');
            }

            console.log('[AnalysisResultItem] handleAction() - Succès:', result.message);

            // Marquer visuellement cet item comme résolu
            this._markAsResolved(result.message);

            // Notifier le contrôleur parent qu'un item est résolu
            // pour qu'il puisse réévaluer le bouton "Valider"
            this.notifyCerveau('bordereau:item.resolved', {
                bordereauId: bordereauId
            });

            // NOUVEAU : Notifier le parent de la réussite (pour Toast individuel)
            console.log('[AnalysisResultItem] handleAction() - Envoi de action:completed (success)');
            this.dispatch('action:completed', { 
                success: true, 
                message: result.message 
            });

        } catch (error) {
            console.log('[AnalysisResultItem] handleAction() - Envoi de action:completed (error)');
            console.error('[AnalysisResultItem] handleAction() - Erreur:', error);
            // Réactiver les boutons en cas d'erreur
            this.actionButtonTargets.forEach(btn => { if(btn) btn.disabled = false; });
            // Afficher un feedback d'erreur sur l'item
            this._showActionError(error.message);

            // NOUVEAU : Notifier le parent de l'échec (pour Toast individuel)
            this.dispatch('action:completed', { 
                success: false, 
                message: error.message 
            });
        }
    }

    /**
     * Marque visuellement un item comme résolu après une action réussie.
     * @param {string} message - Message de confirmation du serveur.
     */
    _markAsResolved(message) {
        // Changer la couleur de la bordure gauche de l'item
        this.element.classList.remove('border-info', 'border-warning', 'border-danger');
        this.element.classList.add('border-success', 'analysis-item-resolved');

        // Remplacer le texte de détails par un message de succès
        const detailsText = this.element.querySelector('.analysis-item-header p');
        if (detailsText) {
            detailsText.innerHTML = `<span class="text-success fw-bold">✓ Traité — ${message}</span>`;
        }

        // Masquer les boutons d'action (inutiles après traitement)
        this.actionsContainerTarget.style.display = 'none';

        // Ajouter un badge "Résolu" dans le titre
        const titleWrapper = this.element.querySelector('.analysis-item-title-wrapper h5');
        if (titleWrapper) {
            const badge = document.createElement('span');
            badge.className = 'badge bg-success ms-2 small';
            badge.textContent = 'Résolu';
            titleWrapper.appendChild(badge);
        }

        // Marquer l'élément DOM comme résolu pour le comptage
        this.element.dataset.resolved = 'true';
    }

    /**
     * Affiche un message d'erreur sur l'item en cas d'échec de l'action.
     * @param {string} message
     */
    _showActionError(message) {
        const detailsText = this.element.querySelector('.analysis-item-header p');
        if (detailsText) {
            detailsText.innerHTML = `<span class="text-danger small">⚠ Erreur : ${message} — Veuillez réessayer.</span>`;
        }
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