// assets/controllers/search-bar-dialog_controller.js
import BaseController from './base_controller.js';
import { Modal } from 'bootstrap';
import TomSelect from 'tom-select';

/**
 * @class SearchBarDialogController
 * @extends BaseController
 * @description Boîte de dialogue de recherche avancée du workspace.
 *
 * Refonte : le dialogue est désormais PROPRIÉTAIRE du rendu ET de la collecte des
 * critères. Il reçoit du contrôleur `search-bar` les DÉFINITIONS structurées (types,
 * libellés, entité cible des relations) et les filtres actifs, puis :
 *   - rend un formulaire ergonomique (fieldsets, labels, états visibles) ;
 *   - branche un vrai sélecteur autocomplété (Tom Select) sur les critères « relation » ;
 *   - propose modes de correspondance texte, opérateurs numériques, presets de dates ;
 *   - collecte les valeurs en objet structuré (fini le re-parsing fragile du DOM).
 */
export default class extends BaseController {
    static targets = [
        "advancedSearchModal",
        "advancedFormContainer"
    ];

    connect() {
        this.nomControleur = "SEARCH-BAR-DIALOG";
        const workspacePanel = this.element.closest('.workspace-tab-panel');
        this.workspaceTabId = workspacePanel?.dataset.tabId ?? null;
        this.modal = new Modal(this.advancedSearchModalTarget);

        this.criteriaDefs = [];
        this.activeFilters = {};
        this.autocompleteUrl = '';
        this.tomSelects = [];
        this.iconCache = new Map(); // cache local des SVG d'icônes de critères

        this.boundOpenDialog = this.openDialog.bind(this);
        document.addEventListener('dialog:search.open-request', this.boundOpenDialog);

        // Icônes des critères : rendues via le mécanisme partagé (ui:icon.request → app:icon.loaded).
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);

        // Nettoyage des instances Tom Select à la fermeture (évite les fuites/doublons).
        this.advancedSearchModalTarget.addEventListener('hidden.bs.modal', () => this.destroyTomSelects());
    }

    disconnect() {
        document.removeEventListener('dialog:search.open-request', this.boundOpenDialog);
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
        this.destroyTomSelects();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Ouverture & rendu
    // ────────────────────────────────────────────────────────────────────────

    openDialog(event) {
        const { criteria, activeFilters, autocompleteUrl, workspaceTabId } = event.detail;
        if (workspaceTabId && this.workspaceTabId && workspaceTabId !== this.workspaceTabId) return;

        this.criteriaDefs = Array.isArray(criteria) ? criteria : [];
        this.activeFilters = activeFilters || {};
        this.autocompleteUrl = autocompleteUrl || '';

        this.destroyTomSelects();
        this.advancedFormContainerTarget.innerHTML = this.renderForm();
        this.modal.show();

        this.initTomSelects();
        this.loadCriterionIcons();
        this.refreshAllBlocks();
    }

    /**
     * Rend le formulaire complet à partir des définitions de critères.
     * @returns {string}
     */
    renderForm() {
        const advanced = this.criteriaDefs.filter(c => !c.isDefault);
        if (advanced.length === 0) {
            return `<p class="text-muted text-center my-4">Aucun critère de recherche avancée disponible pour cette rubrique.</p>`;
        }
        return advanced.map(c => this.renderCriterion(c)).join('');
    }

    /**
     * Rend un bloc de critère (fieldset + libellé + champ(s)).
     * @param {object} criterion
     * @returns {string}
     */
    renderCriterion(criterion) {
        const id = this.criterionId(criterion);
        const name = this.esc(criterion.Nom);
        const type = this.esc(criterion.Type);
        const field = this.renderField(criterion, id);

        // Icône « de signification » : placeholder rempli après rendu via le mécanisme
        // partagé d'icônes. Alias fourni par le serveur (searchCanvas.Icone).
        const iconAlias = this.esc(criterion.Icone || 'action:filter');
        const iconId = `jsb-crit-icon-${id}`;

        return `
        <fieldset class="criterion-block" data-criterion-block data-criterion-name="${name}" data-criterion-type="${type}">
            <legend class="criterion-header">
                <span class="criterion-header__icon" id="${iconId}" data-icon-alias="${iconAlias}" aria-hidden="true"></span>
                <span class="criterion-header__label">${this.esc(criterion.Display)}</span>
            </legend>
            <div class="criterion-body">${field}</div>
        </fieldset>`;
    }

    /**
     * Demande le rendu des icônes des en-têtes de critères (mécanisme partagé du Cerveau).
     */
    loadCriterionIcons() {
        const spans = this.advancedFormContainerTarget.querySelectorAll('.criterion-header__icon[data-icon-alias]');
        spans.forEach(span => {
            const alias = span.dataset.iconAlias;
            if (!alias) return;
            if (this.iconCache.has(alias)) {
                span.innerHTML = this.iconCache.get(alias);
            } else {
                this.notifyCerveau('ui:icon.request', { iconName: alias, iconSize: 16, requesterId: span.id });
            }
        });
    }

    /**
     * Injecte le SVG d'icône reçu dans le placeholder d'en-tête correspondant.
     */
    handleIconLoaded(event) {
        const { html, requesterId, iconName } = event.detail;
        if (iconName && html && !html.trim().startsWith('<!--')) {
            this.iconCache.set(iconName, html);
        }
        if (requesterId && requesterId.startsWith('jsb-crit-icon-')) {
            const span = document.getElementById(requesterId);
            if (span && html) span.innerHTML = html;
        }
    }

    /**
     * Rend le(s) champ(s) d'un critère selon son type.
     * @param {object} criterion
     * @param {string} id
     * @returns {string}
     */
    renderField(criterion, id) {
        const active = this.activeFilters[criterion.Nom];
        const label = criterion.Display.toLowerCase();

        switch (criterion.Type) {
            case 'Text': {
                const value = this.esc(this.readTextValue(active));
                const mode = this.readTextMode(active);
                return `
                <div class="jsb-field-row">
                    <select class="form-select jsb-field-mode" data-role="text-mode" aria-label="Mode de correspondance">
                        ${this.opt('contains', 'Contient', mode)}
                        ${this.opt('starts', 'Commence par', mode)}
                        ${this.opt('exact', 'Exact', mode)}
                    </select>
                    <input type="text" id="${id}" class="form-control" data-role="text-value"
                           value="${value}" placeholder="Saisir ${this.esc(label)}…"
                           aria-label="${this.esc(criterion.Display)}">
                </div>`;
            }

            case 'Number': {
                const filter = (typeof active === 'object' && active !== null) ? active : {};
                const value = filter.value !== undefined ? this.esc(filter.value) : '';
                const op = filter.operator || '=';
                return `
                <div class="jsb-field-row">
                    <select class="form-select jsb-field-mode" data-role="number-op" aria-label="Opérateur">
                        ${this.opt('=', 'Égal à', op)}
                        ${this.opt('!=', 'Différent de', op)}
                        ${this.opt('>', 'Supérieur à', op)}
                        ${this.opt('>=', 'Supérieur ou égal à', op)}
                        ${this.opt('<', 'Inférieur à', op)}
                        ${this.opt('<=', 'Inférieur ou égal à', op)}
                    </select>
                    <input type="number" id="${id}" class="form-control" data-role="number-value"
                           value="${value}" placeholder="Saisir une valeur…"
                           aria-label="${this.esc(criterion.Display)}">
                </div>`;
            }

            case 'DateTimeRange': {
                const filter = (typeof active === 'object' && active !== null) ? active : {};
                const from = this.esc(filter.from || '');
                const to = this.esc(filter.to || '');
                return `
                <div class="jsb-date-presets" role="group" aria-label="Périodes rapides">
                    <button type="button" class="jsb-preset-btn" data-preset="today">Aujourd'hui</button>
                    <button type="button" class="jsb-preset-btn" data-preset="last30">30 derniers jours</button>
                    <button type="button" class="jsb-preset-btn" data-preset="month">Ce mois</button>
                    <button type="button" class="jsb-preset-btn" data-preset="year">Cette année</button>
                </div>
                <div class="jsb-field-row jsb-date-row">
                    <label class="jsb-field-inline-label" for="${id}_from">Du</label>
                    <input type="date" id="${id}_from" class="form-control" data-role="date-from"
                           value="${from}" aria-label="Date de début">
                    <label class="jsb-field-inline-label" for="${id}_to">au</label>
                    <input type="date" id="${id}_to" class="form-control" data-role="date-to"
                           value="${to}" aria-label="Date de fin">
                </div>
                <p class="jsb-field-error" data-role="date-error" role="alert" hidden>
                    La date de début doit précéder la date de fin.
                </p>`;
            }

            case 'Boolean': {
                const raw = (typeof active === 'object' && active !== null) ? active.value : active;
                const current = raw === undefined || raw === null ? '' : String(raw);
                const options = criterion.Valeur || { '1': 'Oui', '0': 'Non' };
                let opts = this.opt('', 'Tous', current);
                for (const [key, val] of Object.entries(options)) {
                    opts += this.opt(String(key), String(val), current);
                }
                return `
                <select id="${id}" class="form-select" data-role="boolean" aria-label="${this.esc(criterion.Display)}">
                    ${opts}
                </select>`;
            }

            case 'Relation': {
                // Le <select> est amélioré en Tom Select autocompleté après injection.
                // On pré-charge l'option sélectionnée (id + libellé) le cas échéant.
                let preselected = '';
                if (typeof active === 'object' && active !== null && active.value) {
                    preselected = `<option value="${this.esc(active.value)}" selected>${this.esc(active.label || active.value)}</option>`;
                }
                return `
                <select id="${id}" class="form-select jsb-relation-select" data-role="relation"
                        data-entity="${this.esc(criterion.targetEntity || '')}"
                        data-display-field="${this.esc(criterion.displayField || 'nom')}"
                        aria-label="${this.esc(criterion.Display)}">
                    <option value=""></option>
                    ${preselected}
                </select>`;
            }

            default:
                return '';
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Tom Select (critères « relation »)
    // ────────────────────────────────────────────────────────────────────────

    initTomSelects() {
        if (!this.autocompleteUrl) return;
        const selects = this.advancedFormContainerTarget.querySelectorAll('[data-role="relation"]');
        selects.forEach(select => {
            const entity = select.dataset.entity;
            const displayField = select.dataset.displayField || 'nom';
            if (!entity) return;

            const ts = new TomSelect(select, {
                valueField: 'value',
                labelField: 'text',
                searchField: 'text',
                maxItems: 1,
                dropdownParent: 'body', // au-dessus de la modale (cf. .ts-dropdown z-index)
                placeholder: 'Rechercher…',
                loadThrottle: 250,
                load: (query, callback) => {
                    const url = `${this.autocompleteUrl}?entity=${encodeURIComponent(entity)}`
                        + `&displayField=${encodeURIComponent(displayField)}`
                        + `&query=${encodeURIComponent(query)}`;
                    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(response => response.json())
                        .then(json => callback(json.results || []))
                        .catch(() => callback());
                },
                onChange: () => this.refreshBlock(select.closest('[data-criterion-block]'))
            });
            this.tomSelects.push(ts);
        });
    }

    destroyTomSelects() {
        this.tomSelects.forEach(ts => {
            try { ts.destroy(); } catch (e) { /* déjà détruit */ }
        });
        this.tomSelects = [];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Interactions (délégation)
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Réagit aux saisies dans le formulaire : presets de dates, mise à jour de l'état
     * actif des blocs, validation des plages de dates. Câblé via data-action sur le
     * conteneur (input + click).
     */
    onFieldInput(event) {
        const preset = event.target.closest('[data-preset]');
        if (preset) {
            this.applyDatePreset(preset);
            return;
        }
        const block = event.target.closest('[data-criterion-block]');
        if (block) this.refreshBlock(block);
    }

    applyDatePreset(button) {
        const block = button.closest('[data-criterion-block]');
        if (!block) return;
        const fromInput = block.querySelector('[data-role="date-from"]');
        const toInput = block.querySelector('[data-role="date-to"]');
        const today = new Date();
        const fmt = (d) => d.toISOString().slice(0, 10);
        let from = today, to = today;

        switch (button.dataset.preset) {
            case 'today':  from = today; to = today; break;
            case 'last30': from = new Date(today.getTime() - 29 * 86400000); to = today; break;
            case 'month':  from = new Date(today.getFullYear(), today.getMonth(), 1); to = today; break;
            case 'year':   from = new Date(today.getFullYear(), 0, 1); to = today; break;
        }
        if (fromInput) fromInput.value = fmt(from);
        if (toInput) toInput.value = fmt(to);
        this.refreshBlock(block);
    }

    /**
     * Met à jour l'état visuel (is-active) et l'éventuelle erreur de date d'un bloc.
     * @param {HTMLElement|null} block
     */
    refreshBlock(block) {
        if (!block) return;
        block.classList.toggle('is-active', this.blockHasValue(block));

        // Validation de plage de dates.
        const error = block.querySelector('[data-role="date-error"]');
        if (error) {
            const from = block.querySelector('[data-role="date-from"]')?.value || '';
            const to = block.querySelector('[data-role="date-to"]')?.value || '';
            const invalid = from && to && from > to;
            error.hidden = !invalid;
            block.classList.toggle('has-error', !!invalid);
        }
    }

    refreshAllBlocks() {
        this.advancedFormContainerTarget
            .querySelectorAll('[data-criterion-block]')
            .forEach(block => this.refreshBlock(block));
    }

    /**
     * Un bloc a-t-il une valeur significative saisie ?
     * @param {HTMLElement} block
     * @returns {boolean}
     */
    blockHasValue(block) {
        switch (block.dataset.criterionType) {
            case 'Text':     return (block.querySelector('[data-role="text-value"]')?.value || '').trim() !== '';
            case 'Number':   return (block.querySelector('[data-role="number-value"]')?.value || '').trim() !== '';
            case 'Boolean':  return (block.querySelector('[data-role="boolean"]')?.value || '') !== '';
            case 'Relation': {
                const select = block.querySelector('[data-role="relation"]');
                const ts = select?.tomselect;
                return (ts ? (ts.items[0] || '') : (select?.value || '')) !== '';
            }
            case 'DateTimeRange': {
                const from = block.querySelector('[data-role="date-from"]')?.value || '';
                const to = block.querySelector('[data-role="date-to"]')?.value || '';
                return from !== '' || to !== '';
            }
            default: return false;
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Soumission / réinitialisation
    // ────────────────────────────────────────────────────────────────────────

    submitAdvancedSearch(event) {
        event.preventDefault();

        // Bloque la soumission si une plage de dates est incohérente (from > to).
        const invalidBlock = Array.from(
            this.advancedFormContainerTarget.querySelectorAll('[data-criterion-block]')
        ).find(block => {
            const from = block.querySelector('[data-role="date-from"]')?.value || '';
            const to = block.querySelector('[data-role="date-to"]')?.value || '';
            return from && to && from > to;
        });
        if (invalidBlock) {
            this.refreshBlock(invalidBlock);
            invalidBlock.querySelector('[data-role="date-from"]')?.focus();
            return;
        }

        const criteria = {};
        this.advancedFormContainerTarget.querySelectorAll('[data-criterion-block]').forEach(block => {
            const name = block.dataset.criterionName;
            const collected = this.collectBlock(block);
            if (collected !== undefined) criteria[name] = collected;
        });

        this.notifyCerveau('ui:search.submitted', { criteria });
        this.modal.hide();
    }

    /**
     * Collecte la valeur structurée d'un bloc, ou undefined si vide.
     * @param {HTMLElement} block
     * @returns {*}
     */
    collectBlock(block) {
        switch (block.dataset.criterionType) {
            case 'Text': {
                const value = (block.querySelector('[data-role="text-value"]')?.value || '').trim();
                if (!value) return undefined;
                const mode = block.querySelector('[data-role="text-mode"]')?.value || 'contains';
                return mode === 'exact'
                    ? { operator: '=', value }
                    : { operator: 'LIKE', value, mode };
            }
            case 'Number': {
                const value = (block.querySelector('[data-role="number-value"]')?.value || '').trim();
                if (value === '') return undefined;
                const operator = block.querySelector('[data-role="number-op"]')?.value || '=';
                return { operator, value };
            }
            case 'DateTimeRange': {
                const from = (block.querySelector('[data-role="date-from"]')?.value || '').trim();
                const to = (block.querySelector('[data-role="date-to"]')?.value || '').trim();
                if (!from && !to) return undefined;
                return { from, to };
            }
            case 'Boolean': {
                const value = block.querySelector('[data-role="boolean"]')?.value || '';
                if (value === '') return undefined;
                return value; // valeur simple '1' / '0' (égalité stricte côté backend)
            }
            case 'Relation': {
                const select = block.querySelector('[data-role="relation"]');
                if (!select) return undefined;
                // Les options chargées à distance sont gérées par Tom Select : on lit
                // la valeur et le libellé depuis l'instance quand elle est présente.
                const ts = select.tomselect;
                const value = ts ? (ts.items[0] || '') : (select.value || '');
                if (!value) return undefined;
                let label = value;
                if (ts && ts.options[value]) {
                    label = ts.options[value][ts.settings.labelField] || value;
                } else if (select.selectedIndex >= 0 && select.options[select.selectedIndex]) {
                    label = select.options[select.selectedIndex].textContent.trim();
                }
                return { operator: '=', value, label };
            }
            default:
                return undefined;
        }
    }

    resetForm() {
        this.notifyCerveau('ui:search.submitted', { criteria: {} });
        this.modal.hide();
    }

    cancel() {
        this.modal.hide();
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    criterionId(criterion) {
        return `jsb_criterion_${String(criterion.Nom).replace(/[^a-zA-Z0-9_]/g, '_')}`;
    }

    /** Construit une <option>, sélectionnée si sa valeur correspond à la valeur courante. */
    opt(value, label, current) {
        const selected = String(value) === String(current) ? ' selected' : '';
        return `<option value="${this.esc(value)}"${selected}>${this.esc(label)}</option>`;
    }

    readTextValue(active) {
        if (typeof active === 'object' && active !== null) return active.value || '';
        return active || '';
    }

    readTextMode(active) {
        if (typeof active === 'object' && active !== null) {
            if (active.operator === '=') return 'exact';
            return active.mode || 'contains';
        }
        return 'contains';
    }

    /** Échappe une valeur pour insertion HTML sûre. */
    esc(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML.replace(/"/g, '&quot;');
    }
}
