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

        // Écoute l'ordre d'ouverture venant du Cerveau
        document.addEventListener('dialog:search.open-request', this.boundOpenDialog);
    }

    disconnect() {
        document.removeEventListener('dialog:search.open-request', this.boundOpenDialog);
    }

    /**
     * Ouvre la boîte de dialogue et injecte le contenu du formulaire reçu.
     * @param {CustomEvent} event L'événement contenant le HTML du formulaire.
     */
    openDialog(event) {
        const { formHtml } = event.detail;
        this.advancedFormContainerTarget.innerHTML = formHtml;
        this.modal.show();
        // NOUVEAU : Attacher les écouteurs d'événements pour la mise à jour dynamique
        this.addDynamicStyleListeners();
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
                // NOUVEAU : On n'ajoute le critère que si une valeur a été saisie.
                if (value) {
                    criteria[name] = { operator: operatorSelect.value, value: value };
                }
            } else if (currentInputs.length > 1 && currentInputs.some(i => i.dataset.criterionPart)) { // Cas d'une plage de dates
                const from = currentInputs.find(i => i.dataset.criterionPart === 'from')?.value.trim() || '';
                const to = currentInputs.find(i => i.dataset.criterionPart === 'to')?.value.trim() || '';
                // NOUVEAU : On n'ajoute le critère que si au moins une des deux dates est renseignée.
                if (from || to) {
                    criteria[name] = { from, to };
                }
            } else { // Cas simple (texte, options)
                const value = currentInputs[0].value.trim();
                if (value) criteria[name] = value;
            }
        }
    
        // NOUVEAU : On notifie le cerveau avec l'événement standard de soumission de recherche.
        this.notifyCerveau('ui:search.submitted', { criteria });
        this.modal.hide();
    }

    /**
     * Réinitialise le formulaire et notifie le Cerveau.
     */
    resetForm() {
        // Notifie le cerveau qu'il faut soumettre une recherche avec des critères vides.
        this.notifyCerveau('ui:search.submitted', { criteria: {} });
        this.modal.hide();
    }

    /**
     * Ferme simplement la modale sans soumettre.
     */
    cancel() {
        this.modal.hide();
    }

    /**
     * NOUVEAU : Ajoute des écouteurs sur les champs pour mettre à jour le style des blocs.
     */
    addDynamicStyleListeners() {
        const inputs = this.advancedFormContainerTarget.querySelectorAll('[data-criterion-name]');
        inputs.forEach(input => {
            input.addEventListener('input', this.updateCriterionBlockStyle.bind(this));
        });
    }

    /**
     * NOUVEAU : Met à jour la classe 'is-active' du bloc parent d'un champ.
     * @param {Event} event L'événement 'input'
     */
    updateCriterionBlockStyle(event) {
        const input = event.currentTarget;
        const criterionName = input.dataset.criterionName;
        const block = input.closest('.criterion-block');
        if (!block) return;

        let isActive = false;
        const relatedInputs = this.advancedFormContainerTarget.querySelectorAll(`[data-criterion-name="${criterionName}"]`);

        if (relatedInputs.length > 1) { // Cas des plages (date, nombre)
            // Pour une plage, on vérifie si au moins un des champs a une valeur
            isActive = Array.from(relatedInputs).some(relatedInput => {
                // Pour les select, la valeur n'est jamais vide, on ignore l'opérateur
                return relatedInput.tagName !== 'SELECT' && relatedInput.value.trim() !== '';
            });
        } else { // Cas simple (texte, select)
            isActive = input.value.trim() !== '';
        }

        block.classList.toggle('is-active', isActive);
    }
}