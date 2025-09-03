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
        itemTitleCreate: String,
        itemTitleEdit: String,
        parentFieldName: String,
        defaultValueConfig: String, // AJOUT : pour recevoir la configuration
        disabled: Boolean,
    };

    connect() {
        this.nomControlleur = "Collection - Manager";
        this.componentId = crypto.randomUUID();
        this.loadItemList();

        this.boundHandleRefreshRequest = this.handleRefreshRequest.bind(this);
        document.addEventListener('collection-manager:refresh-list', this.boundHandleRefreshRequest);

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

            // console.log(this.nomControlleur + " - loadItemList", this.listUrlValue, html);

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
        if (this.disabledValue) return; // Sécurité : ne fait rien si désactivé
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
        if (this.disabledValue) return; // Sécurité : ne fait rien si désactivé
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

            this.loadItemList(); // Rafraîchir la liste

            // --- AJOUT : Annonce le succès de la suppression ---
            // this.dispatch('delete:success');
            buildCustomEventForElement(document, "delete:success", true, true, {});
        } catch (error) {
            // TODO: Afficher une notification d'erreur (toast)
            console.error('Delete error:', error);
            // --- AJOUT : Annonce l'échec de la suppression avec le message d'erreur ---
            // this.dispatch('delete:error', { detail: { message: error.message } });
            buildCustomEventForElement(document, 'delete:error', true, true, { message: error.message });
        }
    }


    /**
     * Ouvre la boîte de dialogue principale en lui envoyant les bonnes informations.
     */
    openFormDialog(entity = null) {
        const entityFormCanvas = {
            parametres: {
                titre_creation: this.itemTitleCreateValue,
                titre_modification: this.itemTitleEditValue,
                endpoint_form_url: this.itemFormUrlValue,
                endpoint_submit_url: this.itemSubmitUrlValue,
            }
        };


        const match = this.listUrlValue.match(/\/api\/(\d+)\//);
        const parentId = match ? match[1] : null;

        const context = {
            originatorId: this.componentId
        };
        if (this.parentFieldNameValue) {
            context[this.parentFieldNameValue] = parentId;
        }

        // --- Logique améliorée pour la valeur par défaut ---
        if (this.hasDefaultValueConfigValue && this.defaultValueConfigValue) {
            console.log(this.nomControlleur + "--- Début du débogage de la valeur par défaut ---");
            try {
                const config = JSON.parse(this.defaultValueConfigValue);
                console.log(this.nomControlleur + " - 1. Configuration reçue :", config);

                const parentForm = this.element.closest('form');
                console.log(this.nomControlleur + " - 2. Formulaire parent trouvé :", parentForm);

                if (parentForm) {
                    const sourceSelector = `[name="${config.source}"], [name$="[${config.source}]"]`;
                    console.log(this.nomControlleur + " - 3. Sélecteur utilisé :", sourceSelector);

                    const sourceField = parentForm.querySelector(sourceSelector);
                    console.log(this.nomControlleur + " - 4. Champ source trouvé :", sourceField);

                    if (sourceField && sourceField.value) {
                        let defaultValue = sourceField.value;
                        console.log(this.nomControlleur + " - 5. Valeur brute trouvée :", defaultValue);

                        defaultValue = defaultValue.replace(/\s/g, '').replace(',', '.');
                        console.log(this.nomControlleur + " - 6. Valeur nettoyée :", defaultValue);

                        // On ajoute les informations au contexte
                        context.defaultValue = {
                            target: config.target,
                            value: defaultValue
                        };
                        console.log(this.nomControlleur + " - 7. Contexte enrichi :", context);
                    } else {
                        console.warn(this.nomControlleur + " - 5. Le champ source n'a pas été trouvé ou sa valeur est vide.");
                    }
                }
            } catch (e) {
                console.error("Erreur de config valeur par défaut:", e);
            }
            console.log(this.nomControlleur + "--- Fin du débogage ---");
        }
        // --- FIN DE LA LOGIQUE AMÉLIORÉE ---
        // console.log(this.nomControlleur + " - openFormDialog", entity, entityFormCanvas, context);
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INIT_REQUEST, true, true, {
            entity: entity,
            entityFormCanvas: entityFormCanvas,
            context: context
        });
    }
}