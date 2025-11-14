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

        // Charger les filtres depuis sessionStorage au démarrage.
        const storageKey = `lastSearchCriteria_${this.nomEntiteValue}`;
        const savedFilters = sessionStorage.getItem(storageKey);

        if (savedFilters) {
            this.activeFilters = JSON.parse(savedFilters);
            console.log(`${this.nomControleur} - Filtres chargés depuis sessionStorage:`, this.activeFilters);
        }

        this.initializeCriteria();

        // La recherche initiale n'est plus déclenchée au rechargement.
        // La liste affichera son contenu par défaut, et la barre de recherche
        // affichera les filtres précédemment utilisés.
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

    /**
     * Gère la réception des critères depuis la boîte de dialogue.
     * @param {CustomEvent} event 
     */
    handleAdvancedSearchData(event) {
        const advancedCriteria = event.detail.criteria;

        // On récupère le filtre simple s'il existe
        const simpleSearchKey = this.simpleSearchCriterionTarget.value;
        const simpleFilter = this.activeFilters[simpleSearchKey];

        // On écrase les filtres avec les nouveaux critères avancés
        this.activeFilters = advancedCriteria;

        // On ré-applique le filtre simple s'il existait, pour ne pas le perdre
        if (simpleFilter) {
            this.activeFilters[simpleSearchKey] = simpleFilter;
        }

        this.dispatchSearchEvent();
    }

    handleAdvancedSearchReset() {
        this.simpleSearchInputTarget.value = '';
        // Réinitialise complètement les filtres
        this.activeFilters = {};
        // La logique de pré-remplissage des dates est maintenant gérée uniquement
        // à l'ouverture du dialogue de recherche avancée.
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

        // CORRECTION : Logique de synchronisation de l'UI avec les filtres restaurés.
        // On parcourt les filtres actifs pour voir si l'un d'eux est un critère simple.
        const simpleCriteriaNames = this.criteriaValue.filter(c => c.Type === 'Text').map(c => c.Nom);
        
        for (const filterKey in this.activeFilters) {
            if (simpleCriteriaNames.includes(filterKey)) {
                const filterValue = this.activeFilters[filterKey];
                // On a trouvé un filtre simple actif. On met à jour l'UI.
                console.log(`${this.nomControleur} - Restauration de l'UI pour le critère simple :`, { key: filterKey, value: filterValue.value });
                this.simpleSearchCriterionTarget.value = filterKey; // Sélectionne le bon critère dans le <select>
                this.simpleSearchInputTarget.value = filterValue.value; // Remplit le champ <input>
                this.updateSimpleSearchPlaceholder(); // Met à jour le placeholder pour correspondre
                break; // On a trouvé notre critère simple, on peut arrêter la boucle.
            }
        }

        this.updateSummary(); // Mettre à jour le résumé des filtres

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
                    // CORRECTION : Gérer la structure objet {value: '...'} pour les filtres texte.
                    const textFilter = this.activeFilters[criterion.Nom];
                    const textValue = (typeof textFilter === 'object' && textFilter !== null) ? textFilter.value : (textFilter || '');
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
                    const optionFilter = this.activeFilters[criterion.Nom];
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
    isCriterionActive(criterion) {
        const filter = this.activeFilters[criterion.Nom];
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

    submitSimpleSearch(event) {
        event.preventDefault();

        const inputValue = this.simpleSearchInputTarget.value.trim();
        const criterionName = this.simpleSearchCriterionTarget.value;
        const criterionDef = this.criteriaValue.find(c => c.Nom === criterionName);

        if (!criterionDef) return;

        if (inputValue) {
            // On construit le filtre avec la structure attendue par le backend
            const filter = {
                operator: 'LIKE',
                value: inputValue,
                // On ajoute le targetField si c'est une relation
                ...(criterionDef.targetField && { targetField: criterionDef.targetField })
            };
            this.activeFilters[criterionName] = filter;
        } else {
            delete this.activeFilters[criterionName];
        }
        this.dispatchSearchEvent();
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
        this.dispatchSearchEvent(); // Relance simplement la recherche avec les filtres courants.
    }

    dispatchSearchEvent() {
        // Notifie le cerveau pour démarrer la barre de progression
        // CORRECTION : La sauvegarde se fait ici pour capturer TOUS les changements de filtres.
        const storageKey = `lastSearchCriteria_${this.nomEntiteValue}`;
        sessionStorage.setItem(storageKey, JSON.stringify(this.activeFilters));
        console.log(`${this.nomControleur} - Filtres sauvegardés dans sessionStorage:`, this.activeFilters);

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
