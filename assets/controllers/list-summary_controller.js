import { Controller } from '@hotwired/stimulus';

/**
 * @class ListSummaryController
 * @extends Controller
 * @description La barre des totaux d'une liste.
 *
 * LE SERVEUR REND LA BARRE (chantier 4, décision de Serge : « le chiffre est calculé et formaté
 * par le serveur, plus par le navigateur ») : il envoie, avec la page,
 *   - `attributs` : les options du sélecteur (libellé, nature : montant ou nombre) ;
 *   - `totaux`    : le total de la PAGE de chaque grandeur, déjà formaté comme une cellule ;
 *   - `valeurs`   : les valeurs entières par ligne.
 * Ce contrôleur choisit le texte à montrer : il n'additionne ni ne formate la page.
 * (Étape : le total de la SÉLECTION est encore sommé ici ; le commit 3 le confie au serveur.)
 */
export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    static values = {
        // Repli seulement : le serveur passe la monnaie de travail du cabinet.
        currencyCode: { type: String, default: 'USD' }
    };

    connect() {
        this.nomControleur = "LIST-SUMMARY";
        /** @property {{attributs: Object, totaux: Object, valeurs: Object}} barre - La barre rendue par le serveur. */
        this.barre = { attributs: {}, totaux: {}, valeurs: {} };
        /** @property {Set<number>} selectedIds - Les identifiants des lignes cochées. */
        this.selectedIds = new Set();

        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        // Lecture directe depuis l'attribut HTML du list-manager parent : le premier contexte du
        // Cerveau peut être filtré par workspaceTabId si le panneau est déjà dans le DOM.
        this._readInitialDataFromDom();

        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    /**
     * La barre (données du serveur) et la sélection, publiées par le Cerveau.
     * @param {CustomEvent} event - L'événement `app:context.changed`.
     */
    handleContextChanged(event) {
        if (this.workspaceTabId && event.detail.workspaceTabId !== this.workspaceTabId) return;
        const { selection, numericAttributesAndValues } = event.detail;

        // La barre des totaux doit toujours être visible pour afficher un statut.
        this.element.style.display = 'flex';
        this._poserLaBarre(numericAttributesAndValues);
        this.selectedIds = new Set((selection || []).map(s => parseInt(s.id, 10)));
        this.recalculate();
    }

    /** Une barre bien formée (attributs, totaux, valeurs), sinon une barre vide. */
    _poserLaBarre(donnees) {
        const d = donnees && typeof donnees === 'object' ? donnees : {};
        this.barre = {
            attributs: d.attributs && typeof d.attributs === 'object' ? d.attributs : {},
            totaux: d.totaux && typeof d.totaux === 'object' ? d.totaux : {},
            valeurs: d.valeurs && typeof d.valeurs === 'object' ? d.valeurs : {},
        };
        this.updateAttributeSelector(Object.entries(this.barre.attributs).map(([code, a]) => ({ code, intitule: a.libelle })));
    }

    /**
     * Met à jour les options du sélecteur d'attributs.
     * @param {Array<{code: string, intitule: string}>} attributes
     * @private
     */
    updateAttributeSelector(attributes) {
        const currentValue = this.attributeSelectorTarget.value;
        this.attributeSelectorTarget.innerHTML = '';

        if (attributes.length === 0) {
            this.attributeSelectorTarget.appendChild(new Option('Aucune valeur numérique à calculer', ''));
            this.attributeSelectorTarget.disabled = true;
            this.element.classList.add('is-disabled');
        } else {
            this.attributeSelectorTarget.disabled = false;
            this.element.classList.remove('is-disabled');
            this.attributeSelectorTarget.appendChild(new Option('-- Choisir --', ''));
            for (const attr of attributes) {
                this.attributeSelectorTarget.appendChild(new Option(attr.intitule, attr.code));
            }
            // On restaure la sélection précédente si l'option existe toujours, sinon la première.
            this.attributeSelectorTarget.value = currentValue;
            if (!this.attributeSelectorTarget.value) {
                this.attributeSelectorTarget.value = attributes[0].code;
            }
        }
    }

    /**
     * Montre les totaux de la grandeur choisie : celui de la page tel que le serveur l'a rendu.
     * Déclenché par un changement d'état ou par l'utilisateur via le sélecteur.
     */
    recalculate() {
        const attribute = this.attributeSelectorTarget.value;
        if (!attribute) {
            this.globalTotalTarget.textContent = '—';
            this.selectionTotalTarget.textContent = '—';
            return;
        }
        this.globalTotalTarget.textContent = this.barre.totaux[attribute] ?? '—';

        // Étape (commit 3 du chantier 4 : la sélection passe au serveur).
        const selectionTotal = Object.entries(this.barre.valeurs)
            .filter(([id]) => this.selectedIds.has(parseInt(id, 10)))
            .reduce((somme, [, ligne]) => somme + (ligne[attribute] || 0), 0);
        const nature = (this.barre.attributs[attribute] || {}).nature;
        this.selectionTotalTarget.textContent = nature === 'nombre'
            ? new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 }).format(selectionTotal)
            : this.formatCurrency(selectionTotal);
    }

    /**
     * Lit la barre initiale depuis l'attribut HTML du list-manager parent, sans attendre le
     * premier contexte du Cerveau.
     * @private
     */
    _readInitialDataFromDom() {
        const listManagerEl = this.element.closest('[data-controller~="list-manager"]');
        if (!listManagerEl) return;

        const raw = listManagerEl.getAttribute('data-list-manager-numeric-attributes-and-values-value');
        if (!raw) return;

        try {
            this._poserLaBarre(JSON.parse(raw));
            if (Object.keys(this.barre.attributs).length) {
                this.element.style.display = 'flex';
                this.recalculate();
            }
        } catch (e) {
            console.error(`${this.nomControleur} - Erreur lecture DOM initiale.`, e);
            this._poserLaBarre({});
        }
    }

    /**
     * Étape : format du total de la SÉLECTION (le commit 3 le confie au serveur).
     * @param {number} valueInCents
     * @private
     */
    formatCurrency(valueInCents) {
        const currency = this.currencyCodeValue || 'USD';
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).format(valueInCents / 100);
    }
}
