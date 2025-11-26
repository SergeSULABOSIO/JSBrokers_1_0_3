import { Controller } from '@hotwired/stimulus';
import { } from './base_controller.js';

/**
 * @file Ce fichier contient le contrôleur Stimulus 'cerveau'.
 * @description Ce contrôleur implémente le patron de conception Médiateur (Mediator Pattern).
 * Il agit comme le hub de communication central pour toute l'application, recevant des événements
 * de divers composants et orchestrant les réponses appropriées. Il ne doit pas être attaché à un
 * composant d'UI spécifique mais plutôt à un élément global comme `<body>`.
 */

/**
 * @class CerveauController
 * @extends Controller
 * @description Le contrôleur Cerveau est le médiateur central de l'application.
 */
export default class extends Controller {
    /**
     * Méthode du cycle de vie de Stimulus. S'exécute lorsque le contrôleur est connecté au DOM.
     * Met en place l'écouteur d'événement principal `cerveau:event`.
     */
    connect() {
        window.logSequence = window.logSequence || 0; // Initialise le compteur de log global
        this.nomControleur = "Cerveau";
        this.selectionState = []; // Tableau des objets "selecto"
        this.selectionIds = new Set(); // Pour une recherche rapide des IDs
        this.numericAttributesAndValues = {}; // Stocke l'objet complet {colonnes, valeurs}
        this.activeTabFormCanvas = null; // NOUVEAU : Pour stocker le formCanvas de l'onglet actif.
        this.currentIdEntreprise = null;
        this.displayState = {
            rubricName: 'Tableau de bord',
            action: 'Initialisation',
            result: 'Prêt',
            selectionCount: 0
        };
        this.currentIdInvite = null;
        this.activeParentId = null; // NOUVEAU : Pour stocker l'ID du parent de l'onglet actif.
        console.log(`[${++window.logSequence}] ${this.nomControleur} 🧠 Cerveau prêt à orchestrer.`);
        this.boundHandleEvent = this.handleEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleEvent);
    }

    /**
     * Méthode du cycle de vie de Stimulus. Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('cerveau:event', this.boundHandleEvent);
    }


    
    /**
     * Point d'entrée unique pour tous les événements destinés au Cerveau.
     * Analyse le type d'événement et délègue l'action appropriée.
     * @param {CustomEvent} event - L'événement personnalisé reçu.
     * @property {object} event.detail - Le conteneur de données de l'événement.
     * @property {string} event.detail.type - Le type d'action demandé (ex: 'ui:component.load').
     * @property {string} event.detail.source - Le nom du contrôleur qui a émis l'événement.
     * @property {object} event.detail.payload - Les données spécifiques à l'événement.
     * @property {number} event.detail.timestamp - L'horodatage de l'émission de l'événement.
     */
    handleEvent(event) {
        const { type, source, payload, timestamp } = event.detail;
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - handleEvent - Code: 100 - Données:`, { type, source, payload });

        // Validation de base de l'événement
        if (!type || !source || !payload || !timestamp) {
            console.error("🧠 [Cerveau] Événement invalide reçu. Structure attendue: {type, source, payload, timestamp}", event.detail);
            return;
        }

        switch (type) {
            case 'ui:component.load': // Utilisé pour charger une rubrique dans l'espace de travail
                this.displayState.rubricName = payload.entityName || 'Inconnu';
                this.loadWorkspaceComponent(payload.componentName, payload.entityName, payload.idEntreprise, payload.idInvite);
                break;
            case 'app:context.initialized':
                this._setApplicationContext(payload);
                break;
            case 'app:error.api':
                this._showNotification('Une erreur serveur est survenue. Veuillez réessayer.', 'error');
                break;
            case 'ui:tab.context-changed':
                this.tabId = payload.tabId;
                this.activeParentId = payload.parentId || null; // NOUVEAU : Mémoriser l'ID du parent.
                this.broadcast('app:context.changed', {
                    tabId: this.tabId,
                    parentId: this.activeParentId,
                });
                break;
            case 'dialog:boite-dialogue:init-request':
            case 'ui:boite-dialogue:add-collection-item-request':
                this.broadcast('app:loading.start');
                this.openDialogBox(payload);
                break;
            case 'ui:dialog.opened':
                this.broadcast('app:loading.stop');
                break;
            case 'app:form.validation-error':
                this._showNotification(payload.message || 'Erreur de validation.', 'error');
                break;
            case 'app:base-données:sélection-request':
                console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 - Recherche`, payload);
                const criteriaText = Object.keys(payload.criteria || {}).length > 0 
                    ? `Filtre actif` 
                    : 'Recherche par défaut';
                this.broadcast('app:loading.start');
                this.broadcast('app:list.refresh-request', payload);
                break;
            case 'ui:context-menu.request':
                this.broadcast('app:context-menu.show', payload);
                break;
            case 'dialog:confirmation.request':
                this._requestDeleteConfirmation(payload);
                break;
            case 'ui:toolbar.open-request':
                this.broadcast('app:loading.start');
                this._handleOpenRequest(payload);
                break;
            case 'app:navigation-rubrique:openned':
                this.broadcast('app:navigation-rubrique:openned', payload);
                break;
            case 'ui:list.selection-completed':
                this._setSelectionState(payload.selectos || []);
                break;
            case 'app:loading.start':
                this.broadcast('app:loading.start', payload);
                break;
            case 'app:loading.stop':
                this.broadcast('app:loading.stop', payload);
                break;
            case 'ui:dialog.closed':
                break;
            default:
                console.warn(`-> ATTENTION: Aucun gestionnaire défini pour l'événement "${type}".`);
        }
    }


    /**
     * Gère une demande d'ouverture d'éléments en diffusant un événement pour chaque entité sélectionnée.
     * @param {object} payload - Le payload contenant le tableau `entities`.
     * @param {Array} payload.entities - Tableau d'objets "selecto".
     * @private
     */
    _handleOpenRequest(payload) {
        if (payload.entities && payload.entities.length > 0) {
            payload.entities.forEach(selecto => {
                this.broadcast('app:liste-element:openned', selecto);
            });
        }
    }


    openDialogBox(payload) {
        console.groupCollapsed(`[${++window.logSequence}] ${this.nomControleur} - handleEvent - EDITDIAL(1)`);
        console.log(`| Mode: ${payload.isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', payload.entity);
        console.log('| Canvas:', payload.entityFormCanvas);
        console.groupEnd();

        this.broadcast('app:boite-dialogue:init-request', {
            entity: payload.entity, // Entité vide pour le mode création
            entityFormCanvas: payload.entityFormCanvas,
            isCreationMode: payload.isCreationMode, // Correction: isCreationMode au lieu de isCreateMode
            context: {
                ...payload.context,
                idEntreprise: this.currentIdEntreprise, // CORRECTION : Utiliser la propriété correcte
                idInvite: this.currentIdInvite       // CORRECTION : Utiliser la propriété correcte
            }, 
            parentContext: this.activeParentId ? {
                id: this.activeParentId,
                fieldName: payload.entityFormCanvas && payload.entityFormCanvas.parametres && payload.entityFormCanvas.parametres.parent_entity_field_name
            } : null
        });
    }

    /**
     * Définit un nouvel état de sélection complet et le publie.
     * @param {Array} [selectos=[]] - Le nouveau tableau d'objets "selecto".
     * @private
     */
    _setSelectionState(selectos = []) {
        this.selectionState = selectos;
        this.selectionIds = new Set(this.selectionState.map(s => s.id));
    }
}