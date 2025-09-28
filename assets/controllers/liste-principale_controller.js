import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = [
        'donnees',          //Liste conténant des élements
        'selectAllCheckbox',
        'rowCheckbox',
    ];
    static values = {
        objet: Object,
        identreprise: Number,
        idutilisateur: Number,
        nbelements: Number,
        rubrique: String,
        entite: String,
        controleurphp: String,
        controleursitimulus: String,
        entityFormCanvas: Object,
    };


    connect() {
        this.urlAPIDynamicQuery = "/admin/" + this.controleurphpValue + "/api/dynamic-query/" + this.identrepriseValue;
        this.nomControleur = "LISTE-PRINCIPALE";
        console.log(this.nomControleur + " - Connecté");
        this.init();
        
        // --- AJOUT : Le "récepteur" pour restaurer l'état de la sélection ---
        // On met le contrôleur à l'écoute de l'événement de mise à jour global venant du cerveau.
        this.boundHandleGlobalSelectionUpdate = this.handleGlobalSelectionUpdate.bind(this);
        document.addEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        // NOUVEAU : Écouter le changement de sélection d'un item individuel
        this.boundHandleItemSelectionChange = this.handleItemSelectionChange.bind(this);
        document.addEventListener('app:list-item.selection-changed:relay', this.boundHandleItemSelectionChange);
    }


    init() {
        this.tabSelectedEntities = [];
        this.selectedEntitiesType = null;
        this.selectedEntitiesCanvas = null;
        this.tabSelectedCheckBoxs = [];

        this.menu = document.getElementById("simpleContextMenu");
        this.listePrincipale = document.getElementById("liste");
        this.initToolTips();
        this.updateMessage("Prêt.");

        // NOUVEAU : Publier l'état initial (même vide) pour que les autres composants (barre d'outils) reçoivent le contexte.
        this.publierSelection();
        this.setEcouteurs();
    }


    setEcouteurs() {
        // --- CORRECTION : Lier toutes les méthodes de gestion d'événements ---
        this.boundHandleDBRequest = this.handleDBRequest.bind(this);
        this.boundHandleDonneesLoaded = this.handleDonneesLoaded.bind(this);
        this.boundHandleGlobalRefresh = this.handleGlobalRefresh.bind(this);

        // --- CORRECTION : Activer tous les écouteurs nécessaires ---
        document.addEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);
        document.addEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.addEventListener('app:base-données:données-loaded', this.boundHandleDonneesLoaded);

        // --- NOUVEAU : Écouteur pour le résultat de la requête DB ---
        // On le place ici pour qu'il soit nettoyé dans disconnect()
        this.boundHandleDBResult = this.handleDBResult.bind(this);
        document.addEventListener('app:base-données:sélection-executed', this.boundHandleDBResult);
    }

    disconnect() {
        // --- CORRECTION : Nettoyage complet de tous les écouteurs ---
        document.removeEventListener('ui:selection.changed', this.boundHandleGlobalSelectionUpdate);
        document.removeEventListener('app:list-item.selection-changed:relay', this.boundHandleItemSelectionChange);
        document.removeEventListener('app:list.refresh-request', this.boundHandleGlobalRefresh);
        document.removeEventListener('app:base-données:sélection-request', this.boundHandleDBRequest);
        document.removeEventListener('app:base-données:sélection-executed', this.boundHandleDBResult);
        document.removeEventListener('app:base-données:données-loaded', this.boundHandleDonneesLoaded);
    }

    /**
     * NOUVEAU : Dispatche un événement customisé sur le document.
     * @param {string} name Le nom de l'événement
     * @param {object} detail Les données à envoyer
     */
    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }

    /**
     * --- NOUVELLE MÉTHODE ---
     * C'est le "récepteur". Il écoute les événements de sélection globaux
     * et met à jour l'état visuel de cette liste si elle est concernée.
     */
    handleGlobalSelectionUpdate(event) {
        console.log(this.nomControleur + " - Réception de l'événement 'ui:selection.changed'", event.detail);
        // Garde-fou 1 : On ne réagit que si la liste est visible à l'écran.
        // offsetParent est null si l'élément ou un de ses parents a 'display: none'.
        if (this.element.offsetParent === null) {
            console.log(this.nomControleur + " - La liste est cachée, mise à jour ignorée.");
            return; // On ne fait rien si on est une liste cachée.
        }

        // Garde-fou 2 : On ne doit pas traiter l'événement si c'est cette instance même qui l'a émis.
        // On vérifie si l'événement a été marqué par notre propre contrôleur.
        if (event.detail.source === this.nomControleur) {
            console.log(this.nomControleur + " - Événement ignoré car il provient de cette même instance.");
            return; 
        }

        const restoredSelectionIds = new Set((event.detail.selection || []).map(id => String(id))); // On s'assure que les IDs sont des chaînes pour la comparaison

        // On met à jour la sélection interne du contrôleur.
        this.tabSelectedCheckBoxs = Array.from(restoredSelectionIds);

        // On parcourt toutes les cases à cocher de CETTE liste et on met à jour leur état visuel.
        this.rowCheckboxTargets.forEach(checkbox => {
            const checkboxId = String(checkbox.dataset.idobjetValue); // On s'assure que l'ID est une chaîne
            const shouldBeChecked = restoredSelectionIds.has(checkboxId);
            checkbox.checked = shouldBeChecked;

            const row = checkbox.closest('tr');
            if (row) {
                row.classList.toggle('row-selected', shouldBeChecked); // Applique la classe si coché, la retire sinon
            }
        });

        // On met à jour l'état de la case "Tout cocher" pour refléter le nouvel état.
        this.updateSelectAllCheckboxState();
    }
    


    /**
     * Propage un événement de réponse personnalisé contenant les résultats (HTML) ou une erreur.
     * @param {object[]|null} results - Les données de la base de données.
     * @param {string|null} error - Le message d'erreur, le cas échéant.
     */
    dispatchResponse(results, error) {
        this.dispatch('app:base-données:sélection-executed', {
            results: results,
            error: error,
            isSuccess: !error,
        });
    }


    handleItemSelectionChange(event) {
        const { id, isChecked, entity, canvas, entityType } = event.detail;
        const idObjet = String(id); // S'assurer que c'est une chaîne

        console.log(`${this.nomControleur} - Traitement du changement de sélection pour l'ID ${idObjet}. Nouvel état : ${isChecked}`);
        var entityJSON = null;
        var canvasJSON = null;

        if (!entity || !canvas) {
            console.error("Attributs data-entity ou data-entity-canvas manquants sur l'élément cliqué.", element);
            return;
        }

        // 1. Les données sont déjà des objets grâce au JSON.parse dans liste-element
        entityJSON = entity;
        canvasJSON = canvas;

        // 2. (Optionnel mais recommandé) On vérifie que l'ID existe avant d'envoyer
        if (typeof entityJSON.id === 'undefined' || entityJSON.id === null) {
            console.error("L'entité reçue n'a pas d'ID valide.", entityJSON);
            return;
        }


        if (isChecked) {
            if (!this.tabSelectedCheckBoxs.includes(idObjet)) {
                this.tabSelectedCheckBoxs.push(idObjet);
                //Utiles pour le panneau à onglets
                this.tabSelectedEntities.push(entityJSON);
                this.selectedEntitiesType = entityType;
                this.selectedEntitiesCanvas = canvasJSON;
            }
        } else {
            const index = this.tabSelectedCheckBoxs.indexOf(idObjet);
            if (index > -1) {
                this.tabSelectedCheckBoxs.splice(index, 1);
            }

            if (entityJSON && canvasJSON) {
                // On cherche l'index de l'entité dont l'ID correspond à 'idObjet'.
                const indexEntity = this.tabSelectedEntities.findIndex(e => e.id == idObjet);
                if (indexEntity > -1) {
                    this.tabSelectedEntities.splice(indexEntity, 1);

                    // Ajouté : Si la liste des entités sélectionnées est maintenant vide...
                    if (this.tabSelectedEntities.length === 0) {
                        // ...on réinitialise le type et le canvas.
                        this.selectedEntitiesType = null;
                        this.selectedEntitiesCanvas = null;
                    }
                }
            }
        }
        
        // --- CORRECTION : Mettre à jour la case "Tout cocher" AVANT de publier ---
        this.updateSelectAllCheckboxState();
        this.publierSelection();
    }

    updateSelectAllCheckboxState() {
        const allChecked = this.rowCheckboxTargets.every(checkbox => checkbox.checked);
        const someChecked = this.rowCheckboxTargets.some(checkbox => checkbox.checked);

        if (allChecked) {
            this.selectAllCheckboxTarget.checked = true;
            this.selectAllCheckboxTarget.indeterminate = false;
        } else if (someChecked) {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = true;
        } else {
            this.selectAllCheckboxTarget.checked = false;
            this.selectAllCheckboxTarget.indeterminate = false;
        }
    }

    handleDeleteRequest(event) {
        const { titre, action, selection } = event.detail;
        console.log(this.nomControleur + " - handleDeleteRequest", event.detail);
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.delete-request', { selection: this.tabSelectedCheckBoxs });
    }

    handleModifyRequest(event) {
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.edit-request', {
            entity: this.tabSelectedEntities[0],
            entityFormCanvas: this.entityFormCanvasValue
        });
    }

    handleAddRequest(event) {
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:toolbar.add-request', {
            entity: {},
            entityFormCanvas: this.entityFormCanvasValue
        });
    }

    /**
     * Méthode centralisée pour envoyer un événement au cerveau.
     * @param {string} type Le type d'événement pour le cerveau (ex: 'ui:toolbar.add-request').
     * @param {object} payload Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        console.log(`${this.nomControleur} - Notification du cerveau: ${type}`, payload);
        this.dispatch('cerveau:event', {
            type: type,
            source: this.nomControleur,
            payload: payload,
            timestamp: Date.now()
        });
    }

    /**
     * Récupère toutes les entités de la liste actuellement affichée à l'écran.
     * @returns {Array<Object>} Un tableau contenant tous les objets entité.
     */
    getAllEntities() {
        // 'this.rowCheckboxTargets' est un tableau fourni par Stimulus
        // contenant tous les éléments <input> ayant le target "rowCheckbox".
        return this.rowCheckboxTargets.map(checkbox => {
            // Pour chaque case à cocher, on lit son attribut data-entity.
            const entityData = checkbox.dataset.entity;
            try {
                // On parse la chaîne JSON pour la transformer en véritable objet JavaScript.
                return JSON.parse(entityData);
            } catch (e) {
                console.error("Erreur de parsing JSON sur une ligne :", entityData, e);
                return null; // Retourne null si une donnée est corrompue
            }
        }).filter(entity => entity !== null); // On retire les éventuelles erreurs de parsing
    }

    publierSelection() {
        console.log(this.nomControleur + " - Publication de la sélection locale vers le Cerveau.");
        // NOUVEAU : On notifie le cerveau de l'état de sélection complet.
        this.dispatch('cerveau:event', {
            type: 'ui:selection.updated',
            source: this.nomControleur,
            payload: {
                selection: this.tabSelectedCheckBoxs,
                entities: this.tabSelectedEntities,
                canvas: this.selectedEntitiesCanvas,
                entityType: this.selectedEntitiesType,
                entityFormCanvas: this.entityFormCanvasValue, // AJOUT : Toujours inclure le canvas du formulaire
            },
            timestamp: Date.now()
        }); 
    }


    initToolTips() {
        //On initialise le tooltips
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
    }

    
    /**
     * NOUVELLE VERSION : Notifie le contrôleur parent pour afficher un message.
     */
    updateMessage(titre, message) {
        // --- MODIFICATION : Communication via le cerveau ---
        this.notifyCerveau('ui:status.notify', {
            titre: titre,
            message: message
        });
    }


    handleDonneesLoaded(event) {
        const { status, page, limit, totalitems } = event.detail;

        this.nbelementsValue = totalitems;
        this.tabSelectedCheckBoxs = [];
        this.updateMessageSelectedCheckBoxes();
        this.publierSelection();

        console.log(this.nomControleur + " - handleDonneesLoaded" + event.datil);
        this.updateMessage(status.message);
    }

    /**
     * 
     */
    updateMessageSelectedCheckBoxes() {
        if (this.tabSelectedCheckBoxs.length != 0) {
            this.updateMessage(this.tabSelectedCheckBoxs.length + " éléments cochés.");
        }
    }

    /**
     * Gère l'événement de demande de recherche.
     * @param {CustomEvent} event - L'événement doit contenir { detail: { entityName: '...', criteria: {...} } }
     */
    async handleDBRequest(event) {
        const { criteria } = event.detail;
        const entityName = this.entiteValue;
        const page = 1;
        const limit = 100;

        if (!entityName || !criteria) {
            console.error('Event "app:base-données:sélection-request" is missing "entityName" or "criteria" in detail.', event.detail);
            this.dispatchResponse(null, 'Missing parameters in event detail.');
            return;
        }

        // --- MODIFICATION : Afficher le spinner avant la requête ---
        this.donneesTarget.innerHTML = `<div class="spinner-container"><div class="custom-spinner"></div></div>`;
        // ---------------------------------------------------------

        try {
            const response = await fetch(this.urlAPIDynamicQuery, { // La requête est déjà attendue, c'est bien.
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ entityName, criteria, page, limit }),
            });

            const responseData = await response.text(); // On attend la réponse texte.
            
            // MODIFICATION CLÉ : On propage le résultat APRÈS avoir reçu la réponse.
            this.dispatchResponse(responseData, null); // Succès : propage les résultats
        } catch (error) {
            // Gère les erreurs réseau ou de parsing JSON
            console.error(this.nomControleur + ' - Fetch error:', error);
            this.dispatchResponse(null, error.message);
            // --- MODIFICATION : Afficher une erreur à la place du spinner ---
            this.donneesTarget.innerHTML = `<div class="alert alert-danger m-3">Erreur de chargement: ${error.message}</div>`;
        }
    }


    handleDBResult(event) {
        const { results, error, isSuccess } = event.detail;
        console.log(this.nomControleur + " - handleDBResult", event.detail);
        //Ici on redessine la liste des données
        this.donneesTarget.innerHTML = results;
    }

    /**
     * NOUVEAU : Gère la demande de rafraîchissement globale.
     */
    handleGlobalRefresh(event) {
        // On ne rafraîchit que la liste principale, pas les collections
        if (this.element.closest('[data-content-id="principal"]')) {
            console.log(this.nomControleur + " - Demande de rafraîchissement global reçue. Rechargement de la liste.");
            this.dispatch('app:base-données:sélection-request', { criteria: {} }); // Relance une recherche vide pour tout réafficher
        }
    }
}