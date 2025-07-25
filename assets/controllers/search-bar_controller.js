// assets/controllers/search-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {
    static targets = [
        "simpleSearchInput",
        "advancedSearchToast",
        "advancedFormContainer",
        "summaryContainer", // Le conteneur de la ligne 2
        "summary" // La div qui contiendra les badges
    ];

    static values = {
        criteria: Array,
        defaultCriterion: Object
    }

    // Objet pour stocker l'état actuel des filtres actifs
    activeFilters = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        this.toast = new Toast(this.advancedSearchToastTarget);
        document.addEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_DEFINED", this.handleCriteriaDefined.bind(this));
        this.handleRequestCriteres();
    }

    disconnect(){
        document.removeEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_DEFINED", this.handleCriteriaDefined.bind(this));
    }

    handleRequestCriteres() {
        console.log(this.nomControleur + " - handleRequestCriteres");
        this.dispatch("EVEN_LISTE_PRINCIPALE_CRITERES_REQUEST", { bubbles: true });
    }

    handleCriteriaDefined(event) {
        this.criteriaValue = event.detail;
        console.log(this.nomControleur + " - handleCriteriaDefined", event.detail);
        const defaultCriterion = this.criteriaValue.find(c => c.isDefault === true);
        if (defaultCriterion) {
            this.defaultCriterionValue = defaultCriterion;
            this.simpleSearchInputTarget.placeholder = defaultCriterion.Nom;
        }
        this.buildAdvancedForm();
    }

    buildAdvancedForm() {
        // Le code de cette fonction reste identique à la version précédente.
        // ... (voir le code dans la réponse précédente si besoin)
        let html = '';
        const advancedCriteria = this.criteriaValue.filter(c => c.isDefault !== true);
        advancedCriteria.forEach(criterion => {
            const criterionId = `criterion_${criterion.Nom.replace(/\s+/g, '_')}`;
            html += `<div class="mb-3"><label for="${criterionId}" class="form-label">${criterion.Nom}</label>`;
            switch (criterion.Type) {
                case 'Text':
                    html += `<input type="text" id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-control form-control-sm">`;
                    break;
                case 'Number':
                    html += `<div class="input-group input-group-sm">
                                <select class="form-select" style="max-width: 150px; min-width: 150px;" data-criterion-operator-for="${criterion.Nom}">
                                    <option value="=">Égal à</option>
                                    <option value="!=">Différent de</option>
                                    <option value=">">Sup à</option>
                                    <option value=">=">Sup ou égal à</option>
                                    <option value="<">Inf à</option>
                                    <option value="<=">Inf ou égal à</option>
                                </select>
                                <input type="number" id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-control form-control-sm">
                            </div>`;
                    break;
                case 'Options':
                    html += `<select id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-select form-select-sm">`;
                    html += `<option value="">Toutes</option>`;
                    for (const [key, value] of Object.entries(criterion.Valeur)) {
                         html += `<option value="${key}" title="${value}">${value}</option>`;
                    }
                    html += `</select>`;
                    break;
            }
            html += `</div>`;
        });
        this.advancedFormContainerTarget.innerHTML = html;
    }

    // --- Actions de l'utilisateur (logique mise à jour) ---

    openAdvancedSearch() {
        this.toast.show();
    }

    cancelAdvancedSearch() {
        this.toast.hide();
        // Optionnel : vider les champs avancés lors de l'annulation
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
    }

    submitSimpleSearch(event) {
        event.preventDefault();
        const value = this.simpleSearchInputTarget.value.trim();
        const key = this.defaultCriterionValue.Nom;

        if (value) {
            this.activeFilters[key] = value;
        } else {
            delete this.activeFilters[key];
        }

        this.dispatchSearchEvent();
    }

    submitAdvancedSearch(event) {
        event.preventDefault();
        const inputs = this.advancedFormContainerTarget.querySelectorAll('[data-criterion-name]');

        inputs.forEach(input => {
            const name = input.dataset.criterionName;
            const value = input.value.trim();

            if (value) {
                if (input.type === 'number') {
                    const operatorSelect = this.advancedFormContainerTarget.querySelector(`[data-criterion-operator-for="${name}"]`);
                    this.activeFilters[name] = { operator: operatorSelect.value, value: value };
                } else {
                    this.activeFilters[name] = value;
                }
            } else {
                delete this.activeFilters[name];
            }
        });

        this.toast.hide();
        this.dispatchSearchEvent();
    }

    /**
     * NOUVELLE MÉTHODE: Supprime un filtre individuel depuis un badge.
     */
    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;

        // Supprime le filtre de notre objet d'état
        delete this.activeFilters[keyToRemove];

        // Vide le champ correspondant dans le formulaire
        if (keyToRemove === this.defaultCriterionValue.Nom) {
            this.simpleSearchInputTarget.value = '';
        } else {
            const inputToClear = this.advancedFormContainerTarget.querySelector(`[data-criterion-name="${keyToRemove}"]`);
            if (inputToClear) inputToClear.value = '';
        }

        // Relance la recherche avec les filtres restants
        this.dispatchSearchEvent();
    }

    reset() {
        // Vide tous les champs
        this.simpleSearchInputTarget.value = '';
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');

        // Réinitialise l'état et lance une recherche vide
        this.activeFilters = {};
        this.toast.hide();
        this.dispatchSearchEvent();
    }

    // --- Méthodes utilitaires (logique mise à jour) ---

    /**
     * Émet l'événement avec l'état actuel des filtres.
     */
    dispatchSearchEvent() {
        this.dispatch("EVEN_LISTE_PRINCIPALE_SEARCH_REQUEST", {
            bubbles: true,
            detail: { criteria: this.activeFilters }
        });
        // Met à jour l'UI après chaque action
        this.updateSummary();
    }

    /**
     * Met à jour la ligne de résumé avec des badges interactifs.
     */
    updateSummary() {
        const activeCriteria = Object.entries(this.activeFilters);

        if (activeCriteria.length === 0) {
            this.summary.innerHTML = '<span>Recherche simple activée.</span>';
            this.summaryContainerTarget.classList.add('text-muted');
            this.summaryContainerTarget.classList.remove('text-dark', 'fw-bold');
            this.summaryContainerTarget.querySelector('i').className = 'bi-info-circle me-2 flex-shrink-0'; // icone info
            return;
        }

        this.summaryContainerTarget.classList.remove('text-muted');
        this.summaryContainerTarget.classList.add('text-dark', 'fw-bold');
        this.summaryContainerTarget.querySelector('i').className = 'bi-filter me-2 flex-shrink-0'; // icone filtre

        let summaryHtml = '';
        activeCriteria.forEach(([key, val]) => {
            let text = typeof val === 'object' ? `${key} ${val.operator} ${val.value}` : `${key}: "${val}"`;
            summaryHtml += `
                <span class="badge text-bg-secondary me-1 mb-1 d-inline-flex align-items-center">
                    ${text}
                    <button type="button" 
                            class="btn-close btn-close-white ms-2"
                            style="font-size: 0.6em;"
                            aria-label="Remove filter" 
                            data-action="click->search-bar#removeFilter"
                            data-filter-key="${key}">
                    </button>
                </span>
            `;
        });
        this.summary.innerHTML = summaryHtml;
    }

    /**
     * Dispatche un événement customisé sur la fenêtre.
     * @param {string} name Le nom de l'événement (ex: 'initialized')
     */
    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}