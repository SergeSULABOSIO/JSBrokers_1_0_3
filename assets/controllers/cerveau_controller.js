import { Controller } from '@hotwired/stimulus';

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
            // --- Gestion des Sinistres ---
            case 'ui:sinistre.index': // Utilisé pour charger une rubrique dans l'espace de travail
                console.log(this.nomControleur + "🧠 [Cerveau]-> ACTION: Charger le composant '${payload.componentName}' pour l'espace de travail.");
                this.loadWorkspaceComponent(payload.componentName);
                break;

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