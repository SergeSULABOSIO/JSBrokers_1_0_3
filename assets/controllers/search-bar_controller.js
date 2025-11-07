// assets/controllers/search-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

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
        
        this.boundhandleCriteriaDefined = this.handleCriteriaDefined.bind(this);
        this.boundUpdateToastPosition = this.updateToastPosition.bind(this);
        this.boundHandleExternalRefresh = this.handleExternalRefresh.bind(this);

        // --- MODIFICATION : Écoute les nouveaux événements ---
        document.addEventListener('ui:search.criteria-provided', this.boundhandleCriteriaDefined);
        document.addEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);

        this.handleRequestCriteres();
    }

    disconnect() {
        // --- MODIFICATION : Nettoie les nouveaux écouteurs ---
        document.removeEventListener('ui:search.criteria-provided', this.boundhandleCriteriaDefined);
        document.removeEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);
    }

    // --- Actions de l'utilisateur (logique mise à jour) ---

    openAdvancedSearch() {
        // Nouvelle méthode : Mettre à jour la position avant d'afficher
        this.updateToastPosition();
        this.toast.show();
        // Nouvelle méthode : Écouter le scroll et le redimensionnement pour garder le toast aligné
        // window.addEventListener('scroll', this.boundUpdateToastPosition, { passive: true });
        // window.addEventListener('resize', this.boundUpdateToastPosition);
    }

    cancelAdvancedSearch() {
        this.toast.hide();
        // Nouvelle méthode : Arrêter d'écouter les événements pour ne pas impacter les performances
        // window.removeEventListener('scroll', this.boundUpdateToastPosition);
        // window.removeEventListener('resize', this.boundUpdateToastPosition);
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
    }

    submitAdvancedSearch(event) {
        event.preventDefault();
        const inputs = this.advancedFormContainerTarget.querySelectorAll('[data-criterion-name]');

        // Réinitialiser activeFilters pour s'assurer que les plages sont bien re-traitées
        this.activeFilters = {};

        // Pour gérer les plages, nous allons regrouper les inputs par leur data-criterion-name
        const groupedInputs = {};
        inputs.forEach(input => {
            const name = input.dataset.criterionName;
            if (!groupedInputs[name]) {
                groupedInputs[name] = [];
            }
            groupedInputs[name].push(input);
        });

        for (const name in groupedInputs) {
            if (groupedInputs.hasOwnProperty(name)) {
                const currentInputs = groupedInputs[name];

                // Cherchons la définition du critère pour déterminer son type
                const criterionDef = this.criteriaValue.find(c => c.Nom === name);

                if (!criterionDef) {
                    console.warn(`Definition for criterion "${name}" not found.`);
                    continue; // Skip if no definition
                }

                if (criterionDef.Type === 'DateTimeRange') {
                    const fromInput = currentInputs.find(input => input.dataset.criterionPart === 'from');
                    const toInput = currentInputs.find(input => input.dataset.criterionPart === 'to');

                    const fromValue = fromInput ? fromInput.value.trim() : '';
                    const toValue = toInput ? toInput.value.trim() : '';

                    if (fromValue || toValue) { // Si au moins une des deux dates est renseignée
                        this.activeFilters[name] = {
                            operator: 'BETWEEN', // L'opérateur est implicite
                            value: { from: fromValue, to: toValue }
                        };
                    } else {
                        delete this.activeFilters[name]; // Si les deux sont vides, supprimer le filtre
                    }
                } else {
                    // Logique existante pour les autres types (Text, Number, Options)
                    const input = currentInputs[0]; // Pour les types non-plage, il n'y a qu'un seul input par nom
                    const value = input.value.trim();

                    if (value) {
                        if (input.type === 'number' || input.type === 'date') {
                            const operatorSelect = this.advancedFormContainerTarget.querySelector(`[data-criterion-operator-for="${name}"]`);
                            this.activeFilters[name] = { operator: operatorSelect.value, value: value };
                        } else {
                            this.activeFilters[name] = value;
                        }
                    } else {
                        delete this.activeFilters[name];
                    }
                }
            }
        }

        this.toast.hide(); // Le listener 'hide.bs.toast' s'occupera du nettoyage
        // this.dispatchSearchEvent();
    }

    reset() {
        this.simpleSearchInputTarget.value = '';
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
        this.activeFilters = {};
        this.toast.hide(); // Le listener 'hide.bs.toast' s'occupera du nettoyage
        // this.dispatchSearchEvent();
    }

    handlePublisheSelection(event) {
        console.log(this.nomControleur + " - handlePublishSelection", event.detail);
        // const { selection } = event.detail; // Récupère les données de l'événement
        // this.dispatchSearchEvent();
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
        this.dispatch('app:search.provide-criteria');
    }

    handleCriteriaDefined(event) {
        this.criteriaValue = event.detail.criteria;
        const defaultCriterion = this.criteriaValue.find(c => c.isDefault === true);
        if (defaultCriterion) {
            this.defaultCriterionValue = defaultCriterion;
            this.simpleSearchInputTarget.placeholder = "Tapez du texte pour filtrer dans l'attribut '" + defaultCriterion.Display + "'.";
        }
        this.buildAdvancedForm();
    }

    buildAdvancedForm() {
        let html = '';
        const advancedCriteria = this.criteriaValue.filter(c => c.isDefault !== true);
        advancedCriteria.forEach(criterion => {
            const criterionId = `criterion_${criterion.Nom.replace(/\s+/g, '_')}`;
            // html += `<div class="mb-3"><label for="${criterionId}" class="form-label">${criterion.Nom}</label>`;
            html += `<div class="mb-3"><label for="${criterionId}" class="form-label">${criterion.Display}</label>`;
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

                // --- NOUVEAU CASE POUR DATETIME RANGE ---
                case 'DateTimeRange':
                    // Obtenir la date du jour au format YYYY-MM-DD
                    // Cela garantit que la date est formatée correctement pour un input type="date"
                    const today = new Date();
                    const year = today.getFullYear();
                    const month = String(today.getMonth() + 1).padStart(2, '0'); // Mois commence à 0
                    const day = String(today.getDate()).padStart(2, '0');
                    const defaultDate = `${year}-${month}-${day}`;

                    // Pour une plage de dates, nous aurons deux champs de date.
                    // L'opérateur sera implicitement "Entre" (BETWEEN) côté backend.
                    html += `<div class="input-group input-group-sm">
                    <span class="input-group-text">Entre</span>
                    <input type="date" 
                           id="${criterionId}_from" 
                           data-criterion-name="${criterion.Nom}" 
                           data-criterion-part="from" 
                           class="form-control form-control-sm"
                           value="${defaultDate}"  
                           placeholder="Date de début">
                    <span class="input-group-text">et</span>
                    <input type="date" 
                           id="${criterionId}_to" 
                           data-criterion-name="${criterion.Nom}" 
                           value="${defaultDate}"  
                           data-criterion-part="to" 
                           class="form-control form-control-sm"
                           placeholder="Date de fin">
                    </div>`;
                    break;
                // -----------------------------------
                case 'Options':
                    html += `<select id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-select form-select-sm">`;
                    html += `<option value="">Tout</option>`;
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
        // this.dispatchSearchEvent();
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
        // this.dispatchSearchEvent();
    }

    /**
     * NOUVELLE MÉTHODE
     * Gère une demande d'actualisation externe.
     * @param {CustomEvent} event L'événement reçu.
     */
    handleExternalRefresh(event) {
        console.log(this.nomControleur + " - Événement de rafraîchissement reçu, relance de la recherche.");
        
        // On réutilise simplement la fonction existante pour lancer la recherche
        // this.dispatchSearchEvent();
    }

    dispatchSearchEvent() {
        this.dispatch('app:base-données:sélection-request', { criteria: this.activeFilters });
        this.updateSummary();
    }

    updateSummary() {
        const activeCriteria = Object.entries(this.activeFilters);

        if (activeCriteria.length === 0) {
            this.summaryTarget.innerHTML = '<span>Recherche simple activée.</span>';
            this.summaryContainerTarget.classList.add('text-muted');
            this.summaryContainerTarget.classList.remove('text-dark', 'fw-bold');
            return;
        }

        this.summaryContainerTarget.classList.remove('text-muted');
        this.summaryContainerTarget.classList.add('text-dark', 'fw-bold');

        let summaryHtml = '';
        activeCriteria.forEach(([key, val]) => {
            let text = '';
            // Récupérer la définition du critère pour son Display name
            const criterionDef = this.criteriaValue.find(c => c.Nom === key);
            const displayName = criterionDef ? criterionDef.Display : key; // Utilise Display ou le Nom si non trouvé

            if (typeof val === 'object' && val.operator === 'BETWEEN' && typeof val.value === 'object' && (val.value.from || val.value.to)) {
                // Cas du DateTimeRange
                let from = val.value.from;
                let to = val.value.to;

                if (from && to) {
                    text = `${displayName}: du ${from} au ${to}`;
                } else if (from) {
                    text = `${displayName}: à partir du ${from}`;
                } else if (to) {
                    text = `${displayName}: jusqu'au ${to}`;
                }
            } else if (typeof val === 'object' && val.operator && val.value) {
                // Cas des nombres ou dates uniques (si vous les gardez)
                text = `${displayName} ${val.operator} ${val.value}`;
            } else {
                // Cas des textes et options
                text = `${displayName}: "${val}"`;
            }

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
        this.summaryTarget.innerHTML = summaryHtml;
    }

    dispatch(name, detail = {}) {
        document.dispatchEvent(new CustomEvent(name, { bubbles: true, detail }));
    }
}
