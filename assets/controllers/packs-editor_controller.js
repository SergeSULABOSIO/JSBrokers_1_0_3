import { Controller } from '@hotwired/stimulus';

/**
 * @class PacksEditorController
 * @extends Controller
 * @description Édition des « Paquets prépayés » du plan tarifaire (Console) sous
 * forme de collection stylisée + boîte de dialogue d'ajout / édition. Le champ
 * caché `input` (plan_tarifaire[packsJson]) reste la SOURCE DE VÉRITÉ soumise au
 * serveur : à chaque modification, le contrôleur le re-sérialise en JSON
 * ({ "<clé>": { label, tokens, price } }). Le contrôleur PHP le décode tel quel.
 *
 * La clé technique est STABLE : générée (slug) depuis le nom à la création, puis
 * conservée à l'édition — ainsi renommer un paquet ne casse jamais les liens
 * d'achat ni les cartes vitrine codées en dur (intermediaire / professionnel).
 */
export default class extends Controller {
    static targets = [
        'input', 'list', 'empty', 'rowTemplate', 'dialog',
        'dialogTitle', 'fieldLabel', 'fieldTokens', 'fieldPrice', 'error',
    ];

    connect() {
        this.packs = this.parse();
        this.editingKey = null;
        this.render();

        // Suppression : on réutilise la boîte de confirmation générique du projet
        // (composant `confirmation-dialog`), entièrement découplée via le bus
        // d'événements. À la confirmation, elle émet `cerveau:event` ; ici, faute
        // de Cerveau sur la Console, on traite nous-mêmes l'événement et on referme.
        this.boundConfirmed = this.onDeleteConfirmed.bind(this);
        document.addEventListener('cerveau:event', this.boundConfirmed);
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundConfirmed);
    }

    /** Parse le JSON du champ caché en objet sûr (objet vide si invalide). */
    parse() {
        try {
            const data = JSON.parse(this.inputTarget.value || '{}');
            return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
        } catch (e) {
            return {};
        }
    }

    /** Rend la collection à partir du modèle, puis synchronise le champ caché. */
    render() {
        const keys = Object.keys(this.packs);
        this.listTarget.innerHTML = '';
        this.emptyTarget.hidden = keys.length > 0;

        keys.forEach((key) => {
            const pack = this.packs[key];
            const node = this.rowTemplateTarget.content.firstElementChild.cloneNode(true);
            node.querySelector('[data-field="label"]').textContent = pack.label || this.titleize(key);
            node.querySelector('[data-field="tokens"]').textContent = this.formatInt(pack.tokens);
            node.querySelector('[data-field="price"]').textContent = this.formatPrice(pack.price);
            node.querySelectorAll('[data-key]').forEach((btn) => { btn.dataset.key = key; });
            this.listTarget.appendChild(node);
        });

        this.sync();
    }

    /** Écrit le modèle dans le champ caché soumis au serveur. */
    sync() {
        this.inputTarget.value = JSON.stringify(this.packs);
    }

    /** Ouvre la boîte de dialogue en création (clé générée à la validation). */
    add() {
        this.editingKey = null;
        this.dialogTitleTarget.textContent = 'Nouveau paquet';
        this.fieldLabelTarget.value = '';
        this.fieldTokensTarget.value = '';
        this.fieldPriceTarget.value = '';
        this.clearError();
        this.open();
        this.fieldLabelTarget.focus();
    }

    /** Ouvre la boîte de dialogue pré-remplie (la clé du paquet est figée). */
    edit(event) {
        const key = event.currentTarget.dataset.key;
        const pack = this.packs[key];
        if (!pack) { return; }

        this.editingKey = key;
        this.dialogTitleTarget.textContent = 'Modifier le paquet';
        this.fieldLabelTarget.value = pack.label || this.titleize(key);
        this.fieldTokensTarget.value = pack.tokens;
        this.fieldPriceTarget.value = pack.price;
        this.clearError();
        this.open();
        this.fieldLabelTarget.focus();
    }

    /**
     * Demande la suppression d'un paquet via la boîte de confirmation stylisée
     * du projet (action irréversible). La suppression effective est faite à la
     * réception de la confirmation (cf. onDeleteConfirmed).
     */
    delete(event) {
        const key = event.currentTarget.dataset.key;
        const pack = this.packs[key];
        if (!pack) { return; }

        const nom = pack.label || this.titleize(key);
        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer le paquet',
                body: `Voulez-vous vraiment supprimer le paquet <strong>${this.escapeHtml(nom)}</strong> ?`
                    + ' Il ne sera plus proposé à l\'achat ni affiché sur le portail public.',
                itemDescriptions: [nom],
                showIrreversible: true,
                onConfirm: { type: 'packs:delete', payload: { key } },
            },
        }));
    }

    /**
     * Réception de la confirmation émise par la boîte de dialogue (bus Cerveau).
     * On ne traite que notre type d'action, on supprime puis on referme la modale.
     */
    onDeleteConfirmed(event) {
        const detail = event.detail || {};
        if (detail.type !== 'packs:delete') { return; }

        const key = detail.payload ? detail.payload.key : null;
        if (key && Object.prototype.hasOwnProperty.call(this.packs, key)) {
            delete this.packs[key];
            this.render();
        }
        document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
    }

    /** Valide les champs et enregistre (création ou édition), puis ferme. */
    save(event) {
        if (event) { event.preventDefault(); }

        const label = this.fieldLabelTarget.value.trim();
        const tokens = parseInt(this.fieldTokensTarget.value, 10);
        const price = parseFloat(this.fieldPriceTarget.value);

        if (label === '') {
            this.showError('Le nom du plan est obligatoire.');
            this.fieldLabelTarget.focus();
            return;
        }
        if (!Number.isFinite(tokens) || tokens <= 0) {
            this.showError('La quantité de tokens doit être un entier strictement positif.');
            this.fieldTokensTarget.focus();
            return;
        }
        if (!Number.isFinite(price) || price < 0) {
            this.showError('Le prix de vente TTC doit être un nombre positif ou nul.');
            this.fieldPriceTarget.focus();
            return;
        }

        // Clé stable : générée depuis le nom en création, conservée en édition.
        const key = this.editingKey ?? this.uniqueKey(this.slugify(label));
        this.packs[key] = { label, tokens, price };

        this.render();
        this.close();
    }

    /** Annule et ferme sans modifier le modèle. */
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

    /** Slug technique sûr à partir d'un libellé (sans accents, a-z0-9 et tirets). */
    slugify(str) {
        // NFD décompose les lettres accentuées ; on retire ensuite tout ce qui
        // n'est pas ASCII de base (les marques diacritiques sont > 0x7F), puis on
        // ne garde que a-z0-9 en remplaçant le reste par des tirets.
        return str
            .toString()
            .normalize('NFD')
            .replace(/[^\x00-\x7F]/g, '')
            .toLowerCase().trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    /** Garantit l'unicité de la clé en suffixant -2, -3… si nécessaire. */
    uniqueKey(base) {
        const root = base || 'paquet';
        let key = root;
        let i = 2;
        while (Object.prototype.hasOwnProperty.call(this.packs, key)) {
            key = `${root}-${i}`;
            i += 1;
        }
        return key;
    }

    titleize(key) {
        return key.charAt(0).toUpperCase() + key.slice(1);
    }

    /** Échappe le HTML (le corps de la confirmation est inséré via innerHTML). */
    escapeHtml(unsafe) {
        return String(unsafe)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    formatInt(value) {
        const n = parseInt(value, 10);
        return Number.isFinite(n) ? n.toLocaleString('fr-FR') : value;
    }

    formatPrice(value) {
        const n = parseFloat(value);
        if (!Number.isFinite(n)) { return value; }
        return Number.isInteger(n) ? String(n) : n.toFixed(2);
    }
}
