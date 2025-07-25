// assets/controllers/search-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {
    static targets = [
        "simpleSearchInput",
        "advancedSearchToast",
        "advancedFormContainer",
        "summaryContainer",
        "summary"
    ];

    static values = {
        criteria: Array,
        defaultCriterion: Object
    }

    activeFilters = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        this.toast = new Toast(this.advancedSearchToastTarget);

        // Nouvelle méthode : Lier la fonction de mise à jour de la position pour pouvoir l'ajouter/supprimer
        this.boundUpdateToastPosition = this.updateToastPosition.bind(this);

        // Nouvelle méthode : Nettoyer les écouteurs si le toast est fermé (par ex: par le bouton close)
        this.advancedSearchToastTarget.addEventListener('hide.bs.toast', () => {
            window.removeEventListener('scroll', this.boundUpdateToastPosition);
            window.removeEventListener('resize', this.boundUpdateToastPosition);
        });

        document.addEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_DEFINED", this.handleCriteriaDefined.bind(this));
        this.handleRequestCriteres();
    }

    disconnect() {
        document.removeEventListener("EVEN_LISTE_PRINCIPALE_CRITERES_DEFINED", this.handleCriteriaDefined.bind(this));
        // Nouvelle méthode : S'assurer que les écouteurs sont supprimés si le contrôleur est déconnecté
        window.removeEventListener('scroll', this.boundUpdateToastPosition);
        window.removeEventListener('resize', this.boundUpdateToastPosition);
    }

    // --- Actions de l'utilisateur (logique mise à jour) ---

    openAdvancedSearch() {
        // Nouvelle méthode : Mettre à jour la position avant d'afficher
        this.updateToastPosition();
        this.toast.show();
        // Nouvelle méthode : Écouter le scroll et le redimensionnement pour garder le toast aligné
        window.addEventListener('scroll', this.boundUpdateToastPosition, { passive: true });
        window.addEventListener('resize', this.boundUpdateToastPosition);
    }

    cancelAdvancedSearch() {
        this.toast.hide();
        // Nouvelle méthode : Arrêter d'écouter les événements pour ne pas impacter les performances
        window.removeEventListener('scroll', this.boundUpdateToastPosition);
        window.removeEventListener('resize', this.boundUpdateToastPosition);
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
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

        this.toast.hide(); // Le listener 'hide.bs.toast' s'occupera du nettoyage
        this.dispatchSearchEvent();
    }

    reset() {
        this.simpleSearchInputTarget.value = '';
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
        this.activeFilters = {};
        this.toast.hide(); // Le listener 'hide.bs.toast' s'occupera du nettoyage
        this.dispatchSearchEvent();
    }

    // --- Nouvelle méthode pour la position ---

    /**
     * Calcule et met à jour la position du toast de recherche avancée
     * pour qu'il s'aligne juste en dessous de la barre de recherche.
     */
    updateToastPosition() {
        if (!this.element.isConnected) return;

        const rect = this.element.getBoundingClientRect();
        const toastEl = this.advancedSearchToastTarget;

        toastEl.style.position = 'fixed';
        toastEl.style.top = `${rect.bottom + 5}px`; // 5px de marge en dessous
        toastEl.style.left = `${rect.left}px`;
        toastEl.style.width = `${rect.width}px`;
    }

    // --- Méthodes existantes (inchangées) ---

    handleRequestCriteres() {
        this.dispatch("EVEN_LISTE_PRINCIPALE_CRITERES_REQUEST", { bubbles: true });
    }

    handleCriteriaDefined(event) {
        this.criteriaValue = event.detail;
        const defaultCriterion = this.criteriaValue.find(c => c.isDefault === true);
        if (defaultCriterion) {
            this.defaultCriterionValue = defaultCriterion;
            this.simpleSearchInputTarget.placeholder = defaultCriterion.Nom;
        }
        this.buildAdvancedForm();
    }

    buildAdvancedForm() {
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

    submitSimpleSearch(event) {
        event.preventDefault();

        // Empêche l'erreur si le critère par défaut n'est pas encore chargé
        if (!this.hasDefaultCriterionValue) {
            console.warn("Recherche annulée : le critère par défaut n'est pas encore défini.");
            return;
        }

        const value = this.simpleSearchInputTarget.value.trim();
        const key = this.defaultCriterionValue.Nom;

        if (value) {
            this.activeFilters[key] = value;
        } else {
            delete this.activeFilters[key];
        }

        this.dispatchSearchEvent();
    }

    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;
        delete this.activeFilters[keyToRemove];
        if (keyToRemove === this.defaultCriterionValue.Nom) {
            this.simpleSearchInputTarget.value = '';
        } else {
            const inputToClear = this.advancedFormContainerTarget.querySelector(`[data-criterion-name="${keyToRemove}"]`);
            if (inputToClear) inputToClear.value = '';
        }
        this.dispatchSearchEvent();
    }

    dispatchSearchEvent() {
        this.dispatch("EVEN_LISTE_PRINCIPALE_SEARCH_REQUEST", {
            bubbles: true,
            detail: { criteria: this.activeFilters }
        });
        this.updateSummary();
    }

    updateSummary() {
        const activeCriteria = Object.entries(this.activeFilters);

        if (activeCriteria.length === 0) {
            this.summary.innerHTML = '<span>Recherche simple activée.</span>';
            this.summaryContainerTarget.classList.add('text-muted');
            this.summaryContainerTarget.classList.remove('text-dark', 'fw-bold');
            return;
        }

        this.summaryContainerTarget.classList.remove('text-muted');
        this.summaryContainerTarget.classList.add('text-dark', 'fw-bold');

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

    dispatch(name, detail = {}) {
        buildCustomEventForElement(document, name, true, true, detail);
    }
}
