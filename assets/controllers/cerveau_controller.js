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
        // --- NOUVELLE ARCHITECTURE : Le Cerveau devient la source de vérité pour la sélection ---
        this.selectionState = []; // Tableau des objets "selecto"
        this.selectionIds = new Set(); // Pour une recherche rapide des IDs
        this.numericAttributesAndValues = {}; // Stocke l'objet complet {colonnes, valeurs}
        this.currentIdEntreprise = null;
        // NOUVEAU : État pour le "display"
        this.displayState = {
            rubricName: 'Tableau de bord',
            action: 'Initialisation',
            result: 'Prêt',
            selectionCount: 0
        };
        this.currentIdInvite = null;
        this.activeParentId = null; // NOUVEAU : Pour stocker l'ID du parent de l'onglet actif.
        console.log(`[${++window.logSequence}] ${this.nomControleur} 🧠 Cerveau prêt à orchestrer.`);
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
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - handleEvent - Code: 100 - Données:`, { type, source, payload });

        // Validation de base de l'événement
        if (!type || !source || !payload || !timestamp) {
            console.error("🧠 [Cerveau] Événement invalide reçu. Structure attendue: {type, source, payload, timestamp}", event.detail);
            return;
        }

        switch (type) {
            case 'ui:component.load': // Utilisé pour charger une rubrique dans l'espace de travail
                this.displayState.rubricName = payload.entityName || 'Inconnu';
                this._publishDisplayStatus('Chargement de la rubrique...');
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
                // --- SOLUTION FINALE ---
                // On fait confiance au ViewManager. Il est la source de vérité pour l'état de sélection
                // lors d'un changement d'onglet. S'il envoie un tableau de `selectos`, on l'utilise.
                // S'il envoie un tableau vide (ou rien), la sélection est réinitialisée.
                // Cette simple ligne corrige le problème de perte de sélection au retour sur un onglet
                // tout en conservant le comportement de réinitialisation pour les nouveaux onglets.
                this._setSelectionState(payload.selectos || []);

                this._publishDisplayStatus(`Navigation vers l'onglet '${payload.tabId}'`);
                
                this.activeParentId = payload.parentId || null; // NOUVEAU : Mémoriser l'ID du parent.
                // --- SOLUTION : On ne rafraîchit plus systématiquement la liste au changement d'onglet ---
                // Le rafraîchissement automatique entraînait la perte de la sélection restaurée.
                // La liste est déjà chargée, soit par le serveur initialement, soit par un chargement AJAX précédent.
                // this.broadcast('search:advanced.reset', {}); // On peut aussi commenter cette ligne si on veut conserver les filtres entre les onglets.
                this.broadcast('ui:tab.context-changed', { ...payload });
                break;
            
            // SOLUTION : Un list-manager (d'un onglet) notifie qu'il est prêt avec son propre contexte.
            case 'app:list.context-ready':
                // Le Cerveau relaie cette information via un événement que la toolbar écoute déjà.
                // C'est la clé pour que le bouton "Ajouter" ait le bon contexte de formulaire.
                console.log(`[${++window.logSequence}] 🧠 [Cerveau] Contexte de formulaire reçu pour l'onglet '${payload.tabId}'. Diffusion...`, payload);
                this.broadcast('ui:tab.context-changed', { tabId: payload.tabId, formCanvas: payload.formCanvas });
                break;


            // --- NOUVEAU : Communication pour la recherche avancée ---
            case 'dialog:search.open-request':
                // La barre de recherche demande l'ouverture du dialogue
                this.broadcast('dialog:search.open-request', payload);
                break;
            case 'search:advanced.submitted':
                // Le dialogue a soumis les critères, on les envoie à la barre de recherche
                this.broadcast('search:advanced.submitted', payload);
                break;
            case 'search:advanced.reset':
                // Le dialogue demande une réinitialisation, on le transmet à la barre de recherche
                this.broadcast('search:advanced.reset', payload);
                break;
            // NOUVEAU : La barre de recherche demande une réinitialisation complète.
            // CORRECTION : Cet événement est maintenant le point d'entrée unique pour une réinitialisation globale.
            case 'ui:search.reset-request':
                this.broadcast('search:advanced.reset', {}); // Ordonne à la barre de recherche de vider son UI et ses filtres.
                // Lance une recherche avec des critères vides (par défaut) pour rafraîchir la liste.
                // CORRECTION : On passe l'ID de l'onglet actif pour cibler la bonne liste.
                const activeTabId = this.getActiveTabId();
                this._requestListRefresh(activeTabId, { criteria: {} });
                break;

            // FUSION DE LOGIQUE : On traite une demande d'init de dialogue comme une demande d'ajout.
            // Cela garantit que le payload est toujours enrichi avec le contexte nécessaire (formCanvas).
            case 'dialog:boite-dialogue:init-request':
            case 'ui:boite-dialogue:add-collection-item-request':
                this.broadcast('app:loading.start');
                this._publishDisplayStatus('Ouverture du formulaire de collection...');
                this.openDialogBox(payload);
                break;

            case 'ui:toolbar.add-request':
                this.broadcast('app:loading.start');
                this._publishDisplayStatus('Ouverture du formulaire de création...');
                this.openDialogBox(payload);
                break;
            
            case 'ui:toolbar.edit-request':
                this.broadcast('app:loading.start');
                this._publishDisplayStatus(`Modification de l'élément...`);
                this.openDialogBox(payload);
                break;

            // NOUVEAU : Le dialogue est complètement chargé et affiché.
            case 'ui:dialog.opened':
                this._publishDisplayStatus(payload.mode === 'creation' ? 'Formulaire prêt pour la saisie.' : 'Formulaire prêt pour modification.');
                this.broadcast('app:loading.stop');
                break;

            case 'app:entity.saved':
                this._requestListRefresh(payload.originatorId);
                this._showNotification('Enregistrement réussi !', 'success');
                break;

            case 'app:form.validation-error':
                this._publishDisplayStatus('Erreur de validation. Veuillez corriger le formulaire.');
                this._showNotification(payload.message || 'Erreur de validation.', 'error');
                break;

            case 'app:base-données:sélection-request':
                console.log(`[${++window.logSequence}] ${this.nomControleur} - Code: 1986 - Recherche`, payload);
                const criteriaText = Object.keys(payload.criteria || {}).length > 0 
                    ? `Filtre actif` 
                    : 'Recherche par défaut';
                this._publishDisplayStatus(criteriaText);
                // CORRECTION : Le cerveau déclenche systématiquement le chargement.
                this.broadcast('app:loading.start');
                this.broadcast('app:list.refresh-request', payload);
                break;

            case 'ui:toolbar.refresh-request':
                this.displayState.action = 'Rafraîchissement manuel';
                this._publishDisplayStatus('Rafraîchissement en cours...');
                // On notifie le début du chargement pour que la barre de progression s'affiche
                this.broadcast('app:loading.start');
                this._requestListRefresh(this.getActiveTabId());
                break;

            // NOUVEAU : La liste a terminé son actualisation.
            case 'app:list.refreshed':
                this._setSelectionState([]); // On réinitialise la sélection
                const itemCount = payload.itemCount ?? 'N/A';
                this._publishDisplayStatus(`Liste chargée : ${itemCount} élément(s)`);
                // On notifie la fin pour masquer la barre de progression
                this.broadcast('app:loading.stop');
                break;
            
            // NOUVEAU : La liste a chargé ses données, on stocke les infos numériques.
            case 'app:list.data-loaded':
                this.numericAttributesAndValues = payload.numericAttributesAndValues || {};
                // --- AJOUT DU LOG ---
                console.log(`[${++window.logSequence}] 🧠 [Cerveau] Données numériques reçues:`, { 
                    numericAttributesAndValues: this.numericAttributesAndValues
                });
                break;

            case 'ui:context-menu.request':
                this.broadcast('app:context-menu.show', payload);
                break;

            case 'app:api.delete-request':
                this._publishDisplayStatus('Suppression en cours...');
                this._handleApiDeleteRequest(payload);
                break;

            case 'dialog:confirmation.request':
                this._publishDisplayStatus('Attente de confirmation...');
                this._requestDeleteConfirmation(payload);
                break;

            case 'ui:toolbar.delete-request':
                this._handleToolbarDeleteRequest(payload);
                break;

            case 'ui:status.notify':
                this.broadcast('app:status.updated', payload);
                break;

            case 'ui:toolbar.open-request':
                this.broadcast('app:loading.start');
                this._publishDisplayStatus('Ouverture de la vue détaillée...');
                this._handleOpenRequest(payload);
                break;

            // NOUVEAU : Un onglet a été ouvert avec succès dans la colonne de visualisation.
            case 'app:tab.opened':
                this.broadcast('app:loading.stop');
                break;

            case 'ui:toolbar.select-all-request':
                this.broadcast('app:list.toggle-all-request');
                break;

            // NOUVEAU : La rubrique est chargée, on le signale à tout le monde.
            case 'app:navigation-rubrique:openned':
                this.broadcast('app:navigation-rubrique:openned', payload);
                break;

            // NOUVEAU : La liste a terminé une opération de sélection de masse.
            case 'ui:list.selection-completed':
                this._setSelectionState(payload.selectos || []);
                break;

            // NOUVEAU : Relais pour les indicateurs de chargement
            case 'app:loading.start':
                this.broadcast('app:loading.start', payload);
                break;

            case 'app:loading.stop':
                this.broadcast('app:loading.stop', payload);
                break;

            // NOUVEAU : Gère la fermeture d'un dialogue
            case 'ui:dialog.closed':
                this._publishDisplayStatus('Formulaire fermé.');
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
            }, // CORRECTION : Ajout de la virgule manquante.
            // NOUVEAU : On ajoute le contexte du parent si on est dans un onglet de collection.
            parentContext: this.activeParentId ? {
                id: this.activeParentId,
                fieldName: payload.entityFormCanvas && payload.entityFormCanvas.parametres && payload.entityFormCanvas.parametres.parent_entity_field_name
            } : null
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
        console.log(`[${++window.logSequence}] [${this.nomControleur}] - publishSelection - Code: 100 - Données:`, { selection: this.selectionState });
        this.displayState.selectionCount = this.selectionState.length;
        this._publishDisplayStatus(); // Met à jour le display avec le nouveau compte de sélection
        // --- NOUVELLE ARCHITECTURE ---
        this.broadcast('ui:selection.changed', {
            selection: this.selectionState,
            numericAttributesAndValues: this.numericAttributesAndValues
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
        console.log(`[${++window.logSequence}] [Cerveau] Appel fetch vers l'URL: ${url}`);

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
                // Notifie la boîte de dialogue de confirmation de l'erreur pour qu'elle l'affiche.
                this.broadcast('ui:confirmation.error', { error: error.message || "La suppression a échoué." });
                // La boîte de dialogue de confirmation gérera sa propre fermeture après affichage de l'erreur.
            });
    }

    /**
     * Récupère l'ID de l'onglet actuellement actif depuis le view-manager.
     * @returns {string|null}
     * @private
     */
    getActiveTabId() {
        const viewManagerEl = document.querySelector('[data-controller="view-manager"]');
        if (viewManagerEl && this.application.getControllerForElementAndIdentifier(viewManagerEl, 'view-manager')) {
            return this.application.getControllerForElementAndIdentifier(viewManagerEl, 'view-manager').activeTabId;
        }
        return 'principal'; // Fallback sur la liste principale
    }

    /**
     * Diffuse une demande de rafraîchissement de la liste.
     * @param {string|null} [originatorId=null] - L'ID du composant qui a initié la demande, pour un rafraîchissement ciblé.
     * @param {object} [criteriaPayload={}] - Le payload contenant les critères de recherche.
     * @private
     */
    _requestListRefresh(originatorId = null, criteriaPayload = {}) {
        const payload = {
            ...criteriaPayload, // Fusionne les critères passés
            idEntreprise: this.currentIdEntreprise,
            idInvite: this.currentIdInvite,
            originatorId: originatorId // On ajoute l'ID de la liste à rafraîchir
        };
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
     * NOUVEAU : Formate et diffuse le message de statut pour le display.
     * @param {string|null} [action=null] - La nouvelle action à afficher. Si null, l'action précédente est conservée.
     * @private
     */
    _publishDisplayStatus(action = null) {
        if (action) {
            this.displayState.action = action;
        }

        const timestamp = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        
        const messageHtml = `
            <span class="fw-bold text-dark">${this.displayState.rubricName}</span>
            <span class="mx-2 text-muted">›</span>
            <span>${this.displayState.action}</span>
            <span class="mx-2 text-muted">|</span>
            <span class="fw-bold">${this.displayState.selectionCount}</span> sélection(s)
        `;
        this.broadcast('app:display.update', { html: messageHtml });
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