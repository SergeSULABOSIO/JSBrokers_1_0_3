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
        nomEntite: String, // NOUVEAU : pour recevoir le nom de l'entité
        autocompleteUrl: String // Endpoint générique d'autocomplétion des critères « relation »
    }

    // La barre de recherche devient "stateless". Elle ne stocke que l'état actuel reçu du cerveau pour le rendu.
    currentCriteria = {};

    connect() {
        this.nomControleur = "SEARCH_BAR";
        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);

        // L'initialisation est minimale. Le rendu se fait via handleContextChanged.
        this.populateSimpleSearchSelector();
        this.updateSimpleSearchPlaceholder();
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    /**
     * Retire l'attribut `readonly` du champ de recherche dès que l'utilisateur lui donne
     * le focus. Le champ est rendu `readonly` côté serveur pour empêcher l'autofill du
     * navigateur (qui ignore autocomplete="off" et y injecte l'email de connexion) ;
     * on le rend éditable uniquement quand l'utilisateur veut réellement saisir.
     */
    enableEditing() {
        if (this.hasSimpleSearchInputTarget && this.simpleSearchInputTarget.hasAttribute('readonly')) {
            this.simpleSearchInputTarget.removeAttribute('readonly');
        }
    }

    openAdvancedSearch() {
        // La barre ne construit plus de HTML : elle transmet les DÉFINITIONS de critères
        // et les filtres actifs au dialogue, qui se charge du rendu et de la collecte.
        // Le dialogue gagne ainsi la maîtrise des champs (Tom Select pour les relations,
        // modes texte, presets de dates…) et supprime le fragile re-parsing du DOM.
        this.notifyCerveau('dialog:search.open-request', {
            criteria: this.criteriaValue,
            activeFilters: this.currentCriteria,
            entiteNom: this.nomEntiteValue,
            autocompleteUrl: this.hasAutocompleteUrlValue ? this.autocompleteUrlValue : ''
        });
    }

    /**
     * NOUVEAU : Point d'entrée unique pour la mise à jour de l'UI de la barre de recherche.
     * Est appelé à chaque fois que le contexte de l'application (et donc les filtres) change.
     * @param {CustomEvent} event 
     */
    handleContextChanged(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
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
        // La recherche simple accepte les critères texte ET les relations directes
        // (recherchées en texte libre via LIKE sur leur champ d'affichage). Les critères
        // à chemin (ex. « portefeuille.gestionnaire ») et les autres types (nombre, date,
        // booléen) restent réservés à la recherche avancée.
        const textCriteria = this.criteriaValue.filter(
            c => (c.Type === 'Text' || c.Type === 'Relation')
                && !String(c.Nom).includes('.')
                && !String(c.Nom).startsWith('__') // critères synthétiques (ex. « Mon portefeuille »)
        );
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

    /**
     * NOUVEAU : Réinitialise tous les filtres et notifie le cerveau.
     */
    resetAllFilters() {
        this.simpleSearchInputTarget.value = '';
        this.notifyCerveau('ui:search.submitted', { criteria: {} });
    }

    submitSimpleSearch(event) {
        event.preventDefault();

        // Garde anti-déclenchement parasite : on ne lance la recherche que si le champ
        // de recherche a réellement le focus (= vraie frappe clavier de l'utilisateur).
        // Bloque les faux « Enter » injectés par l'autofill du navigateur ou un gestionnaire
        // de mots de passe lors du premier geste utilisateur (clic sur une ligne), qui
        // déclenchaient une recherche/recharge complète non désirée de la liste.
        if (document.activeElement !== this.simpleSearchInputTarget) {
            return;
        }

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
            this.summaryTarget.innerHTML = '';
            return;
        }

        let summaryHtml = '';
        activeCriteria.forEach(([key, val]) => {
            const criterionDef = this.criteriaValue.find(c => c.Nom === key);
            const displayName = criterionDef ? criterionDef.Display : key;
            const text = this.formatFilterText(displayName, val, criterionDef);

            summaryHtml += `
            <span class="jsb-filter-badge">
                <span class="jsb-filter-badge__text">${this.escapeHtml(text)}</span>
                <button type="button"
                    class="jsb-filter-badge__remove"
                    aria-label="Retirer le filtre ${this.escapeHtml(displayName)}"
                    data-action="click->search-bar#removeFilter"
                    data-filter-key="${this.escapeHtml(key)}">×</button>
            </span>
        `;
        });
        this.summaryTarget.innerHTML = summaryHtml;
    }

    /**
     * Construit le libellé lisible d'un filtre actif, en fonction du type de critère.
     * @param {string} displayName
     * @param {*} val La valeur du filtre (forme dépendante du type).
     * @param {object|undefined} criterionDef
     * @returns {string}
     */
    formatFilterText(displayName, val, criterionDef) {
        const type = criterionDef ? criterionDef.Type : null;

        // Plage de dates : { from, to }
        if (type === 'DateTimeRange' || (typeof val === 'object' && val !== null && (val.from || val.to))) {
            const { from, to } = val;
            if (from && to) return `${displayName} : du ${from} au ${to}`;
            if (from) return `${displayName} : à partir du ${from}`;
            if (to) return `${displayName} : jusqu'au ${to}`;
        }

        // Relation : { value: id, label } → on affiche le libellé lisible, pas l'id.
        if (type === 'Relation' && typeof val === 'object' && val !== null) {
            return `${displayName} : ${val.label || val.value}`;
        }

        // Booléen : valeur simple '1' / '0' → Oui / Non.
        if (type === 'Boolean') {
            const raw = (typeof val === 'object' && val !== null) ? val.value : val;
            const label = criterionDef && criterionDef.Valeur ? criterionDef.Valeur[String(raw)] : raw;
            return `${displayName} : ${label ?? raw}`;
        }

        // Nombre (ou tout objet avec opérateur) : Display op valeur.
        if (typeof val === 'object' && val !== null && val.operator && type === 'Number') {
            return `${displayName} ${val.operator} ${val.value}`;
        }

        // Texte / repli : { value } ou chaîne simple.
        const displayValue = (typeof val === 'object' && val !== null) ? val.value : val;
        return `${displayName} : "${displayValue}"`;
    }

    /**
     * Échappe une chaîne pour une insertion sûre dans du HTML.
     * @param {*} value
     * @returns {string}
     */
    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
}
