// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, EVEN_LISTE_PRINCIPALE_REFRESHED } from './base_controller.js';

export default class extends Controller {
    static targets = ["listBody", "rowCheckbox", "selectAllCheckbox"];

    connect() {
        // EVENT_LISTE_PRINCIPALE_INITIALIZED
        // Informe que la liste est prête pour un premier calcul.
        this.dispatch(EVEN_LISTE_PRINCIPALE_REFRESHED);
    }

    toggleRow() {
        // EVEN_ELEMENT_LISTE_CHECKED
        this.updateSelectAllCheckboxState();
        this.dispatch(EVEN_LISTE_ELEMENT_CHECKED);
    }

    toggleAll(event) {
        const isChecked = event.currentTarget.checked;
        this.rowCheckboxTargets.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        // EVEN_LISTE_PRINCIPALE_ALL_CHECKED
        this.dispatch(EVEN_LISTE_PRINCIPALE_ALL_CHECKED);
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
    
    /**
     * Dispatche un événement customisé sur la fenêtre.
     * @param {string} name Le nom de l'événement (ex: 'initialized')
     */
    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}