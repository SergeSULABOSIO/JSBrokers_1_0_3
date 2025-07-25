// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement, EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_LISTE_PRINCIPALE_REFRESHED, EVEN_SHOW_TOAST } from './base_controller.js';

export default class extends Controller {
    static targets = ["listBody", "rowCheckbox", "selectAllCheckbox"];

    connect() {
        this.nomControleur = "SALES-LIST";
        // EVENT_LISTE_PRINCIPALE_INITIALIZED
        // Informe que la liste est prête pour un premier calcul.
        this.dispatch(EVEN_LISTE_PRINCIPALE_REFRESHED);


        this.element.addEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_REQUEST", this.provideCriteria.bind(this));

        // Écoute l'événement de recherche pour mettre à jour la liste des ventes
        this.element.addEventListener("EVEN_LISTE_PRINCIPALE_SEARCH_REQUEST", this.handleSearch.bind(this));
    }

    disconnect(){
        this.element.removeEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_REQUEST", this.provideCriteria.bind(this));
        this.element.removeEventListener("EVEN_LISTE_PRINCIPALE_SEARCH_REQUEST", this.handleSearch.bind(this));
    }


    /**
     * Fournit la structure des critères à la barre de recherche.
     */
    provideCriteria() {
        console.log(this.nomControleur + " - Request for criteria received. Providing data...");
        const criteriaDefinition = [
            { Nom: 'Nom du matériel', Type: 'Text', Valeur: '', isDefault: true },
            { Nom: 'Nom du client', Type: 'Text', Valeur: '', isDefault: false },
            { Nom: 'Montant facturé', Type: 'Number', Valeur: 0, isDefault: false },
            { Nom: 'Montant payé', Type: 'Number', Valeur: 0, isDefault: false },
            { Nom: 'Solde restant', Type: 'Number', Valeur: 0, isDefault: false },
            // Exemple pour un type 'Options'
            // { Nom: 'Statut', Type: 'Options', Valeur: { 'paid': 'Payé', 'unpaid': 'Impayé' }, isDefault: false }
        ];

        // Émet l'événement de réponse avec les données
        this.dispatch("EVEN_LISTE_PRINCIPALE_CRITERES_DEFINED", {
            bubbles: true,
            cancelable: true,
            detail: criteriaDefinition
        });
    }

    /**
     * Gère une requête de recherche (simple ou avancée).
     */
    handleSearch(event) {
        const { criteria } = event.detail;
        console.log(this.nomControleur + " - New search requested with criteria:", criteria);

        // Ici, vous feriez votre appel AJAX (fetch/Turbo) vers le serveur
        // pour filtrer et rafraîchir la liste des ventes.
        // Exemple:
        // const query = new URLSearchParams(criteria).toString();
        // Turbo.visit(`/ventes?${query}`);
    }
    

    toggleRow() {
        // EVEN_ELEMENT_LISTE_CHECKED
        this.updateSelectAllCheckboxState();
        this.dispatch(EVEN_CHECKBOX_PUBLISH_SELECTION);

        // Déclencher l'événement global pour afficher la notification
        const detail = { text: 'Modifications enregistrées !', type: 'success' };
        this.dispatch(EVEN_SHOW_TOAST, detail);
    }

    toggleAll(event) {
        const isChecked = event.currentTarget.checked;
        this.rowCheckboxTargets.forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        // EVEN_LISTE_PRINCIPALE_ALL_CHECKED
        this.dispatch(EVEN_CHECKBOX_PUBLISH_SELECTION);

        // Déclencher l'événement global pour afficher la notification
        const detail = { text: 'Une erreur est survenue.', type: 'error' };
        this.dispatch(EVEN_SHOW_TOAST, detail);
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