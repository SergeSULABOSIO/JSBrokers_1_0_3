// assets/controllers/search-bar_controller.js
import BaseController from './base_controller.js';
import { Modal } from 'bootstrap';

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

    activeFilters = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        this.boundHandleExternalRefresh = this.handleExternalRefresh.bind(this);
        this.boundHandleAdvancedSearchData = this.handleAdvancedSearchData.bind(this);
        this.boundHandleAdvancedSearchReset = this.handleAdvancedSearchReset.bind(this);

        document.addEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);
        document.addEventListener('search:advanced.submitted', this.boundHandleAdvancedSearchData);
        document.addEventListener('search:advanced.reset', this.boundHandleAdvancedSearchReset);

        this.initializeCriteria(); // NOUVEAU : On initialise les critères directement
    }

    disconnect() {
        document.removeEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);
        document.removeEventListener('search:advanced.submitted', this.boundHandleAdvancedSearchData);
        document.removeEventListener('search:advanced.reset', this.boundHandleAdvancedSearchReset);
    }

    // --- Actions de l'utilisateur (logique mise à jour) ---

    openAdvancedSearch() {
        // MODIFIÉ : Pré-remplir TOUS les filtres DateTimeRange non actifs avec le mois en cours.
        const dateCriteria = this.criteriaValue.filter(c => c.Type === 'DateTimeRange');
        dateCriteria.forEach(dateCriterion => {
            if (dateCriterion && !this.activeFilters[dateCriterion.Nom]) {
                const today = new Date();
                const year = today.getFullYear();
                const month = today.getMonth();
                
                const firstDay = new Date(Date.UTC(year, month, 1)).toISOString().split('T')[0];
                const lastDay = new Date(Date.UTC(year, month + 1, 0)).toISOString().split('T')[0];
                this.activeFilters[dateCriterion.Nom] = { from: firstDay, to: lastDay };
            }
        });

        const formHtml = this.buildAdvancedForm();
        this.notifyCerveau('dialog:search.open-request', { formHtml });
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

        this.modal.hide();
        this.dispatchSearchEvent(); // Décommenté
    }

    /**
     * Gère la réception des critères depuis la boîte de dialogue.
     * @param {CustomEvent} event 
     */
    handleAdvancedSearchData(event) {
        const { criteria } = event.detail;
        
        // On fusionne les nouveaux critères avancés avec les filtres simples existants
        const simpleFilterKey = this.defaultCriterionValue.Nom;
        const simpleFilterValue = this.activeFilters[simpleFilterKey];

        this.activeFilters = {}; // On réinitialise
        if (simpleFilterValue) {
            this.activeFilters[simpleFilterKey] = simpleFilterValue;
        }

        // On ajoute les critères avancés
        for (const [key, value] of Object.entries(criteria)) {
            this.activeFilters[key] = value;
        }

        this.dispatchSearchEvent();
    }

    handleAdvancedSearchReset() {
        // Vide la recherche simple
        this.simpleSearchInputTarget.value = '';
        // Réinitialise complètement les filtres
        this.activeFilters = {};

        // NOUVELLE LOGIQUE : Applique le filtre de date par défaut (mois en cours)
        const dateCriterion = this.criteriaValue.find(c => c.Type === 'DateTimeRange');
        if (dateCriterion) {
            const today = new Date();
            const year = today.getFullYear();
            const month = today.getMonth();
            
            const firstDay = new Date(Date.UTC(year, month, 1)).toISOString().split('T')[0];
            const lastDay = new Date(Date.UTC(year, month + 1, 0)).toISOString().split('T')[0];
            // const firstDay = new Date(year, month, 1).toISOString().split('T')[0];
            // const lastDay = new Date(year, month + 1, 0).toISOString().split('T')[0];

            this.activeFilters[dateCriterion.Nom] = { from: firstDay, to: lastDay };
        }

        // Lance la recherche avec les filtres réinitialisés (contenant uniquement la date)
        this.dispatchSearchEvent();
    }




    /**
     * NOUVEAU : Fusion de la logique de search-criteria_controller.
     * Définit les critères de recherche en fonction du nom de l'entité
     * et initialise la barre de recherche.
     */
    initializeCriteria() {
        // NOUVELLE LOGIQUE : Les critères sont maintenant passés directement par le serveur
        // via la `value` Stimulus `criteria`. Il n'y a plus besoin de les construire ici.
        console.log(`${this.nomControleur} - Initializing with criteria from server:`, this.criteriaValue);

        // On s'assure que la valeur a bien été chargée.
        if (!this.hasCriteriaValue || this.criteriaValue.length === 0) {
            console.warn("Aucune directive de recherche (searchCanvas) n'a été fournie par le serveur.");
            return;
        }

        // Le reste de la logique reste identique.
        // NOUVEAU : On peuple le sélecteur de critère simple
        this.populateSimpleSearchSelector();
        this.updateSimpleSearchPlaceholder();
        this.buildAdvancedForm();
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

    buildAdvancedForm() {
        let html = '';
        const advancedCriteria = this.criteriaValue.filter(c => !c.isDefault); // [c.isDefault !== true]
        advancedCriteria.forEach(criterion => {            
            const criterionId = `criterion_${criterion.Nom.replace(/\s+/g, '_')}`;
            const activeClass = this.isCriterionActive(criterion) ? 'is-active' : '';

            // NOUVEAU : Début du bloc visuel pour un critère
            html += `<div class="criterion-block ${activeClass}">`;
            html += `<div class="criterion-header">${criterion.Display}</div>`;
            html += `<div class="p-3">`; // Conteneur pour le champ de formulaire
            switch (criterion.Type) {
                case 'Text':
                    const textValue = this.activeFilters[criterion.Nom] || '';
                    html += `<input type="text" id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-control form-control-sm" value="${textValue}" placeholder="Saisir ${criterion.Display.toLowerCase()}...">`;
                    break;
                case 'Number':
                    // On s'assure que activeFilters[criterion.Nom] est un objet
                    const numberFilter = (typeof this.activeFilters[criterion.Nom] === 'object' && this.activeFilters[criterion.Nom] !== null)
                        ? this.activeFilters[criterion.Nom]
                        : {};
                    const numberValue = numberFilter.value || '';
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
                    // Les valeurs par défaut sont maintenant définies dans openAdvancedSearch ou handleAdvancedSearchReset
                    // si aucun filtre n'est actif pour ce critère.
                    // Nous récupérons donc simplement les valeurs de activeFilters.
                    const dateFilter = (typeof this.activeFilters[criterion.Nom] === 'object' && this.activeFilters[criterion.Nom] !== null)
                        ? this.activeFilters[criterion.Nom]
                        : {};
                    const fromValue = dateFilter.from || ''; // Devrait déjà être défini si nécessaire
                    const toValue = dateFilter.to || '';     // Devrait déjà être défini si nécessaire

                    // Pour une plage de dates, nous aurons deux champs de date.
                    // L'opérateur sera implicitement "Entre" (BETWEEN) côté backend.
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
                    const optionValue = this.activeFilters[criterion.Nom] || '';
                    html += `<select id="${criterionId}" data-criterion-name="${criterion.Nom}" class="form-select form-select-sm">`;
                    html += `<option value="">Tout</option>`;
                    for (const [key, value] of Object.entries(criterion.Valeur)) {
                        const isSelected = key === optionValue ? 'selected' : '';
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
    isCriterionActive(criterion) {
        const filter = this.activeFilters[criterion.Nom];
        if (filter === undefined || filter === null) return false;

        switch (criterion.Type) {
            case 'Text':
            case 'Options':
                return filter !== '';
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

    submitSimpleSearch(event) {
        event.preventDefault();

        const value = this.simpleSearchInputTarget.value.trim();
        const key = this.simpleSearchCriterionTarget.value; // NOUVEAU : On utilise le critère sélectionné

        if (value) {
            this.activeFilters[key] = value;
        } else {
            delete this.activeFilters[key];
        }
        this.dispatchSearchEvent(); // Décommenté
    }

    removeFilter(event) {
        const keyToRemove = event.currentTarget.dataset.filterKey;
        delete this.activeFilters[keyToRemove]; 
        if (keyToRemove === this.simpleSearchCriterionTarget.value) {
            this.simpleSearchInputTarget.value = '';
        } else {
            // Plus besoin de nettoyer le formulaire ici, car il est dans un autre contrôleur.
        }
        this.dispatchSearchEvent();
    }

    /**
     * NOUVELLE MÉTHODE
     * Gère une demande d'actualisation externe.
     * @param {CustomEvent} event L'événement reçu.
     */
    handleExternalRefresh(event) {
        console.log(this.nomControleur + " - Événement de rafraîchissement reçu, relance de la recherche.");

        this.dispatchSearchEvent();
    }

    dispatchSearchEvent() {
        // Notifie le cerveau pour démarrer la barre de progression
        this.notifyCerveau('app:loading.start');
        // Notifie le cerveau pour lancer la recherche
        this.notifyCerveau('app:base-données:sélection-request', { criteria: this.activeFilters });
        // Met à jour le résumé des filtres actifs
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
                // Cas des textes et options
                text = `${displayName}: "${val}"`;
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
