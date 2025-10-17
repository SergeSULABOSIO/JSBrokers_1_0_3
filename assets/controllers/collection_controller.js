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
        "countBadge"
    ];

    static values = {
        // --- AMÉLIORATION : Utiliser les URLs fournies par le serveur ---
        listUrl: String, // URL pour charger la liste des items
        itemFormUrl: String, // URL pour obtenir le formulaire d'un item
        itemSubmitUrl: String, // URL pour soumettre le formulaire d'un item
        itemDeleteUrl: String, // URL pour supprimer un item
        itemTitleCreate: String, // Titre pour la création
        itemTitleEdit: String, // Titre pour l'édition
        parentEntityId: Number,
        parentFieldName: String,
        disabled: Boolean, // NOUVEAU : Pour gérer l'état activé/désactivé
    };

    connect() {
        this.nomControleur = "Collection";
        console.log(`${this.nomControleur} - Connecté.`);
        this.boundRefresh = this.refresh.bind(this);
        // Écoute l'événement de sauvegarde pour se rafraîchir
        document.addEventListener('app:list.refresh-request', this.boundRefresh);
        // --- CORRECTION : Ne charge pas si le widget est désactivé ---
        if (this.disabledValue) {
            this.listContainerTarget.innerHTML = '<div class="alert alert-info">Veuillez d\'abord enregistrer l\'élément principal pour pouvoir ajouter des pièces.</div>';
        } else {
            this.load();
        }
        this.verbaliser();
    }

    disconnect() {
        document.removeEventListener('app:list.refresh-request', this.boundRefresh);
    }

    verbaliser(){
        console.log(this.nomControleur + " - Options - listUrlValue:", this.listUrlValue);
        console.log(this.nomControleur + " - Options - itemFormUrlValue:", this.itemFormUrlValue);
        console.log(this.nomControleur + " - Options - itemSubmitUrlValue:", this.itemSubmitUrlValue);
        console.log(this.nomControleur + " - Options - itemDeleteUrlValue:", this.itemDeleteUrlValue); 
        console.log(this.nomControleur + " - Options - itemTitleCreateValue:", this.itemTitleCreateValue);
        console.log(this.nomControleur + " - Options - itemTitleEditValue:", this.itemTitleEditValue);
        console.log(this.nomControleur + " - Options - parentEntityIdValue:", this.parentEntityIdValue);
        console.log(this.nomControleur + " - Options - parentFieldNameValue:", this.parentFieldNameValue);
        console.log(this.nomControleur + " - Options - disabledValue:", this.disabledValue);
    }

    /**
     * Charge ou recharge le contenu de la liste via AJAX.
     */
    async load() {
        // --- CORRECTION : Vérification de l'état désactivé ---
        const dialogListUrl = this.listUrlValue + "/dialog";
        console.log(this.nomControleur + " load - (3/5) Actualisation de la Collection", dialogListUrl);
        this.verbaliser();
        if (!this.listUrlValue || this.disabledValue) {
            // console.error(`${this.nomControleur} - Aucune URL n'est définie pour charger la collection.`);
            this.listContainerTarget.innerHTML = '<div class="alert alert-warning">Configuration manquante: URL de chargement non définie.</div>';
            return;
        }

        try {
            console.log(this.nomControleur + " refresh - (4/5) Actualisation de la Collection, listUrl:" + dialogListUrl);
            const response = await fetch(dialogListUrl);
            if (!response.ok) throw new Error(`Erreur serveur: ${response.statusText}`);

            const html = await response.text();
            this.listContainerTarget.innerHTML = html;
            this.updateCount();
            console.log(this.nomControleur + " refresh - (5/5) fin de l'Actualisation de la Collection." + html);
        } catch (error) {
            console.error(`${this.nomControleur} - Erreur lors du chargement de la collection:`, error, this.listUrlValue);
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger">Impossible de charger la liste: ${error.message}</div>`;
        }
    }

    /**
     * Active le widget et charge son contenu. Appelé par dialog-instance après une création.
     * @param {number} parentId - Le nouvel ID de l'entité parente.
     */
    enableAndLoad(parentId) {
        console.log(this.nomControleur + " - PASSATION ID PARENT VERS COLLECTION:", parentId);
        this.verbaliser();
        this.parentEntityIdValue = parentId;
        this.listUrlValue = this.listUrlValue.replace('/api/0/', `/api/${parentId}/`);
        this.disabledValue = false;
        this.element.classList.remove('is-disabled');
        console.log(`${this.nomControleur} - (4) Collection activée. L'ID parent est maintenant: ${this.parentEntityIdValue}. URL de liste mise à jour: ${this.listUrlValue}`);

        // CORRECTION : On s'assure que le bouton est invisible au départ en mode édition.
        // Le survol le rendra visible.
        if (this.hasAddButtonContainerTarget) {
            this.addButtonContainerTarget.style.opacity = '0';
        }
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
        this.verbaliser();
        // ce qui évite de déclencher l'action 'toggleAccordion' du titre.
        event.stopPropagation();
        console.log(`${this.nomControleur} - (5) Clic sur 'Ajouter'. Demande d'ouverture du formulaire de tâche.`);
        console.log(`${this.nomControleur} - (6) L'ID de l'entité parente (${this.parentEntityIdValue}) va être inclus dans le contexte sous la clé '${this.parentFieldNameValue}'.`);
        console.log(`${this.nomControleur} - AddItem - PARENT - ATTRIBUT: ${this.parentFieldNameValue}.`);
        console.log(`${this.nomControleur} - AddItem - PARENT - ID: ${this.parentEntityIdValue}.`);

        // On construit dynamiquement l'objet de contexte pour l'ID parent.
        const parentContext = {};
        if (this.parentFieldNameValue && this.parentEntityIdValue) {
            parentContext[this.parentFieldNameValue] = this.parentEntityIdValue;
        }
        this.notifyCerveau('ui:boite-dialogue:add-collection-item-request', {
            entity: {}, // Entité vide pour la création
            isCreationMode: true,
            entityFormCanvas: {
                parametres: {
                    titre_creation: this.itemTitleCreateValue,
                    titre_modification: this.itemTitleEditValue,
                    endpoint_form_url: this.itemFormUrlValue,
                    endpoint_submit_url: this.itemSubmitUrlValue,
                    isCreationMode: true,
                }
            },
            idEntreprise: this.idEntrepriseValue,
            idInvite: this.idInviteValue,
            context: {
                originatorId: this.element.id, // On s'identifie pour le rafraîchissement
                ...parentContext
            }
        });
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour modifier un élément existant.
     * @param {MouseEvent} event
     */
    editItem(event) {
        this.verbaliser();
        const itemId = event.currentTarget.dataset.itemId;
        this.notifyCerveau('app:boite-dialogue:init-request', {
            entity: { id: itemId }, // On passe juste l'ID pour l'édition
            entityFormCanvas: {
                parametres: {
                    titre_modification: this.itemTitleEditValue,
                    endpoint_form_url: this.itemFormUrlValue,
                    endpoint_submit_url: this.itemSubmitUrlValue,
                }
            },
            idEntreprise: this.idEntrepriseValue,
            idInvite: this.idInviteValue,
            context: {
                originatorId: this.element.id
            }
        });
    }

    /**
     * Demande la confirmation avant de supprimer un élément.
     * @param {MouseEvent} event
     */
    deleteItem(event) {
        const itemId = event.currentTarget.dataset.itemId;

        this.notifyCerveau('ui:confirmation.request', {
            title: 'Confirmation de suppression',
            body: `Êtes-vous sûr de vouloir supprimer cet élément ?`,
            onConfirm: {
                type: 'app:api.delete-request', // Le cerveau relaiera cette demande
                payload: {
                    url: this.itemDeleteUrlValue,
                    originatorId: this.element.id
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
}
