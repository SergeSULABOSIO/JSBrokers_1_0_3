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
        this.currentIdEntreprise = null;
        this.currentIdInvite = null;
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

        switch (type) {
            case 'ui:component.load': // Utilisé pour charger une rubrique dans l'espace de travail
                this.loadWorkspaceComponent(payload.componentName, payload.entityName, payload.idEntreprise, payload.idInvite);
                break;

            case 'app:context.initialized':
                this._setApplicationContext(payload);
                break;

            case 'app:error.api':
                this._showNotification('Une erreur serveur est survenue. Veuillez réessayer.', 'error');
                break;

            case 'ui:list-row.selection-changed':
                this.updateSelectionState(payload);
                break;

            case 'ui:toolbar.close-request':
                this.broadcast('app:workspace.load-default');
                break;

            case 'ui:tab.context-changed':
                this._setSelectionState(payload.selectos || []);
                this.broadcast('ui:tab.context-changed', { ...payload });
                break;

            case 'dialog:boite-dialogue:init-request':
                this.openDialogBox(payload);
                break;

            case 'ui:boite-dialogue:add-collection-item-request':
                this.openDialogBox(payload);
                break;

            case 'ui:toolbar.add-request':
                this.openDialogBox(payload);
                break;
            
            case 'ui:toolbar.edit-request':
                this.openDialogBox(payload);
                break;

            case 'app:entity.saved':
                this._requestListRefresh(payload.originatorId);
                this._showNotification('Enregistrement réussi !', 'success');
                break;

            case 'app:form.validation-error':
                this._showNotification(payload.message || 'Erreur de validation.', 'error');
                break;

            case 'ui:toolbar.refresh-request':
                // On notifie le début du chargement pour que la barre de progression s'affiche
                this.broadcast('app:loading.start');
                this._requestListRefresh();
                break;

            // NOUVEAU : La liste a terminé son actualisation.
            case 'app:list.refreshed':
                this._setSelectionState([]); // On réinitialise la sélection
                this.broadcast('app:loading.stop'); // On notifie la fin pour masquer la barre de progression
                break;

            case 'ui:context-menu.request':
                this.broadcast('app:context-menu.show', payload);
                break;

            case 'app:api.delete-request':
                this._handleApiDeleteRequest(payload);
                break;

            case 'dialog:confirmation.request':
                this._requestDeleteConfirmation(payload);
                break;

            case 'ui:toolbar.delete-request':
                this._handleToolbarDeleteRequest(payload);
                break;

            case 'ui:status.notify':
                this.broadcast('app:status.updated', payload);
                break;

            case 'ui:toolbar.open-request':
                this._handleOpenRequest(payload);
                break;

            case 'ui:toolbar.select-all-request':
                this.broadcast('app:list.toggle-all-request');
                break;

            // NOUVEAU : La liste a terminé une opération de sélection de masse.
            case 'ui:list.selection-completed':
                this._setSelectionState(payload.selectos || []);
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
                // Le Cerveau relaie l'objet "selecto" complet.
                // Le workspace-manager est responsable de l'interpréter.
                this.broadcast('app:liste-element:openned', selecto);
            });
        }
    }


    openDialogBox(payload) {
        console.groupCollapsed(`${this.nomControleur} - handleEvent - EDITDIAL(1)`);
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
                idEntreprise: this.idEntreprise,
                idInvite: this.idInvite
            }
        });
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
     * Définit un nouvel état de sélection complet et le publie.
     * @param {Array} [selectos=[]] - Le nouveau tableau d'objets "selecto".
     * @private
     */
    _setSelectionState(selectos = []) {
        this.selectionState = selectos;
        this.selectionIds = new Set(this.selectionState.map(s => s.id));
        this.publishSelection();
    }

    /**
     * Définit le contexte principal de l'application (entreprise et invité) et le diffuse.
     * @param {object} payload - Le payload contenant idEntreprise et idInvite.
     * @private
     */
    _setApplicationContext(payload) {
        this.currentIdEntreprise = payload.idEntreprise;
        this.currentIdInvite = payload.idInvite;
        // On relaie l'événement pour que les composants comme la toolbar puissent se mettre à jour.
        this.broadcast('ui:tab.context-changed', payload);
    }

    /**
     * Charge le contenu HTML d'un composant pour l'espace de travail et diffuse le résultat.
     * @param {string} componentName Le nom du fichier de template du composant.
     * @fires workspace:component.loaded
     * @private
     */
    async loadWorkspaceComponent(componentName, entityName, idEntreprise, idInvite) {
        // On construit l'URL avec les IDs dans le chemin, comme défini par la route Symfony
        let url = `/espacedetravail/api/load-component/${idInvite}/${idEntreprise}?component=${componentName}`;
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


    

    /**
     * Gère la logique de suppression d'éléments via l'API en exécutant plusieurs requêtes en parallèle.
     * Notifie le reste de l'application en cas de succès ou d'échec.
     * @param {object} payload - Le payload contenant les IDs, l'URL et l'originatorId.
     * @param {number[]} payload.ids - Tableau des IDs des entités à supprimer.
     * @param {string} payload.url - L'URL de base de l'API de suppression.
     * @param {string} [payload.originatorId] - L'ID du composant qui a initié la demande (pour un rafraîchissement ciblé).
     * @private
     */
    _handleApiDeleteRequest(payload) {
        const { ids, url, originatorId } = payload;

        // On crée un tableau de promesses, une pour chaque requête de suppression.
        const deletePromises = ids.map(id => {
            const deleteUrl = `${url}/${id}`; // Construit l'URL finale pour chaque ID.
            return fetch(deleteUrl, { method: 'DELETE' })
                .then(response => {
                    if (!response.ok) throw new Error(`Erreur lors de la suppression de l'élément ${id}.`);
                    return response.json();
                });
        });

        // On attend que toutes les promesses de suppression soient résolues.
        Promise.all(deletePromises)
            .then(results => {
                const message = results.length > 1 ? `${results.length} éléments supprimés avec succès.` : 'Élément supprimé avec succès.';
                console.log(`${this.nomControleur} - SUCCÈS: Suppression(s) réussie(s).`, results);
                this._showNotification(message, 'success');
                // On réinitialise l'état de la sélection et on notifie tout le monde (toolbar, etc.)
                this._setSelectionState([]);
                this._requestListRefresh(originatorId);
                this.broadcast('ui:confirmation.close');
            })
            .catch(error => {
                console.error("-> ERREUR: Échec de la suppression API.", error);
                this.broadcast('app:error.api', { error: error.message || "La suppression a échoué." });
                this.broadcast('ui:confirmation.close'); // Ferme aussi la modale en cas d'erreur.
            });
    }

    /**
     * Diffuse une demande de rafraîchissement de la liste.
     * @param {string|null} [originatorId=null] - L'ID du composant qui a initié la demande, pour un rafraîchissement ciblé.
     * @private
     */
    _requestListRefresh(originatorId = null) {
        const payload = {
            idEntreprise: this.currentIdEntreprise,
            idInvite: this.currentIdInvite,
        };
        if (originatorId) payload.originatorId = originatorId;
        this.broadcast('app:list.refresh-request', payload);
    }

    /**
     * Diffuse une demande pour afficher une notification (toast).
     * @param {string} text - Le message à afficher.
     * @param {'success'|'error'|'info'|'warning'} [type='info'] - Le type de notification.
     * @private
     */
    _showNotification(text, type = 'info') {
        this.broadcast('app:notification.show', { text, type });
    }



    /**
     * Encapsule la logique de diffusion d'une demande de confirmation de suppression.
     * @param {object} payload - Le payload de l'événement d'origine, doit contenir `selection`.
     * @private
     */
    _requestDeleteConfirmation(payload) {
        const itemCount = payload.selection ? payload.selection.length : 0;
        if (itemCount === 0) return; // Ne rien faire si la sélection est vide.

        this.broadcast('ui:confirmation.request', {
            title: 'Confirmation de suppression',
            body: `Êtes-vous sûr de vouloir supprimer ${itemCount} élément(s) ?`,
            onConfirm: { type: 'app:api.delete-request', payload: payload }
        });
    }


    
    /**
     * Gère une demande de suppression provenant de la barre d'outils en construisant
     * et en diffusant une demande de confirmation.
     * @param {object} payload - Le payload de l'événement, contenant `selection` et `actionConfig`.
     * @private
     */
    _handleToolbarDeleteRequest(payload) {
        this.broadcast('ui:confirmation.request', {
            title: payload.title || 'Confirmation de suppression',
            body: payload.body || `Êtes-vous sûr de vouloir supprimer ${payload.selection.length} élément(s) ?`,
            onConfirm: {
                type: 'app:api.delete-request',
                payload: {
                    ids: payload.selection, // Les IDs à supprimer
                    url: payload.actionConfig.url, // L'URL de base pour la suppression
                    originatorId: payload.actionConfig?.originatorId // L'ID de la collection à rafraîchir (optionnel)
                }
            }
        });
    }
}