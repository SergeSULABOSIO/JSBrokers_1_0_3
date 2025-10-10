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
        this.nomControleur = "Cerveau";
        // --- NOUVELLE ARCHITECTURE : Le Cerveau devient la source de vérité pour la sélection ---
        this.selectionState = []; // Tableau des objets "selecto"
        this.selectionIds = new Set(); // Pour une recherche rapide des IDs
        this.currentIdEntreprise = null; // NOUVEAU : Stocke l'ID de l'entreprise du contexte actuel

        console.log(this.nomControleur + "🧠 Cerveau prêt à orchestrer.");
        // --- CORRECTION : Lier la fonction une seule fois et stocker la référence ---
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

        // Validation de base de l'événement
        if (!type || !source || !payload || !timestamp) {
            console.error("🧠 [Cerveau] Événement invalide reçu. Structure attendue: {type, source, payload, timestamp}", event.detail);
            return;
        }

        // Log stylisé pour le débogage
        console.groupCollapsed(`🧠 [Cerveau] Événement Reçu: %c${type}`, 'color: #4CAF50; font-weight: bold;');
        console.log(`| Source:`, source);
        console.log(`| Données (Payload):`, payload);
        console.log(`| Horodatage:`, new Date(timestamp).toLocaleString('fr-FR'));

        switch (type) {
            // --- Chargement du composant dans l'espace de travail ---
            case 'ui:component.load': // Utilisé pour charger une rubrique dans l'espace de travail
                console.log(this.nomControleur + `🧠 [Cerveau]-> ACTION: Charger le composant '${payload.componentName}' (entité: ${payload.entityName}) pour l'espace de travail.`);
                this.loadWorkspaceComponent(payload.componentName, payload.entityName);
                break;

            // NOUVEAU : Un composant (view-manager) initialise le contexte
            case 'app:context.initialized':
                console.log("-> ACTION: Le contexte de la rubrique a été initialisé.");
                this.currentIdEntreprise = payload.idEntreprise;
                this.broadcast('ui:tab.context-changed', payload); // On relaie pour que la toolbar se mette à jour
                break;

            case 'app:error.api':
                console.error("-> GESTION ERREUR: Une erreur API a été interceptée.");
                console.error("| Détails:", payload.error);
                console.log("-> ACTION: Afficher une notification d'erreur standard à l'utilisateur.");
                // Code futur: this.broadcast('notification:show', { type: 'error', message: 'Une erreur serveur est survenue. Veuillez réessayer.' });
                break;

            // --- NOUVEAU : Relais du changement de sélection d'un élément de liste ---
            case 'ui:list-row.selection-changed':
                console.log("-> ACTION: Mise à jour de l'état de sélection.");
                this.updateSelectionState(payload);
                break;

            case 'ui:selection.updated':
                // Cet événement est maintenant obsolète, la logique est dans 'ui:list-row.selection-changed'
                console.warn("-> ATTENTION: Événement 'ui:selection.updated' obsolète. La logique est gérée par le Cerveau.");
                break;

            // --- NOUVEAU : Gère la demande de fermeture d'une rubrique ---
            case 'ui:toolbar.close-request':
                console.log("-> ACTION: Demande de fermeture de la rubrique. Diffusion de l'ordre de retour au tableau de bord.");
                this.broadcast('app:workspace.load-default');
                break;

            // --- NOUVEAU : Gère le changement de contexte d'un onglet ---
            case 'ui:tab.context-changed':
                console.log("-> ACTION: Le contexte d'un onglet a changé. Restauration de la sélection pour cet onglet.");
                // On restaure l'état de la sélection avec les "selectos" fournis par le view-manager.
                this.selectionState = payload.selectos || [];
                this.selectionIds = new Set(this.selectionState.map(s => s.id));
                // On publie la sélection ET on rediffuse le contexte de l'onglet pour les composants comme la toolbar.
                this.publishSelection(); 
                this.broadcast('ui:tab.context-changed', {
                    ...payload
                });
                break;

            // --- NOUVEAU : Gère la demande d'ajout depuis la barre d'outils ---
            case 'ui:toolbar.add-request':
                console.log("-> ACTION: Demande d'ajout. Préparation de l'ouverture de la boîte de dialogue.");
                // Le payload contient maintenant directement { entityFormCanvas: ... }
                // Et potentiellement isCreationMode: true
                this.broadcast('app:boite-dialogue:init-request', {
                    entity: {}, // Entité vide pour le mode création
                    entityFormCanvas: payload.entityFormCanvas,
                    isCreationMode: payload.isCreationMode, // Relayer l'information
                    context: {}
                });
                break;

            // --- NOUVEAU : Gestion des événements du cycle de vie des dialogues ---
            case 'ui:dialog.opened':
                console.log("-> ACTION: Une boîte de dialogue a été ouverte.", payload);
                // Aucune diffusion nécessaire pour le moment, mais le hook est là.
                break;

            case 'app:entity.saved':
                console.log("-> ACTION: Une entité a été sauvegardée. Demande de rafraîchissement des listes et affichage d'une notification.");
                // Diffusion pour rafraîchir les listes (principale et collections)
                this.broadcast('app:list.refresh-request', {
                    originatorId: payload.originatorId, // Permet au bon collection-manager de se rafraîchir
                    idEntreprise: this.currentIdEntreprise // MISSION 2 : On ajoute l'ID de l'entreprise
                });
                // Diffusion pour afficher un toast de succès
                this.broadcast('app:notification.show', { text: 'Enregistrement réussi !', type: 'success' });
                break;

            case 'app:form.validation-error':
                console.warn("-> ACTION: Une erreur de validation de formulaire a été reçue.", payload);
                this.broadcast('app:notification.show', { text: payload.message || 'Erreur de validation.', type: 'error' });
                break;

            case 'ui:dialog.closed':
                console.log("-> ACTION: Une boîte de dialogue a été fermée.", payload);
                break;
            
            // --- NOUVEAU : Gère la demande d'actualisation depuis la barre d'outils ---
            case 'ui:toolbar.refresh-request':
                console.log("-> ACTION: Demande d'actualisation de la liste principale. Diffusion de l'ordre de rafraîchissement.");
                this.broadcast('app:list.refresh-request', {
                    idEntreprise: this.currentIdEntreprise // MISSION 2 : On ajoute l'ID de l'entreprise
                });
                break;
            
            // --- NOUVEAU : Gère la demande d'ouverture du menu contextuel ---
            case 'ui:context-menu.request':
                console.log("-> ACTION: Demande d'affichage du menu contextuel. Diffusion de l'ordre.");
                this.broadcast('app:context-menu.show', payload);
                break;

            // --- NOUVEAU : Gère la demande de suppression depuis la barre d'outils ---
            case 'ui:toolbar.delete-request':
                console.log("-> ACTION: Demande de suppression reçue. Ouverture du dialogue de confirmation.");
                this.broadcast('ui:confirmation.request', {
                    title: 'Confirmation de suppression',
                    body: `Êtes-vous sûr de vouloir supprimer ${payload.selection.length} élément(s) ?`,
                    onConfirm: { type: 'app:api.delete-request', payload: payload }
                });
                break;

            // --- NOUVEAU : Gère la notification de statut ---
            case 'ui:status.notify':
                console.log("-> ACTION: Un message de statut a été reçu. Diffusion pour affichage.");
                this.broadcast('app:status.updated', payload);
                break;

            // --- NOUVEAU : Gère la demande d'ouverture d'un élément (depuis barre d'outils ou menu contextuel) ---
            case 'ui:toolbar.open-request':
                console.log("-> ACTION: Demande d'ouverture d'élément(s). Diffusion de l'ordre au WorkspaceManager.");
                // Le workspace-manager écoute 'app:liste-element:openned' pour ouvrir les onglets.
                // CORRECTION : On boucle sur toutes les entités et on envoie un événement pour chacune.
                if (payload.entities && payload.entities.length > 0) {
                    payload.entities.forEach(selecto => {
                        // On restructure le payload pour qu'il corresponde exactement
                        // à ce que `workspace-manager` attend : un objet avec les clés `entity`, `entityType`, `entityCanvas`.
                        this.broadcast('app:liste-element:openned', selecto);
                    });
                }
                break;

            // --- NOUVEAU : Gère la demande de sélection/désélection de tous les éléments ---
            case 'ui:toolbar.select-all-request':
                console.log("-> ACTION: Demande de sélection totale. Diffusion de l'ordre au ListManager.");
                this.broadcast('app:list.toggle-all-request', payload);
                break;

            default:
                console.warn(`-> ATTENTION: Aucun gestionnaire défini pour l'événement "${type}".`);
        }

        console.groupEnd();
    }

    /**
     * Met à jour l'état de la sélection et publie le changement.
     * @param {object} selecto - L'objet de sélection d'une ligne.
     * @private
     */
    updateSelectionState(selecto) {
        const { id, isChecked } = selecto;

        if (isChecked) {
            if (!this.selectionIds.has(id)) {
                this.selectionState.push(selecto);
                this.selectionIds.add(id);
            }
        } else {
            if (this.selectionIds.has(id)) {
                this.selectionState = this.selectionState.filter(item => item.id !== id);
                this.selectionIds.delete(id);
            }
        }
        this.publishSelection();
    }

    /**
     * Diffuse l'état de sélection actuel à toute l'application.
     * @private
     */
    publishSelection() {
        // --- NOUVELLE ARCHITECTURE ---
        // Le payload est maintenant directement le tableau des "selectos".
        console.log("-> ACTION: Publication de l'état de sélection mis à jour.", this.selectionState);
        this.broadcast('ui:selection.changed', this.selectionState);
    }

    /**
     * Charge le contenu HTML d'un composant pour l'espace de travail et diffuse le résultat.
     * @param {string} componentName Le nom du fichier de template du composant.
     * @fires workspace:component.loaded
     * @private
     */
    async loadWorkspaceComponent(componentName, entityName) {
        let url = `/espacedetravail/api/load-component?component=${componentName}`;
        // On ajoute le paramètre 'entity' s'il est fourni
        if (entityName) {
            url += `&entity=${entityName}`;
        }

        // LOG: Vérifier l'URL finale avant l'appel fetch
        console.log(`[Cerveau] Appel fetch vers l'URL: ${url}`);

        try {
            const response = await fetch(url);
            if (!response.ok) {
                throw new Error(`Erreur serveur (${response.status}): ${response.statusText}`);
            }
            const html = await response.text();

            // On diffuse le HTML aux contrôleurs qui écoutent (ex: espace-de-travail)
            this.broadcast('workspace:component.loaded', { html: html, error: null });

        } catch (error) {
            console.error(`[Cerveau] Échec du chargement du composant '${componentName}':`, error);
            this.broadcast('workspace:component.loaded', { html: null, error: error.message });
        }
    }

    /**
     * Méthode utilitaire pour diffuser un événement à l'échelle de l'application.
     * @param {string} eventName - Le nom de l'événement à diffuser.
     * @param {object} [detail={}] - Le payload à inclure dans `event.detail`.
     * @private
     */
    broadcast(eventName, detail) {
        document.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail }));
    }
}