import { Controller } from '@hotwired/stimulus';

/**
 * @class CollectionController
 * @extends Controller
 * @description Gère un widget de formulaire de type collection.
 * Il est responsable de l'affichage de la liste, de l'ajout, de la modification
 * et de la suppression d'éléments via des boîtes de dialogue modales.
 */
export default class extends Controller {
    static targets = [
        "contentPanel",
        "listContainer",
        "addButtonContainer",
        "countBadge",
        "rowActions" // NOUVEAU : Cible pour les conteneurs d'actions de ligne
    ];

    static values = {
        //Fournies déjà par les options du _form_canvas.html.twig
        listUrl: String,
        itemFormUrl: String,
        itemSubmitUrl: String,
        itemDeleteUrl: String,
        itemTitleCreate: String,
        itemTitleEdit: String,
        idEntreprise: Number,
        idInvite: Number,
        parentEntityId: Number,
        
        //Fournies par _dialog_list_component.html.twig
        entiteNom: String,
        serverRootName: String,
        listeCanvas: Object,
        entityCanvas: Object,
        entityFormCanvas: Object,
        numericAttributes: Array,
        parentFieldName: String,
        disabled: Boolean, // NOUVEAU : Pour gérer l'état activé/désactivé
        context: Object, // NOUVEAU : Pour recevoir le contexte d'un dialogue parent
    };

    /**
     * @property {Object} hideTimeouts - Stocke les minuteurs pour masquer les boutons.
     * @private
     */
    hideTimeouts = {};

    connect() {
        this.nomControleur = "Collection";
        console.log(`${this.nomControleur} - Connecté.`);
        this.boundRefresh = this.refresh.bind(this);
        // Écoute l'événement de sauvegarde pour se rafraîchir
        document.addEventListener('app:list.refresh-request', this.boundRefresh);
        this.load();
    }

    disconnect() {
        document.removeEventListener('app:list.refresh-request', this.boundRefresh);
    }

    /**
     * Charge ou recharge le contenu de la liste via AJAX.
     */
    async load() {
        console.groupCollapsed(this.nomControleur + " - Load() - Code:1986");
        console.log("| " + this.nomControleur + " - id:", this.element.id);
        console.log("| " + this.nomControleur + " - listUrl:", this.listUrlValue);
        console.log("| " + this.nomControleur + " - itemFormUrl:", this.itemFormUrlValue);
        console.log("| " + this.nomControleur + " - itemSubmitUrl:", this.itemSubmitUrlValue);
        console.log("| " + this.nomControleur + " - itemDeleteUrl:", this.itemDeleteUrlValue);
        console.log("| " + this.nomControleur + " - itemTitleCreate:", this.itemTitleCreateValue);
        console.log("| " + this.nomControleur + " - itemTitleEdit:", this.itemTitleEditValue);
        console.log("| " + this.nomControleur + " - idEntreprise:", this.idEntrepriseValue);
        console.log("| " + this.nomControleur + " - idInvite:", this.idInviteValue);
        console.log("| " + this.nomControleur + " - parentEntityId:", this.parentEntityIdValue);
        console.log("| " + this.nomControleur + " - parentFieldName:", this.parentFieldNameValue);
        console.log("| " + this.nomControleur + " - disabledValue:", this.disabledValue);
        console.log("| " + this.nomControleur + " - context:", this.contextValue);
        console.log("********");
        console.log("| " + this.nomControleur + " - entiteNom:", this.entiteNomValue);
        console.log("| " + this.nomControleur + " - serverRoot:", this.serverRootNameValue);
        console.log("| " + this.nomControleur + " - listeCanvas:", this.listeCanvasValue);
        console.log("| " + this.nomControleur + " - entityCanvas:", this.entityCanvasValue);
        console.log("| " + this.nomControleur + " - Contenu de entityCanvasValue:", JSON.stringify(this.entityCanvasValue, null, 2));
        console.log("| " + this.nomControleur + " - entityFormCanvas:", this.entityFormCanvasValue);
        console.log("| " + this.nomControleur + " - numericAttributes:", this.numericAttributesValue);
        console.groupEnd();

        if (!this.listUrlValue || this.disabledValue) {
            // console.error(`${this.nomControleur} - Aucune URL n'est définie pour charger la collection.`);
            this.listContainerTarget.innerHTML = '<div class="alert alert-warning">Configuration manquante: URL de chargement non définie.</div>';
            return;
        }

        try {
            //Tout est activé car l'objet parent est maintenant disponible,
            //On doit charger les élements de la collection
            const dialogListUrl = this.listUrlValue + "/dialog";
            console.log(this.nomControleur + " Load() - Code:1986 - Actualisation de la Collection", dialogListUrl);
            const response = await fetch(dialogListUrl);
            if (!response.ok) throw new Error(`Erreur serveur: ${response.statusText}`);

            const html = await response.text();
            this.listContainerTarget.innerHTML = html;

            console.log(this.nomControleur + " Load() - Code:1986 - Collection " + this.element.id + " via '" + this.listUrlValue + "' est chargée.");
            this.updateCount();
        } catch (error) {
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger">Impossible de charger la liste: ${error.message}</div>`;
            console.error(`${this.nomControleur} - Erreur lors du chargement de la collection:`, error, this.listUrlValue);
        }
    }

    /**
     * Active le widget et charge son contenu. Appelé par dialog-instance après une création.
     * @param {number} parentId - Le nouvel ID de l'entité parente.
     */
    enableAndLoad(parentId) {
        console.log(this.nomControleur + " - EnableAndLoad()");
        //Affectation des variables sur le nouveau parent
        this.parentEntityIdValue = parentId;

        //On reactive la collection sur la plan visuel et logique
        this.element.classList.remove('is-disabled');
        this.disabledValue = false;
        //On reactive le bouton d'ajout
        if (this.hasAddButtonContainerTarget) {
            this.addButtonContainerTarget.style.opacity = '0';
        }
        console.log(this.nomControleur + " - EnableAndLoad() - Code:1986 - Correction de l'URL de chargement de la collection: " + this.listUrlValue);
        this.listUrlValue = this.listUrlValue.replace('/api/0/', `/api/${parentId}/`);
        console.log(this.nomControleur + " - EnableAndLoad() - Code:1986 - Correction de l'URL de chargement de la collection: " + this.listUrlValue);
        
        this.load();
    }

    // --- MÉTHODES RESTAURÉES POUR L'INTERACTIVITÉ DE L'ACCORDÉON ---

    /**
     * Affiche ou masque le contenu de l'accordéon.
     */
    toggleAccordion() {
        // On ne permet pas d'ouvrir l'accordéon s'il est désactivé
        if (this.disabledValue) return;

        this.contentPanelTarget.classList.toggle('is-open');
        const icon = this.element.querySelector('.toggle-icon');
        if (icon) {
            icon.textContent = this.contentPanelTarget.classList.contains('is-open') ? '-' : '+';
        }
    }

    /**
     * Affiche un message dans la console lorsque la souris entre dans la zone du titre.
     * Ne fait rien si le widget est désactivé (mode création).
     */
    logMouseEnter() {
        // console.log(`${this.nomControleur} - Souris entrée sur le titre de l'accordéon (mode édition).`, this.addButtonContainerTarget);
        if (!this.disabledValue) {
            if (this.hasAddButtonContainerTarget) {
                this.addButtonContainerTarget.style.opacity = '1';
            }
        }
    }

    /**
     * Affiche un message dans la console lorsque la souris quitte la zone du titre.
     * Ne fait rien si le widget est désactivé (mode création).
     */
    logMouseLeave() {
        // console.log(`${this.nomControleur} - Souris sortie du titre de l'accordéon (mode édition).`, this.addButtonContainerTarget);
        if (!this.disabledValue) {
            if (this.hasAddButtonContainerTarget) {
                this.addButtonContainerTarget.style.opacity = '0';
            }
        }
    }

    /**
     * Affiche les boutons d'action pour une ligne survolée.
     * @param {MouseEvent} event
     */
    showRowActions(event) {
        const row = event.currentTarget;
        const actionsContainer = row.querySelector('[data-collection-target="rowActions"]');

        if (actionsContainer) {
            // Annule tout minuteur de masquage existant pour cette ligne
            if (this.hideTimeouts[row.id]) {
                clearTimeout(this.hideTimeouts[row.id]);
            }
            actionsContainer.classList.add('visible');
        }
    }

    /**
     * Masque les boutons d'action après un délai lorsque la souris quitte une ligne.
     * @param {MouseEvent} event
     */
    hideRowActions(event) {
        const row = event.currentTarget;
        const actionsContainer = row.querySelector('[data-collection-target="rowActions"]');

        if (actionsContainer) {
            // Lance un minuteur pour masquer les boutons après 3 secondes
            this.hideTimeouts[row.id] = setTimeout(() => {
                actionsContainer.classList.remove('visible');
            }, 800);
        }
    }


    /**
     * Rafraîchit la liste si l'événement de sauvegarde concerne cette collection.
     * @param {CustomEvent} event
     */
    refresh(event) {
        console.log(this.nomControleur + " refresh - (2/5) Actualisation de la Collection:", event);
        // L'ID 'originatorId' est l'ID de l'élément HTML du contrôleur collection
        // qui a initié l'action. On ne rafraîchit que si c'est nous.
        if (event.detail.originatorId === this.element.id) {
            console.log(`${this.nomControleur} - Rafraîchissement demandé.`);
            this.load();
        }
    }

    /**
     * Met à jour le badge affichant le nombre d'éléments.
     */
    updateCount() {
        if (this.hasCountBadgeTarget) {
            const count = this.listContainerTarget.querySelectorAll('[data-item-id]').length;
            this.countBadgeTarget.textContent = count;
            this.countBadgeTarget.style.display = count > 0 ? 'inline-block' : 'none';
        }
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour ajouter un nouvel élément.
     */
    addItem(event) {
        console.log(this.nomControleur + " (0) - addItem()");
        // ce qui évite de déclencher l'action 'toggleAccordion' du titre.
        event.stopPropagation();

        // Contexte du parent immédiat (celui de la collection)
        const parentContext = {};
        if (this.parentFieldNameValue && this.parentEntityIdValue) {
            parentContext[this.parentFieldNameValue] = this.parentEntityIdValue;
        }
        console.log(this.nomControleur + " - parentContext (addItem):", parentContext);

        //Les variables à transporter
        const entity = {};// Entité vide pour la création, avec l'id pour l'édition
        const isCreationMode = true;
        const entityFormCanvas = {
            parametres: {
                titre_creation: this.itemTitleCreateValue,
                titre_modification: this.itemTitleEditValue,
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
                isCreationMode: true,
            }
        };
        const context = {
            // On fusionne le contexte reçu du dialogue parent (s'il existe)
            originatorId: this.element.id, // On s'identifie pour le rafraîchissement
            ...parentContext, // Le parent immédiat écrase toute clé identique (ce qui est correct)
        };

        console.groupCollapsed(`${this.nomControleur} - addItem - EDITDIAL(0)`);
        console.log(`| Mode: ${isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', entity);
        console.log('| Contexte:', context);
        console.log('| Canvas:', entityFormCanvas);
        console.groupEnd();

        this.notifyCerveau('ui:boite-dialogue:add-collection-item-request', {
            entity: entity, // Entité vide pour la création
            isCreationMode: isCreationMode,
            entityFormCanvas: entityFormCanvas,
            context: context
        });
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour modifier un élément existant.
     * @param {MouseEvent} event
     */
    editItem(event) {
        console.log(this.nomControleur + " (0) - editItem()");
        // CORRECTION : On cherche l'ID sur la ligne parente (tr) la plus proche.
        const row = event.currentTarget.closest('tr');
        if (!row || !row.dataset.itemId) return;
        const itemId = row.dataset.itemId;

        // Contexte du parent immédiat (celui de la collection)
        const parentContext = {};
        if (this.parentFieldNameValue && this.parentEntityIdValue) {
            parentContext[this.parentFieldNameValue] = this.parentEntityIdValue;
        }
        // --- DÉBOGAGE : Affichage des informations parentes récupérées ---
        console.log(`${this.nomControleur} - editItem - Infos parent récupérées:`, {
            parentFieldName: this.parentFieldNameValue,
            parentEntityId: this.parentEntityIdValue
        });

        //Les variables à transporter
        const entity = { id: itemId };// Entité vide pour la création, avec l'id pour l'édition
        const isCreationMode = false;
        const entityFormCanvas = {
            parametres: {
                titre_creation: this.itemTitleCreateValue,
                titre_modification: this.itemTitleEditValue,
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
                isCreationMode: false,
            }
        };
        const context = {
            // On fusionne le contexte hérité du dialogue parent (ex: {notificationSinistre: 123})
            ...this.contextValue,
            originatorId: this.element.id, // On s'identifie pour le rafraîchissement
            ...parentContext, // Le parent immédiat écrase toute clé identique (ce qui est correct)
        };

        console.groupCollapsed(`${this.nomControleur} - editItem - EDITDIAL(0)`);
        console.log(`| Mode: ${isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', entity);
        console.log('| Contexte:', context);
        console.log('| Canvas:', entityFormCanvas);
        console.groupEnd();

        this.notifyCerveau('ui:boite-dialogue:add-collection-item-request', {
            entity: entity,
            isCreationMode: isCreationMode,
            entityFormCanvas: entityFormCanvas,
            context: context
        });
    }

    /**
     * Demande la confirmation avant de supprimer un élément.
     * @param {MouseEvent} event
     */
    deleteItem(event) {
        // CORRECTION : On cherche l'ID sur la ligne parente (tr) la plus proche.
        const row = event.currentTarget.closest('tr');
        if (!row || !row.dataset.itemId) return;
        const itemId = row.dataset.itemId;

        console.log(this.nomControleur + " (0) - deleteItem(): " + itemId);

        // CORRECTION : On utilise le même événement que la toolbar pour la cohérence.
        this.notifyCerveau('ui:toolbar.delete-request', {
            title: 'Confirmation de suppression',
            body: `Êtes-vous sûr de vouloir supprimer cet élément ?`, // Message personnalisé
            selection: [itemId], // On passe l'ID dans un tableau pour être compatible avec le cerveau
            // On passe les informations nécessaires à l'action de suppression
            actionConfig: {
                url: this.itemDeleteUrlValue,
                originatorId: this.element.id // L'ID de la collection, pour le rafraîchissement
            }
        });
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type
     * @param {object} payload
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}
