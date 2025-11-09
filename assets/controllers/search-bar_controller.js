// assets/controllers/search-bar_controller.js
import BaseController from './base_controller.js';
import { Modal } from 'bootstrap';

export default class extends BaseController {
    static targets = [
        "simpleSearchInput",
        "advancedSearchModal",
        "advancedFormContainer",
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
        this.modal = new Modal(this.advancedSearchModalTarget);
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);
        this.boundHandleExternalRefresh = this.handleExternalRefresh.bind(this);

        // Écoute l'événement 'shown.bs.modal' pour ajuster le z-index après l'affichage
        this.advancedSearchModalTarget.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
        document.addEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);

        this.initializeCriteria(); // NOUVEAU : On initialise les critères directement
    }

    disconnect() {
        this.advancedSearchModalTarget.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
        document.removeEventListener('app:list.refresh-request', this.boundHandleExternalRefresh);
    }

    // --- Actions de l'utilisateur (logique mise à jour) ---

    openAdvancedSearch() {
        this.modal.show();
    }

    cancelAdvancedSearch() {
        this.modal.hide();
        // On ne réinitialise pas les champs pour que l'utilisateur retrouve ses critères
        // s'il ferme la modale par erreur. La réinitialisation se fait via le bouton "reset".
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

    reset() {
        this.simpleSearchInputTarget.value = '';
        this.advancedFormContainerTarget.querySelectorAll('input, select').forEach(el => el.value = '');
        this.activeFilters = {};
        this.modal.hide();
        this.dispatchSearchEvent(); // Décommenté
    }

    


    
    /**
     * Ajuste le `z-index` de la modale pour s'assurer qu'elle apparaît
     * au-dessus des autres modales déjà ouvertes. Essentiel pour les dialogues imbriqués.
     * @private
     */
    adjustZIndex() {
        // Trouve tous les backdrops visibles
        const backdrops = document.querySelectorAll('.modal-backdrop.show');

        // S'il y a plus d'un backdrop, cela signifie que nous superposons les modales
        if (backdrops.length > 1) {
            // Trouve le z-index le plus élevé parmi TOUTES les modales actuellement visibles
            const modals = document.querySelectorAll('.modal.show');
            let maxZIndex = 0;
            modals.forEach(modal => {
                // On s'assure de ne pas nous comparer à nous-même
                if (modal !== this.advancedSearchModalTarget) {
                    const zIndex = parseInt(window.getComputedStyle(modal).zIndex) || 1055;
                    if (zIndex > maxZIndex) {
                        maxZIndex = zIndex;
                    }
                }
            });

            // On récupère notre modale et son backdrop (c'est toujours le dernier ajouté)
            const myModal = this.advancedSearchModalTarget;
            const myBackdrop = backdrops[backdrops.length - 1];

            // On définit le z-index de notre backdrop pour être au-dessus du maximum trouvé,
            // et celui de notre modale pour être au-dessus de son propre backdrop.
            myBackdrop.style.zIndex = maxZIndex + 1;
            myModal.style.zIndex = maxZIndex + 2;
        }
    }

    /**
     * NOUVEAU : Fusion de la logique de search-criteria_controller.
     * Définit les critères de recherche en fonction du nom de l'entité
     * et initialise la barre de recherche.
     */
    initializeCriteria() {
        console.log(`${this.nomControleur} - Initializing criteria for entity: ${this.nomEntiteValue}`);

        // La logique de `provideCriteria` est maintenant ici.
        // À l'avenir, cette section pourrait être remplacée par un appel API
        // pour récupérer les critères dynamiquement depuis le serveur.
        let criteriaDefinition = [];

        if (this.nomEntiteValue === 'NotificationSinistre') {
            criteriaDefinition = [
                {
                    Nom: 'descriptionDeFait',
                    Display: "Description des faits",
                    Type: 'Text',
                    Valeur: '',
                    isDefault: true
                },
                {
                    Nom: 'notifiedAt',
                    Display: "Date de notification",
                    Type: 'DateTimeRange',
                    Valeur: { from: '', to: '' },
                    isDefault: false
                },
                {
                    Nom: 'referenceSinistre',
                    Display: "Référence du sinistre",
                    Type: 'Text',
                    Valeur: '',
                    isDefault: false
                },
                {
                    Nom: 'referencePolice',
                    Display: "Référence de la police",
                    Type: 'Text',
                    Valeur: '',
                    isDefault: false
                },
                {
                    Nom: 'dommage',
                    Display: "Dommage",
                    Type: 'Number',
                    Valeur: 0,
                    isDefault: false
                },
                {
                    Nom: 'assure.nom',
                    Display: "Client (assuré)",
                    Type: 'Text',
                    Valeur: '',
                    isDefault: false
                }
            ];
        }
        // On pourrait ajouter d'autres `else if (this.nomEntiteValue === '...')` pour d'autres entités.

        this.criteriaValue = criteriaDefinition;
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
        this.dispatchSearchEvent(); // Décommenté
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
        this.dispatchSearchEvent(); // Décommenté
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
}
