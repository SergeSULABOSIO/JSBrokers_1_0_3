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
        url: String,
        listUrl: String,
        itemFormUrl: String,
        itemSubmitUrl: String,
        itemDeleteUrl: String,
        itemTitleCreate: String,
        itemTitleEdit: String,
        parentFieldName: String,
        parentEntityId: Number,
        disabled: Boolean,
        entiteNom: String,
        idEntreprise: Number,
        idInvite: Number,
        context: Object,
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
        if (this.disabledValue) {
            console.log(`${this.nomControleur} - load() - Code: 1986 - disabledValue: `, this.disabledValue);
            this.listContainerTarget.innerHTML = '<div class="alert alert-warning">Commencez par enregistrer.</div>';
            return;
        }
        if (!this.listUrlValue) {
            console.log(`${this.nomControleur} - load() - Code: 1986 - listUrlValue: `, this.listUrlValue);
            this.listContainerTarget.innerHTML = "<div class='alert alert-warning'>L'url de la liste n'est pas définie.</div>";
            return;
        }

        try {
            //Tout est activé car l'objet parent est maintenant disponible,
            //On doit charger les élements de la collection
            const dialogListUrl = this.listUrlValue + "/dialog";
            const response = await fetch(dialogListUrl);
            if (!response.ok) throw new Error(`Erreur serveur: ${response.statusText}`);

            const html = await response.text();
            this.listContainerTarget.innerHTML = html;

            this.updateCount();
            this._logState('load', '1986 avec /dialog done.');
        } catch (error) {
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger">Impossible de charger la liste: ${error.message}</div>`;
            console.error(`${this.nomControleur} - Erreur lors du chargement de la collection:`, error, this.listUrlValue);
        }
    }


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
        // L'ID 'originatorId' est l'ID de l'élément HTML du contrôleur collection
        // qui a initié l'action. On ne rafraîchit que si c'est nous.
        if (event.detail.originatorId === this.element.id) {
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
        this._logState("addItem", "1986");
        // ce qui évite de déclencher l'action 'toggleAccordion' du titre.
        event.stopPropagation();

        // Contexte du parent immédiat (celui de la collection)
        const parentContext = {};
        if (this.parentFieldNameValue && this.parentEntityIdValue) {
            parentContext[this.parentFieldNameValue] = this.parentEntityIdValue;
        }

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

        // On utilise le même événement que la toolbar pour une logique unifiée dans le cerveau.
        this.notifyCerveau('ui:dialog.open-request', {
            entity: entity, // Entité vide pour la création
            isCreationMode: isCreationMode,
            entityFormCanvas: entityFormCanvas,
            context: context,
            // On passe le contexte du parent pour l'imbrication
            parentContext: {
                id: this.parentEntityIdValue,
                fieldName: this.parentFieldNameValue
            }
        });
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour modifier un élément existant.
     * @param {MouseEvent} event
     */
    editItem(event) {
        // CORRECTION : On cherche l'ID sur la ligne parente (tr) la plus proche.
        const row = event.currentTarget.closest('tr');
        if (!row || !row.dataset.itemId) return;
        const itemId = row.dataset.itemId;

        // Contexte du parent immédiat (celui de la collection)
        const parentContext = {};
        if (this.parentFieldNameValue && this.parentEntityIdValue) {
            parentContext[this.parentFieldNameValue] = this.parentEntityIdValue;
        }

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

        // On utilise le même événement que la toolbar pour une logique unifiée dans le cerveau.
        this.notifyCerveau('ui:dialog.open-request', {
            entity: entity,
            isCreationMode: isCreationMode,
            entityFormCanvas: entityFormCanvas,
            context: context,
            parentContext: {
                id: this.parentEntityIdValue,
                fieldName: this.parentFieldNameValue
            }
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

        // On notifie le cerveau avec une demande de suppression simple et claire.
        // C'est le cerveau qui construira la demande de confirmation complexe.
        this.notifyCerveau('ui:toolbar.delete-request', {
            selection: [{ id: itemId }], // On simule un objet "selecto" pour être compatible avec la logique du cerveau
            formCanvas: {
                parametres: {
                    // On fournit juste l'URL de suppression, c'est tout ce dont le cerveau a besoin.
                    endpoint_delete_url: this.itemDeleteUrlValue,
                }
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

    /**
     * Affiche l'état actuel des valeurs du contrôleur dans la console pour le débogage.
     * @param {string} callingFunction - Le nom de la fonction qui appelle ce logger.
     * @param {string} code - Un code de suivi pour filtrer les logs.
     * @private
     */
    _logState(callingFunction, code) {
        console.groupCollapsed(`${this.nomControleur} - ${callingFunction}() - Code:${code}`);
        console.log(`| id:`, this.element.id);
        console.log(`| url:`, this.urlValue);
        console.log(`| listUrl:`, this.listUrlValue);
        console.log(`| itemFormUrl:`, this.itemFormUrlValue);
        console.log(`| itemSubmitUrl:`, this.itemSubmitUrlValue);
        console.log(`| itemDeleteUrl:`, this.itemDeleteUrlValue);
        console.log(`| itemTitleCreate:`, this.itemTitleCreateValue);
        console.log(`| itemTitleEdit:`, this.itemTitleEditValue);
        console.log(`| parentEntityId:`, this.parentEntityIdValue);
        console.log(`| parentFieldName:`, this.parentFieldNameValue);
        console.log(`| disabledValue:`, this.disabledValue);
        console.log(`| entiteNom:`, this.entiteNomValue);
        console.log(`| idEntreprise:`, this.idEntrepriseValue);
        console.log(`| idInvite:`, this.idInviteValue);
        console.log(`| context:`, this.contextValue);
        console.groupEnd();
    }
}
