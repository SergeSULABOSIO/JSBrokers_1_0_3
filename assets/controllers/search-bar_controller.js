// assets/controllers/search-bar_controller.js
import BaseController from './base_controller.js';

export default class extends BaseController {
    static targets = [
        "simpleSearchInput",
        "simpleSearchCriterion", // NOUVEAU
        "summaryContainer", 
        "summary"
    ];

    static values = {
        criteria: Array,
        defaultCriterion: Object,
        nomEntite: String // NOUVEAU : pour recevoir le nom de l'entité
    }

    // La barre de recherche devient "stateless". Elle ne stocke que l'état actuel reçu du cerveau pour le rendu.
    currentCriteria = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        this.boundHandleContextChanged = this.handleContextChanged.bind(this);

        document.addEventListener('app:context.changed', this.boundHandleContextChanged);

        // L'initialisation est minimale. Le rendu se fait via handleContextChanged.
        this.populateSimpleSearchSelector();
        this.updateSimpleSearchPlaceholder();
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    openAdvancedSearch() {
        // On construit le formulaire avancé en se basant sur les critères actuels pour le pré-remplissage.
        const formHtml = this.buildAdvancedForm(this.currentCriteria);
        this.notifyCerveau('dialog:search.open-request', { formHtml });
    }

    /**
     * NOUVEAU : Point d'entrée unique pour la mise à jour de l'UI de la barre de recherche.
     * Est appelé à chaque fois que le contexte de l'application (et donc les filtres) change.
     * @param {CustomEvent} event 
     */
    handleContextChanged(event) {
        const { searchCriteria, isTabSwitch } = event.detail;

        // On met à jour notre copie locale des critères
        this.currentCriteria = searchCriteria || {};

        // On met à jour l'UI pour refléter l'état reçu du cerveau.
        // Si c'est un changement d'onglet, on s'assure que le critère simple est bien synchronisé.
        if (isTabSwitch) {
            const simpleSearchKey = this.simpleSearchCriterionTarget.value;
            if (this.currentCriteria[simpleSearchKey] && this.currentCriteria[simpleSearchKey].value) {
                this.simpleSearchInputTarget.value = this.currentCriteria[simpleSearchKey].value;
            } else {
                this.simpleSearchInputTarget.value = '';
            }
        }
        this.updateSummary(this.currentCriteria);
    }

    /**
     * NOUVEAU : Remplit le sélecteur de recherche simple avec les critères de type 'Text'.
     */
    populateSimpleSearchSelector() {
        const textCriteria = this.criteriaValue.filter(c => c.Type === 'Text');
        this.simpleSearchCriterionTarget.innerHTML = ''; // On vide le sélecteur

        textCriteria.forEach(criterion => {
            const option = document.createElement('option');
            option.value = criterion.Nom;
            option.textContent = criterion.Display;
            this.simpleSearchCriterionTarget.appendChild(option);
        });
    }

    /**
     * NOUVEAU : Met à jour le placeholder du champ de recherche simple.
     */
    updateSimpleSearchPlaceholder() {
        const selectedOption = this.simpleSearchCriterionTarget.options[this.simpleSearchCriterionTarget.selectedIndex];
        if (selectedOption) {
            this.simpleSearchInputTarget.placeholder = `Rechercher dans "${selectedOption.textContent}"...`;
        }
    }

    buildAdvancedForm(activeFilters) {
        let html = '';
        const advancedCriteria = this.criteriaValue.filter(c => !c.isDefault); // [c.isDefault !== true]
        advancedCriteria.forEach(criterion => {            
            const criterionId = `criterion_${criterion.Nom.replace(/\s+/g, '_')}`;
            const activeClass = this.isCriterionActive(criterion, activeFilters) ? 'is-active' : '';

            // NOUVEAU : Début du bloc visuel pour un critère
            html += `<div class="criterion-block ${activeClass}">`;
            html += `<div class="criterion-header">${criterion.Display}</div>`;
            html += `<div class="p-3">`; // Conteneur pour le champ de formulaire
            switch (criterion.Type) {
                case 'Text':
                    // CORRECTION : Gérer la structure objet {value: '...'} pour les filtres texte.
                    const textFilter = activeFilters[criterion.Nom];
                    const textValue = (typeof textFilter === 'object' && textFilter !== null) ? textFilter.value : (textFilter || '');
                    html += `<input type="text" id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-control form-control-sm" value="${textValue}" placeholder="Saisir ${criterion.Display.toLowerCase()}...">`;
                    break;
                case 'Number':
                    // On s'assure que activeFilters[criterion.Nom] est un objet
                    const numberFilter = (typeof activeFilters[criterion.Nom] === 'object' && activeFilters[criterion.Nom] !== null)
                        ? activeFilters[criterion.Nom]
                        : {};
                    const numberValue = numberFilter.value !== undefined ? numberFilter.value : ''; // Gère le cas où la valeur est 0
                    const numberOperator = numberFilter.operator || '=';
                    html += `<div class="input-group input-group-sm">
                                <select class="form-select" style="max-width: 150px; min-width: 150px;" data-criterion-operator-for="${criterion.Nom}">
                                    <option value="=" ${numberOperator === '=' ? 'selected' : ''}>Égal à</option>
                                    <option value="!=" ${numberOperator === '!=' ? 'selected' : ''}>Différent de</option>
                                    <option value=">" ${numberOperator === '>' ? 'selected' : ''}>Sup à</option>
                                    <option value=">=" ${numberOperator === '>=' ? 'selected' : ''}>Sup ou égal à</option>
                                    <option value="<" ${numberOperator === '<' ? 'selected' : ''}>Inf à</option>
                                    <option value="<=" ${numberOperator === '<=' ? 'selected' : ''}>Inf ou égal à</option>
                                </select>
                                <input type="number" id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-control form-control-sm" value="${numberValue}" placeholder="Saisir une valeur...">
                            </div>`;
                    break;

                // --- NOUVEAU CASE POUR DATETIME RANGE ---
                case 'DateTimeRange':
                    const dateFilter = (typeof activeFilters[criterion.Nom] === 'object' && activeFilters[criterion.Nom] !== null)
                        ? activeFilters[criterion.Nom]
                        : {};
                    const fromValue = dateFilter.from || ''; // Devrait déjà être défini si nécessaire
                    const toValue = dateFilter.to || '';     // Devrait déjà être défini si nécessaire

                    html += `<div class="input-group input-group-sm">
                    <span class="input-group-text">Entre</span>
                    <input type="date" 
                           id="${criterionId}_from" 
                           data-criterion-name="${criterion.Nom}" 
                           data-criterion-part="from" 
                           class="form-control form-control-sm"
                           value="${fromValue}"
                           placeholder="Date de début">
                    <span class="input-group-text">et</span>
                    <input type="date" 
                           id="${criterionId}_to" 
                           data-criterion-name="${criterion.Nom}" 
                           value="${toValue}"
                           data-criterion-part="to" 
                           class="form-control form-control-sm"
                           placeholder="Date de fin">
                    </div>`;
                    break;
                // -----------------------------------
                case 'Options':
                    const optionFilter = activeFilters[criterion.Nom];
                    const optionValue = (typeof optionFilter === 'object' && optionFilter !== null) ? optionFilter.value : (optionFilter || '');
                    html += `<select id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-select form-select-sm">`;
                    html += `<option value="">Tout</option>`;
                    for (const [key, value] of Object.entries(criterion.Valeur)) {
                        const isSelected = String(key) === String(optionValue) ? 'selected' : '';
                        html += `<option value="${key}" title="${value}" ${isSelected}>${value}</option>`;
                    }
                    html += `</select>`;
                    break;
            }

            html += `</div>`; // Fin du conteneur de champ
            html += `</div>`; // Fin du bloc de critère
        });
        return html;
    }

    /**
     * NOUVEAU : Vérifie si un critère a une valeur active dans les filtres.
     * @param {object} criterion La définition du critère.
     * @returns {boolean}
     */
    isCriterionActive(criterion, activeFilters) {
        const filter = activeFilters[criterion.Nom];
        if (filter === undefined || filter === null) return false;

        switch (criterion.Type) {
            case 'Text':
            case 'Options':
                // CORRECTION : Gérer la structure objet {value: '...'}
                const value = (typeof filter === 'object' && filter !== null) ? filter.value : filter;
                return value !== '' && value !== undefined;
            case 'Number':
                // Actif si une valeur est saisie, même 0.
                return filter.value !== undefined && filter.value !== '';
            case 'DateTimeRange':
                // Actif si au moins une des deux dates est renseignée.
                return (filter.from && filter.from !== '') || (filter.to && filter.to !== '');
            default:
                return false;
        }
    }   

    /**
     * NOUVEAU : Réinitialise tous les filtres et notifie le cerveau.
     */
    resetAllFilters() {
        this.simpleSearchInputTarget.value = '';
        this.notifyCerveau('ui:search.submitted', { criteria: {} });
    }

    submitSimpleSearch(event) {
        event.preventDefault();

        const inputValue = this.simpleSearchInputTarget.value.trim();
        const criterionName = this.simpleSearchCriterionTarget.value;
        const criterionDef = this.criteriaValue.find(c => c.Nom === criterionName);

        if (!criterionDef) return;

        // On part d'une copie des filtres actuels pour ne pas écraser les filtres avancés
        const newCriteria = { ...this.currentCriteria };

        if (inputValue) {
            // On construit le filtre avec la structure attendue par le backend
            const filter = {
                operator: 'LIKE',
                value: inputValue,
                // On ajoute le targetField si c'est une relation
                ...(criterionDef.targetField && { targetField: criterionDef.targetField })
            };
            newCriteria[criterionName] = filter;
        } else {
            delete newCriteria[criterionName];
        }
        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria });
    }

    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;
        
        const newCriteria = { ...this.currentCriteria };
        delete newCriteria[keyToRemove];

        this.notifyCerveau('ui:search.submitted', { criteria: newCriteria });
    }

    updateSummary(criteria) {
        const activeCriteria = Object.entries(criteria);

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

            if (typeof val === 'object' && (val.from || val.to) && criterionDef.Type === 'DateTimeRange') {
                // Cas spécifique pour DateTimeRange
                let from = val.from;
                let to = val.to;

                if (from && to) {
                    text = `${displayName}: du ${from} au ${to}`;
                } else if (from) {
                    text = `${displayName}: à partir du ${from}`;
                } else if (to) {
                    text = `${displayName}: jusqu'au ${to}`;
                }
            } else if (typeof val === 'object' && val.operator) {
                // Cas des nombres
                text = `${displayName} ${val.operator} ${val.value}`;
            } else {
                // CORRECTION : Gérer la structure objet {value: '...'} pour les textes et options
                const displayValue = (typeof val === 'object' && val !== null) ? val.value : val;
                text = `${displayName}: "${displayValue}"`;
            }

            summaryHtml += `
            <span class="badge text-bg-secondary me-1 mb-1 d-inline-flex align-items-center">
                ${text}
                <button type="button" 
                    class="btn-close btn-close-white ms-2"
                    style="font-size: 0.9em;"
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
}
