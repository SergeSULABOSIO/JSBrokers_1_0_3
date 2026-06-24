import { Controller } from '@hotwired/stimulus';

/**
 * @class WeightsEditorController
 * @extends Controller
 * @description Édition des « Poids d'écriture par entité » du plan tarifaire (Console)
 * sous forme de collection stylisée + boîte de dialogue d'ajout / édition — même
 * pattern que `packs-editor`. Le champ caché `input` (plan_tarifaire[writeWeightsJson])
 * reste la SOURCE DE VÉRITÉ soumise au serveur : à chaque modification, le contrôleur
 * le re-sérialise en JSON ({ "<FQCN>": <poids> }). Le contrôleur PHP le décode tel quel.
 *
 * La clé est le FQCN de l'entité, choisi dans une liste fermée d'entités facturables
 * connues (valeur `labels`, FQCN => libellé). La suppression réutilise la boîte de
 * confirmation générique du projet via le bus d'événements (cf. packs-editor).
 */
export default class extends Controller {
    static targets = [
        'input', 'list', 'empty', 'rowTemplate', 'addButton', 'icons',
        'dialog', 'dialogTitle', 'dialogIcon', 'fieldEntity', 'fieldWeight', 'error',
    ];

    static values = { labels: Object };

    connect() {
        this.weights = this.parse();
        this.editingKey = null;
        this.iconHtml = this.buildIconMap();
        this.defaultDialogIcon = this.hasDialogIconTarget ? this.dialogIconTarget.innerHTML : '';
        this.render();

        this.boundConfirmed = this.onDeleteConfirmed.bind(this);
        document.addEventListener('cerveau:event', this.boundConfirmed);
    }

    /** Construit la carte FQCN → SVG d'icône à partir du gabarit serveur. */
    buildIconMap() {
        const map = {};
        if (!this.hasIconsTarget) { return map; }
        this.iconsTarget.content.querySelectorAll('[data-fqcn]').forEach((node) => {
            map[node.dataset.fqcn] = node.innerHTML;
        });
        return map;
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundConfirmed);
    }

    parse() {
        try {
            const data = JSON.parse(this.inputTarget.value || '{}');
            return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
        } catch (e) {
            return {};
        }
    }

    /** Libellé d'affichage d'une entité (repli : nom court de la classe). */
    labelFor(fqcn) {
        if (this.labelsValue && this.labelsValue[fqcn]) {
            return this.labelsValue[fqcn];
        }
        const parts = fqcn.split('\\');
        return parts[parts.length - 1] || fqcn;
    }

    /** Entités connues encore disponibles à l'ajout (non déjà configurées). */
    availableEntities() {
        return Object.keys(this.labelsValue || {})
            .filter((fqcn) => !Object.prototype.hasOwnProperty.call(this.weights, fqcn));
    }

    render() {
        const keys = Object.keys(this.weights);
        this.listTarget.innerHTML = '';
        this.emptyTarget.hidden = keys.length > 0;

        keys.forEach((fqcn) => {
            const node = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
            node.querySelector('[data-field="entity"]').textContent = this.labelFor(fqcn);
            node.querySelector('[data-field="weight"]').textContent = this.formatInt(this.weights[fqcn]);
            // Icône propre à l'entité (repli : icône par défaut déjà dans le gabarit).
            if (this.iconHtml[fqcn]) {
                node.querySelector('.cs-pack-icon').innerHTML = this.iconHtml[fqcn];
            }
            node.querySelectorAll('[data-key]').forEach((btn) => { btn.dataset.key = fqcn; });
            this.listTarget.appendChild(node);
        });

        // Quand toutes les entités connues sont configurées, l'ajout n'a plus de sens.
        if (this.hasAddButtonTarget) {
            const full = this.availableEntities().length === 0;
            this.addButtonTarget.disabled = full;
            this.addButtonTarget.title = full ? 'Toutes les entités connues sont déjà configurées.' : '';
        }

        this.sync();
    }

    sync() {
        this.inputTarget.value = JSON.stringify(this.weights);
    }

    /** Ouvre la boîte de dialogue en ajout : sélecteur des entités disponibles. */
    add() {
        if (this.availableEntities().length === 0) { return; }
        this.editingKey = null;
        this.dialogTitleTarget.textContent = "Nouveau poids d'écriture";
        this.populateEntities(null, false);
        this.fieldWeightTarget.value = '';
        this.syncDialogIcon();
        this.clearError();
        this.open();
        this.fieldEntityTarget.focus();
    }

    /** Ouvre la boîte de dialogue en édition : l'entité (clé) est figée. */
    edit(event) {
        const fqcn = event.currentTarget.dataset.key;
        if (!Object.prototype.hasOwnProperty.call(this.weights, fqcn)) { return; }

        this.editingKey = fqcn;
        this.dialogTitleTarget.textContent = "Modifier le poids d'écriture";
        this.populateEntities(fqcn, true);
        this.fieldWeightTarget.value = this.weights[fqcn];
        this.syncDialogIcon();
        this.clearError();
        this.open();
        this.fieldWeightTarget.focus();
        this.fieldWeightTarget.select();
    }

    /** Aligne l'icône de l'entête du dialogue sur l'entité sélectionnée. */
    syncDialogIcon() {
        if (!this.hasDialogIconTarget) { return; }
        const fqcn = this.fieldEntityTarget.value;
        this.dialogIconTarget.innerHTML = this.iconHtml[fqcn] || this.defaultDialogIcon;
    }

    /**
     * (Re)construit les options du sélecteur d'entité.
     * @param {?string} selected - FQCN à présélectionner.
     * @param {boolean} lock - true en édition : on ne propose que l'entité courante,
     *                          sélecteur désactivé (la clé ne change pas).
     */
    populateEntities(selected, lock) {
        const select = this.fieldEntityTarget;
        select.innerHTML = '';

        const list = lock ? [selected] : this.availableEntities();
        list.forEach((fqcn) => {
            const opt = document.createElement('option');
            opt.value = fqcn;
            opt.textContent = this.labelFor(fqcn);
            select.appendChild(opt);
        });

        if (selected) { select.value = selected; }
        select.disabled = lock;
    }

    /** Demande de suppression : délègue à la boîte de confirmation générique. */
    delete(event) {
        const fqcn = event.currentTarget.dataset.key;
        if (!Object.prototype.hasOwnProperty.call(this.weights, fqcn)) { return; }
        const nom = this.labelFor(fqcn);

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: "Supprimer le poids d'écriture",
                body: `Voulez-vous vraiment retirer le poids d'écriture de l'entité <strong>${this._escapeHtml(nom)}</strong> ? `
                    + 'Elle utilisera alors le poids d\'écriture par défaut.',
                itemDescriptions: [nom],
                showIrreversible: true,
                onConfirm: { type: 'weights:delete', payload: { fqcn } },
            },
        }));
    }

    /** Reçu après confirmation de l'utilisateur (cerveau:event), filtré par type. */
    onDeleteConfirmed(event) {
        const detail = event.detail || {};
        if (detail.type !== 'weights:delete') { return; }

        const fqcn = detail.payload && detail.payload.fqcn;
        if (fqcn && Object.prototype.hasOwnProperty.call(this.weights, fqcn)) {
            delete this.weights[fqcn];
            this.render();
        }
        document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
    }

    /** Valide et enregistre (ajout ou édition), puis ferme. */
    save(event) {
        if (event) { event.preventDefault(); }

        const fqcn = this.fieldEntityTarget.value;
        const weight = parseInt(this.fieldWeightTarget.value, 10);

        if (!fqcn) {
            this.showError('Veuillez choisir une entité.');
            return;
        }
        if (!Number.isFinite(weight) || weight <= 0) {
            this.showError('Le poids d\'écriture doit être un entier strictement positif.');
            this.fieldWeightTarget.focus();
            return;
        }

        this.weights[fqcn] = weight;
        this.render();
        this.close();
    }

    cancel() {
        this.close();
    }

    open() {
        if (typeof this.dialogTarget.showModal === 'function') {
            this.dialogTarget.showModal();
        } else {
            this.dialogTarget.setAttribute('open', '');
        }
    }

    close() {
        this.clearError();
        if (typeof this.dialogTarget.close === 'function') {
            this.dialogTarget.close();
        } else {
            this.dialogTarget.removeAttribute('open');
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    clearError() {
        this.errorTarget.textContent = '';
        this.errorTarget.hidden = true;
    }

    formatInt(value) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) ? n.toLocaleString('fr-FR') : value;
    }

    _escapeHtml(unsafe) {
        return String(unsafe)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
}
