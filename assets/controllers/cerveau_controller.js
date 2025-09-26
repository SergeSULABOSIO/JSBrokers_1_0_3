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
        console.log(this.nomControleur + "🧠 Cerveau prêt à orchestrer.");
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

            case 'app:error.api':
                console.error("-> GESTION ERREUR: Une erreur API a été interceptée.");
                console.error("| Détails:", payload.error);
                console.log("-> ACTION: Afficher une notification d'erreur standard à l'utilisateur.");
                // Code futur: this.broadcast('notification:show', { type: 'error', message: 'Une erreur serveur est survenue. Veuillez réessayer.' });
                break;

            // --- NOUVEAU : Relais du changement de sélection d'un élément de liste ---
            case 'ui:list-item.selection-changed':
                console.log("-> ACTION: Relayer le changement de sélection d'un élément vers la liste principale.");
                this.broadcast('app:list-item.selection-changed:relay', payload);
                break;

            // --- NOUVEAU : Relais de l'état de sélection complet d'une liste ---
            // Cet événement est émis par liste-principale APRES avoir mis à jour son état.
            case 'ui:selection.updated':
                console.log("-> ACTION: L'état de la sélection a été mis à jour. Diffusion aux composants dépendants (barre d'outils, etc.).");
                this.broadcast('ui:selection.changed', payload);
                break;

            // --- NOUVEAU : Gère la demande de fermeture d'une rubrique ---
            case 'ui:toolbar.close-request':
                console.log("-> ACTION: Demande de fermeture de la rubrique. Diffusion de l'ordre de retour au tableau de bord.");
                this.broadcast('app:workspace.load-default');
                break;

            // --- NOUVEAU : Gère la demande d'ajout depuis la barre d'outils ---
            case 'ui:toolbar.add-request':
                console.log("-> ACTION: Demande d'ajout. Ouverture de la boîte de dialogue.");
                this.broadcast('app:boite-dialogue:init-request', {
                    entity: {}, // Entité vide pour le mode création
                    entityFormCanvas: payload.entityFormCanvas,
                    context: {}
                });
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