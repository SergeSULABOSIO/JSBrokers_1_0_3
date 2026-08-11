import { Controller } from '@hotwired/stimulus';

/**
 * @class FormatsEditorController
 * @extends Controller
 * @description Édition des « multiplicateurs par format » des documents produits par
 * l'assistant (Console, plan tarifaire) — même pattern que `weights-editor` et
 * `packs-editor`. Le champ caché `input` (plan_tarifaire[documentFormatsJson]) reste
 * la SOURCE DE VÉRITÉ soumise au serveur : à chaque modification, le contrôleur le
 * re-sérialise en JSON ({ "<format>": <multiplicateur> }), que le contrôleur PHP
 * décode tel quel (decodeJsonMap).
 *
 * DEUX DIFFÉRENCES avec `weights-editor`, et elles ne sont pas cosmétiques :
 *  - la valeur est DÉCIMALE (1,5 et non 15) : un multiplicateur n'est pas un poids ;
 *  - la clé est un format servi, choisi dans une liste fermée. On peut en modifier
 *    la valeur, jamais retirer un format de la carte : un format absent serait
 *    facturé au multiplicateur neutre, en silence — d'où l'absence de suppression.
 */
export default class extends Controller {
    static targets = [
        'input', 'list', 'empty', 'rowTemplate',
        'dialog', 'dialogTitle', 'fieldFormat', 'fieldMultiplicateur', 'error',
    ];

    /** { "docx": "Word (.docx)", … } — libellés fournis par le serveur. */
    static values = { labels: Object };

    connect() {
        this.formats = this.parse();
        this.editingKey = null;
        this.render();
    }

    parse() {
        try {
            const data = JSON.parse(this.inputTarget.value || '{}');
            return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
        } catch (e) {
            return {};
        }
    }

    labelFor(format) {
        if (this.labelsValue && this.labelsValue[format]) {
            return this.labelsValue[format];
        }
        return String(format).toUpperCase();
    }

    render() {
        const cles = Object.keys(this.formats).sort();
        this.listTarget.innerHTML = '';
        this.emptyTarget.hidden = cles.length > 0;

        cles.forEach((format) => {
            const node = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
            node.querySelector('[data-field="format"]').textContent = this.labelFor(format);
            node.querySelector('[data-field="multiplicateur"]').textContent = `×${this.formatDecimal(this.formats[format])}`;
            node.querySelectorAll('[data-key]').forEach((btn) => { btn.dataset.key = format; });
            this.listTarget.appendChild(node);
        });

        this.sync();
    }

    sync() {
        this.inputTarget.value = JSON.stringify(this.formats);
    }

    /** Ouvre la boîte de dialogue : le format est figé, seule sa valeur change. */
    edit(event) {
        const format = event.currentTarget.dataset.key;
        if (!Object.prototype.hasOwnProperty.call(this.formats, format)) { return; }

        this.editingKey = format;
        this.dialogTitleTarget.textContent = `Multiplicateur — ${this.labelFor(format)}`;
        this.fieldFormatTarget.value = this.labelFor(format);
        this.fieldMultiplicateurTarget.value = this.formats[format];
        this.clearError();
        this.open();
        this.fieldMultiplicateurTarget.focus();
        this.fieldMultiplicateurTarget.select();
    }

    save(event) {
        if (event) { event.preventDefault(); }

        const format = this.editingKey;
        // Virgule décimale acceptée : c'est ce qu'un agent francophone tape.
        const valeur = parseFloat(String(this.fieldMultiplicateurTarget.value).replace(',', '.'));

        if (!format) {
            this.showError('Aucun format sélectionné.');
            return;
        }
        if (!Number.isFinite(valeur) || valeur <= 0) {
            this.showError('Le multiplicateur doit être un nombre strictement positif (ex. 1,5).');
            this.fieldMultiplicateurTarget.focus();
            return;
        }

        this.formats[format] = valeur;
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

    /** « 1,5 » et non « 1.5 » : on écrit un nombre français. */
    formatDecimal(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n.toLocaleString('fr-FR', { maximumFractionDigits: 2 }) : value;
    }
}
