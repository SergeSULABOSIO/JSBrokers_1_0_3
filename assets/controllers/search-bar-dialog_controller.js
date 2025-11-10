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
    
        // Regrouper les inputs par nom de critère pour gérer les cas complexes (plages, opérateurs)
        const groupedInputs = {};
        inputs.forEach(input => {
            const name = input.dataset.criterionName;
            if (!groupedInputs[name]) {
                groupedInputs[name] = [];
            }
            groupedInputs[name].push(input);
        });
    
        for (const name in groupedInputs) {
            const currentInputs = groupedInputs[name];
            const operatorSelect = this.advancedFormContainerTarget.querySelector(`[data-criterion-operator-for="${name}"]`);
    
            if (operatorSelect) { // Cas d'un critère numérique avec opérateur
                const valueInput = currentInputs.find(i => i.type === 'number');
                const value = valueInput ? valueInput.value.trim() : '';
                criteria[name] = { operator: operatorSelect.value, value: value };
            } else if (currentInputs.length > 1 && currentInputs.some(i => i.dataset.criterionPart)) { // Cas d'une plage de dates
                const from = currentInputs.find(i => i.dataset.criterionPart === 'from')?.value.trim() || '';
                const to = currentInputs.find(i => i.dataset.criterionPart === 'to')?.value.trim() || '';
                criteria[name] = { from, to };
            } else { // Cas simple (texte, options)
                const value = currentInputs[0].value.trim();
                if (value) criteria[name] = value;
            }
        }
    
        this.notifyCerveau('search:advanced.submitted', { criteria });
        this.modal.hide();
    }

    /**
     * Réinitialise le formulaire et notifie le Cerveau.
     */
    resetForm() {
        // Ne fait plus de manipulation du DOM ici.
        // Notifie simplement le cerveau de l'intention de réinitialiser.
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