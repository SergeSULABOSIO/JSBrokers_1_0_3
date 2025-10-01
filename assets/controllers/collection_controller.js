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
        url: String, // URL pour charger la liste des items
        createTitle: String, // Titre pour la création
        editTitle: String, // Titre pour l'édition
    };

    connect() {
        this.nomControleur = "Collection";
        console.log(`${this.nomControleur} - Connecté.`);

        this.boundRefresh = this.refresh.bind(this);
        // Écoute l'événement de sauvegarde pour se rafraîchir
        document.addEventListener('app:entity.saved', this.boundRefresh);

        this.load();
    }

    disconnect() {
        document.removeEventListener('app:entity.saved', this.boundRefresh);
    }

    /**
     * Charge ou recharge le contenu de la liste via AJAX.
     */
    async load() {
        if (!this.urlValue) {
            console.error(`${this.nomControleur} - Aucune URL n'est définie pour charger la collection.`);
            this.listContainerTarget.innerHTML = '<div class="alert alert-warning">Configuration manquante: URL de chargement non définie.</div>';
            return;
        }

        try {
            const response = await fetch(this.urlValue);
            if (!response.ok) throw new Error(`Erreur serveur: ${response.statusText}`);

            const html = await response.text();
            this.listContainerTarget.innerHTML = html;
            this.updateCount();

        } catch (error) {
            console.error(`${this.nomControleur} - Erreur lors du chargement de la collection:`, error);
            this.listContainerTarget.innerHTML = `<div class="alert alert-danger">Impossible de charger la liste: ${error.message}</div>`;
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
    addItem() {
        this.notifyCerveau('app:boite-dialogue:init-request', {
            entity: {}, // Entité vide pour la création
            entityFormCanvas: {
                parametres: {
                    titre_creation: this.createTitleValue,
                    endpoint_form_url: this.urlValue.replace('/list', '/form'), // Hypothèse sur la structure de l'URL
                    endpoint_submit_url: this.urlValue.replace('/list', '/submit'), // Hypothèse
                }
            },
            context: {
                originatorId: this.element.id // On s'identifie pour le rafraîchissement
            }
        });
    }

    /**
     * Déclenche l'ouverture de la boîte de dialogue pour modifier un élément existant.
     * @param {MouseEvent} event
     */
    editItem(event) {
        const itemId = event.currentTarget.dataset.itemId;
        const formUrl = this.urlValue.replace('/list', `/form/`);
        const submitUrl = this.urlValue.replace('/list', '/submit');

        this.notifyCerveau('app:boite-dialogue:init-request', {
            entity: { id: itemId }, // On passe juste l'ID pour l'édition
            entityFormCanvas: {
                parametres: {
                    titre_modification: this.editTitleValue,
                    endpoint_form_url: formUrl,
                    endpoint_submit_url: submitUrl,
                }
            },
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
        const deleteUrl = this.urlValue.replace('/list', `/delete/`);

        this.notifyCerveau('ui:confirmation.request', {
            title: 'Confirmation de suppression',
            body: `Êtes-vous sûr de vouloir supprimer cet élément ?`,
            onConfirm: {
                type: 'app:api.delete-request', // Le cerveau relaiera cette demande
                payload: {
                    url: deleteUrl,
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
