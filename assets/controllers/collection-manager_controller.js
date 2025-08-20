import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_BOITE_DIALOGUE_INIT_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = ["countBadge", "addButtonContainer", "contentPanel", "listContainer"];

    static values = {
        // Les endpoints seront passés depuis Twig via les data-attributes
        listUrl: String,
        itemFormUrl: String,
        itemSubmitUrl: String,
        itemDeleteUrl: String,
    };

    connect() {
        this.nomControlleur = "Collection - Manager";
        // Pour être absolument sûr, ajoutons un log ici
        this.componentId = crypto.randomUUID();
        this.loadItemList();
        this.boundHandleRefreshRequest = this.handleRefreshRequest.bind(this);
        document.addEventListener('collection-manager:refresh-list', this.boundHandleRefreshRequest);

        // --- AJOUT : ÉCOUTER L'ÉVÉNEMENT DE SUPPRESSION CONFIRMÉE ---
        // On crée un nom d'événement unique pour chaque instance du composant
        this.deleteEventName = `collection:${this.componentId}:perform-delete`;
        this.boundPerformDelete = this.performDelete.bind(this);
        document.addEventListener(this.deleteEventName, this.boundPerformDelete);
    }

    disconnect() {
        document.removeEventListener('collection-manager:refresh-list', this.boundHandleRefreshRequest);
        document.removeEventListener(this.deleteEventName, this.boundPerformDelete);
    }

    handleRefreshRequest(event) {
        if (event.detail.originatorId === this.componentId) {
            this.loadItemList();
        }
    }

    /**
     * Charge et affiche la liste des éléments de la collection.
     */
    async loadItemList() {
        if (!this.listUrlValue || this.listUrlValue.endsWith('/0')) {
            this.listContainerTarget.innerHTML = '<div class="text-center p-4 text-muted"><em>La liste des contacts apparaîtra ici après la création de la notification.</em></div>';
            this.updateBadge(0);
            return;
        }

        try {
            const response = await fetch(this.listUrlValue);
            if (!response.ok) throw new Error('Network response was not ok.');
            const html = await response.text();
            this.listContainerTarget.innerHTML = html;
            const itemCount = this.listContainerTarget.querySelectorAll('.collection-item').length;
            this.updateBadge(itemCount);
        } catch (error) {
            console.error('Failed to load item list:', error);
            this.listContainerTarget.innerHTML = '<div class="alert alert-danger">Erreur de chargement de la liste.</div>';
        }
    }

    /**
     * Gère le succès de la boîte de dialogue (ajout/modif d'un item).
     */
    handleDialogSuccess(event) {
        // On vérifie si le formulaire soumis était bien celui d'un item de cette collection
        if (event.detail.submitUrl === this.itemSubmitUrlValue) {
            this.loadItemList(); // On rafraîchit la liste
            this.openAccordion(); // On s'assure que l'accordéon est ouvert [cite: 30]
        }
    }

    // --- Gestion de l'UI de l'accordéon ---

    toggleAccordion(event) {
        // On bascule simplement la présence d'une classe CSS sur le panneau de contenu.
        this.contentPanelTarget.classList.toggle('is-open');

        // On cherche l'icône à l'intérieur de l'élément sur lequel on a cliqué.
        const icon = event.currentTarget.querySelector('.toggle-icon');
        if (icon) {
            // On met à jour l'icône en fonction de la présence de la classe.
            icon.textContent = this.contentPanelTarget.classList.contains('is-open') ? '-' : '+';
        }
    }

    openAccordion() { this.contentPanelTarget.style.display = 'block'; }
    closeAccordion() { this.contentPanelTarget.style.display = 'none'; }


    updateBadge(count) {
        if (count > 0) {
            this.countBadgeTarget.textContent = count;
            this.countBadgeTarget.style.display = 'inline-block';
        } else {
            this.countBadgeTarget.style.display = 'none';
        }
    }

    showAddButton() {
        clearTimeout(this.hideTimeout);
        this.addButtonContainerTarget.style.opacity = '1';
        this.addButtonContainerTarget.style.visibility = 'visible';
    }

    hideAddButton() {
        this.hideTimeout = setTimeout(() => {
            this.addButtonContainerTarget.style.opacity = '0';
            this.addButtonContainerTarget.style.visibility = 'hidden';
        }, 2000); // Reste visible 2 secondes [cite: 19]
    }

    // --- Gestion de l'UI des items de la liste ---

    showItemActions(event) {
        const actions = event.currentTarget.querySelector('.item-actions');
        if (actions) actions.style.opacity = '1';
    }

    hideItemActions(event) {
        // Si l'élément n'est pas sélectionné, on cache les actions
        if (!event.currentTarget.classList.contains('selected')) {
            const actions = event.currentTarget.querySelector('.item-actions');
            if (actions) actions.style.opacity = '0';
        }
    }

    selectItem(event) {
        // Ne pas sélectionner si on clique sur un bouton d'action
        if (event.target.closest('button')) return;

        const currentItem = event.currentTarget;
        const isSelected = currentItem.classList.contains('selected');

        // Déselectionner tous les autres items
        this.listContainerTarget.querySelectorAll('.collection-item.selected').forEach(item => {
            item.classList.remove('selected');
            item.querySelector('.item-actions').style.opacity = '0';
        });

        // Si l'item n'était pas déjà sélectionné, on le sélectionne
        if (!isSelected) {
            currentItem.classList.add('selected');
            currentItem.querySelector('.item-actions').style.opacity = '1';
        }
    }

    // --- Actions CRUD ---

    /**
     * Ouvre la boîte de dialogue pour ajouter un nouvel élément.
     */
    addItem(event) {
        event.stopPropagation();
        this.openFormDialog();
    }

    /**
     * Ouvre la boîte de dialogue pour modifier un élément existant.
     */
    editItem(event) {
        event.stopPropagation();
        const itemId = event.currentTarget.closest('.collection-item').dataset.id;
        this.openFormDialog({ id: itemId });
    }

    /**
     * Demande confirmation et supprime un élément.
     */
    async deleteItem(event) {
        event.stopPropagation();
        const item = event.currentTarget.closest('.collection-item');
        const itemId = item.dataset.id;
        const itemName = item.querySelector('.text-main').textContent;

        buildCustomEventForElement(document, 'confirmation:open-request', true, true, {
            title: '<twig:UX:Icon name="bi:exclamation-triangle-fill" /> Attention',
            body: `Êtes-vous sûr de vouloir supprimer le contact "<strong>${itemName}</strong>" ?<br><small>Cette action est irréversible.</small>`,

            // On prépare l'événement qui devra être déclenché si l'utilisateur confirme
            onConfirm: {
                eventName: this.deleteEventName,
                detail: { itemId: itemId }
            }
        });
    }

    /**
     * NOUVEAU : Cette fonction exécute la suppression APRÈS confirmation.
     */
    async performDelete(event) {
        const { itemId } = event.detail;

        try {
            const response = await fetch(`${this.itemDeleteUrlValue}/${itemId}`, { method: 'DELETE' });
            const result = await response.json();

            if (!response.ok) throw new Error(result.message || 'Erreur de suppression.');

            // TODO: Afficher une notification de succès (toast)
            console.log(result.message);
            this.loadItemList(); // Rafraîchir la liste

        } catch (error) {
            // TODO: Afficher une notification d'erreur (toast)
            console.error('Delete error:', error);
            alert(error.message);
        }
    }


    /**
     * Ouvre la boîte de dialogue principale en lui envoyant les bonnes informations.
     */
    openFormDialog(entity = null) {
        const entityFormCanvas = {
            parametres: {
                titre_creation: "Ajouter un nouveau contact",
                titre_modification: "Modification du contact #%id%",
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
            }
        };

        const match = this.listUrlValue.match(/\/api\/(\d+)\/contacts/);
        const parentId = match ? match[1] : null;

        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            entity: entity,
            entityFormCanvas: entityFormCanvas,
            context: {
                notificationSinistreId: parentId,
                originatorId: this.componentId
            }
        });
    }
}