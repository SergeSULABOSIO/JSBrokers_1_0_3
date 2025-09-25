import { Controller } from '@hotwired/stimulus';
import { EVEN_LISTE_ELEMENT_DELETE_REQUEST, EVEN_LISTE_ELEMENT_MODIFY_REQUEST, EVEN_LISTE_ELEMENT_OPEN_REQUEST, EVEN_LISTE_PRINCIPALE_ADD_REQUEST, EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST } from './base_controller.js';

/**
 * Le Cerveau de l'application JS Brokers.
 * Ce contrôleur est le Médiateur central. Il ne doit pas être attaché à un 
 * composant d'UI spécifique mais plutôt à un élément parent global comme <body>.
 * Il écoute les événements de l'application et orchestre les réactions.
 */
export default class extends Controller {
    connect() {
        this.nomControleur = "Cerveau";
        console.log(this.nomControleur + "🧠 Cerveau connecté. Prêt à orchestrer.");
        document.addEventListener('cerveau:event', this.handleEvent.bind(this));
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.handleEvent.bind(this));
    }

    /**
     * Point d'entrée pour tous les événements de l'application.
     * Déclenché par une action `cerveau:event->cerveau#handleEvent`.
     * @param {CustomEvent} event
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
                console.log(this.nomControleur + "🧠 [Cerveau]-> ACTION: Charger le composant '${payload.componentName}' pour l'espace de travail.");
                this.loadWorkspaceComponent(payload.componentName);
                break;

            // --- NOUVEAU : Gestion centralisée du changement de contexte d'onglet ---
            case 'ui:tab.context-changed':
                console.log("-> ACTION: Le contexte de sélection a changé. Diffusion aux composants dépendants.");
                this.broadcast('ui:selection.changed', payload); // On propage simplement le payload
                break;

            // --- Gestion des onglets et de la sélection ---
            case 'ui:tab.switched':
                console.log("-> ACTION: L'état d'un onglet a changé. Diffusion aux outils dépendants.");
                this.broadcast('ui:outils-dependants:ajuster', payload); // On propage simplement le payload
                break;

            // --- NOUVEAU : Relais des actions de la barre d'outils ---
            case 'ui:toolbar.add-request':
                console.log("-> ACTION: Relayer la demande d'ajout.");
                this.broadcast(EVEN_LISTE_PRINCIPALE_ADD_REQUEST, payload);
                break;
            case 'ui:toolbar.modify-request':
                console.log("-> ACTION: Relayer la demande de modification.");
                this.broadcast(EVEN_LISTE_ELEMENT_MODIFY_REQUEST, payload);
                break;
            case 'ui:toolbar.delete-request':
                console.log("-> ACTION: Relayer la demande de suppression.");
                this.broadcast(EVEN_LISTE_ELEMENT_DELETE_REQUEST, payload);
                break;
            case 'ui:toolbar.open-request':
                console.log("-> ACTION: Relayer la demande d'ouverture.");
                this.broadcast(EVEN_LISTE_ELEMENT_OPEN_REQUEST, payload);
                break;
            case 'ui:toolbar.refresh-request':
                console.log("-> ACTION: Relayer la demande de rafraîchissement.");
                this.broadcast(EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST, payload);
                break;
            case 'ui:toolbar.select-all-request':
                console.log("-> ACTION: Relayer la demande de sélection/désélection de tout.");
                this.broadcast(EVEN_LISTE_PRINCIPALE_ALL_CHECK_REQUEST, payload);
                break;
            case 'ui:toolbar.settings-request':
                console.log("-> ACTION: Relayer la demande d'accès aux paramètres.");
                this.broadcast(EVEN_LISTE_PRINCIPALE_SETTINGS_REQUEST, payload);
                break;
            case 'ui:toolbar.close-request':
                console.log("-> ACTION: Relayer la demande de fermeture.");
                this.broadcast(EVEN_LISTE_PRINCIPALE_CLOSE_REQUEST, payload);
                break;
            // --- FIN du relais des actions de la barre d'outils ---


            case 'api:sinistre.created':
                console.log("-> ACTION: Demander le rafraîchissement de la liste des sinistres.");
                console.log("-> ACTION: Afficher une notification de succès 'Sinistre créé'.");
                // Code futur: this.broadcast('liste-sinistres:refresh');
                // Code futur: this.broadcast('notification:show', { type: 'success', message: 'Nouveau sinistre créé avec succès.' });
                break;

            case 'api:sinistre.updated':
                console.log("-> ACTION: Mettre à jour l'élément sinistre dans la liste.");
                console.log("-> ACTION: Mettre à jour la vue détaillée du sinistre si elle est ouverte.");
                console.log("-> ACTION: Afficher une notification de succès 'Sinistre mis à jour'.");
                break;

            case 'ui:sinistre.selected':
                console.log("-> ACTION: Charger les détails du sinistre sélectionné dans le panneau latéral.");
                console.log("-> ACTION: Potentiellement fermer d'autres vues de détail ouvertes.");
                break;

            // --- Gestion des Offres d'Indemnisation ---
            case 'api:offre.created':
                console.log("-> ACTION: Rafraîchir la liste des offres pour le sinistre concerné.");
                console.log("-> ACTION: Mettre à jour le statut du sinistre (ex: 'En attente de validation offre').");
                console.log("-> ACTION: Afficher une notification de succès.");
                break;

            case 'api:offre.updated':
                console.log("-> ACTION: Mettre à jour l'élément offre dans la liste des offres.");
                console.log("-> ACTION: Recalculer les totaux liés au sinistre.");
                break;

            // --- Gestion des Paiements ---
            case 'api:paiement.created':
                console.log("-> ACTION: Mettre à jour le statut de l'offre (ex: 'Payée partiellement', 'Soldée').");
                console.log("-> ACTION: Mettre à jour le statut du sinistre si toutes les offres sont soldées.");
                console.log("-> ACTION: Rafraîchir la liste des paiements.");
                break;

            // --- Gestion des Tâches ---
            case 'api:tache.created':
                console.log("-> ACTION: Ajouter la tâche à la liste des tâches.");
                console.log("-> ACTION: Mettre à jour le badge de compteur des tâches.");
                break;

            case 'api:tache.updated':
                console.log("-> ACTION: Mettre à jour l'affichage de la tâche (ex: la barrer si complétée).");
                console.log("-> ACTION: Mettre à jour le badge de compteur des tâches si le statut change.");
                break;

            // --- Gestion des Pièces et Documents ---
            case 'api:piece.added':
                console.log("-> ACTION: Rafraîchir la liste des pièces pour le sinistre concerné.");
                console.log("-> ACTION: Mettre à jour le statut du dossier sinistre (ex: 'Dossier complet').");
                break;

            case 'api:document.uploaded':
                console.log("-> ACTION: Rafraîchir la bibliothèque de documents.");
                console.log("-> ACTION: Afficher une notification de succès.");
                break;

            // --- Événements d'UI Génériques ---
            case 'ui:notification.show-request':
                console.log(`-> ACTION: Transmettre la demande d'affichage d'un toast de type '${payload.type}' au 'notification-manager'.`);
                // Code futur: document.dispatchEvent(new CustomEvent('notification:show', { detail: payload }));
                break;

            case 'app:error.api':
                console.error("-> GESTION ERREUR: Une erreur API a été interceptée.");
                console.error("| Détails:", payload.error);
                console.log("-> ACTION: Afficher une notification d'erreur standard à l'utilisateur.");
                // Code futur: this.broadcast('notification:show', { type: 'error', message: 'Une erreur serveur est survenue. Veuillez réessayer.' });
                break;

            // --- NOUVEAU : Relais du changement de sélection d'un élément de liste ---
            case 'ui:list-item.selection-changed':
                console.log("-> ACTION: Relayer le changement de sélection d'un élément.", payload);
                this.broadcast('app:list-item.selection-changed:relay', payload);
                break;

            default:
                console.warn(`-> ATTENTION: Aucun gestionnaire défini pour l'événement "${type}".`);
        }

        console.groupEnd();
    }

    // Méthodes utilitaires futures
    // Par exemple, une méthode pour dispatcher des ordres vers d'autres composants
    // broadcast(eventName, detail) { 
    //   document.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail }));
    // }
    /**
     * Charge le contenu HTML d'un composant pour l'espace de travail et diffuse le résultat.
     * @param {string} componentName Le nom du fichier de template du composant.
     */
    async loadWorkspaceComponent(componentName) {
        const url = `/espacedetravail/api/load-component?component=${componentName}`;
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
     * Diffuse un événement à l'échelle de l'application.
     */
    broadcast(eventName, detail) {
        document.dispatchEvent(new CustomEvent(eventName, { bubbles: true, detail }));
    }
}