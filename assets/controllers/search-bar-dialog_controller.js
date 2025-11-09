// assets/controllers/search-bar-dialog_controller.js
import BaseController from './base_controller.js';
import { Modal } from 'bootstrap';

/**
 * @class SearchBarDialogController
 * @extends BaseController
 * @description Gère l'affichage et les interactions de la boîte de dialogue de recherche avancée.
 * Il est entièrement piloté par des événements venant du Cerveau.
 */
export default class extends BaseController {
    static targets = [
        "advancedSearchModal",
        "advancedFormContainer"
    ];

    connect() {
        this.nomControleur = "SEARCH-BAR-DIALOG";
        this.modal = new Modal(this.advancedSearchModalTarget);

        this.boundOpenDialog = this.openDialog.bind(this);
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);

        // Écoute l'ordre d'ouverture venant du Cerveau
        document.addEventListener('dialog:search.open-request', this.boundOpenDialog);
        
        // Écouteur pour la gestion de la superposition (z-index)
        this.advancedSearchModalTarget.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    disconnect() {
        document.removeEventListener('dialog:search.open-request', this.boundOpenDialog);
        this.advancedSearchModalTarget.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    /**
     * Ouvre la boîte de dialogue et injecte le contenu du formulaire reçu.
     * @param {CustomEvent} event L'événement contenant le HTML du formulaire.
     */
    openDialog(event) {
        const { formHtml } = event.detail;
        this.advancedFormContainerTarget.innerHTML = formHtml;
        this.modal.show();
    }

    /**
     * Soumet les critères de recherche avancée au Cerveau.
     * @param {Event} event
     */
    submitAdvancedSearch(event) {
        event.preventDefault();
        const inputs = this.advancedFormContainerTarget.querySelectorAll('[data-criterion-name]');
        const criteria = {};

        inputs.forEach(input => {
            const name = input.dataset.criterionName;
            const value = input.value.trim();

            if (value) {
                if (input.dataset.criterionPart) { // Gère les champs de type plage (from/to)
                    if (!criteria[name]) criteria[name] = {};
                    criteria[name][input.dataset.criterionPart] = value;
                } else if (input.dataset.criterionOperatorFor) { // Gère les champs avec opérateur (ex: Number)
                    const operatorSelect = this.advancedFormContainerTarget.querySelector(`[data-criterion-operator-for="${name}"]`);
                    criteria[name] = { operator: operatorSelect.value, value: value };
                } else {
                    criteria[name] = value;
                }
            }
        });

        this.notifyCerveau('search:advanced.submitted', { criteria });
        this.modal.hide();
    }

    /**
     * Réinitialise le formulaire et notifie le Cerveau.
     */
    resetForm() {
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
        this.notifyCerveau('search:advanced.reset');
        this.modal.hide();
    }

    /**
     * Ferme simplement la modale sans soumettre.
     */
    cancel() {
        this.modal.hide();
    }

    /**
     * Ajuste le z-index pour s'assurer que cette modale apparaît au-dessus des autres.
     */
    adjustZIndex() {
        const backdrops = document.querySelectorAll('.modal-backdrop.show');
        if (backdrops.length > 1) {
            const modals = document.querySelectorAll('.modal.show');
            let maxZIndex = 0;
            modals.forEach(modal => {
                if (modal !== this.advancedSearchModalTarget) {
                    const zIndex = parseInt(window.getComputedStyle(modal).zIndex) || 1055;
                    if (zIndex > maxZIndex) {
                        maxZIndex = zIndex;
                    }
                }
            });

            const myModal = this.advancedSearchModalTarget;
            const myBackdrop = backdrops[backdrops.length - 1];

            myBackdrop.style.zIndex = maxZIndex + 1;
            myModal.style.zIndex = maxZIndex + 2;
        }
    }
}