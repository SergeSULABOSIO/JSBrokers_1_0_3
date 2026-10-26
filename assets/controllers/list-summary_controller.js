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
 * Le total de la SÉLECTION vient aussi du serveur : à chaque sélection, le Cerveau rejoue la
 * requête de la liste avec les lignes cochées, et diffuse le texte reçu
 * (`app:totaux-selection.ready`).
 * Ce contrôleur choisit le texte à montrer : il n'additionne ni ne formate rien.
 */
export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];
    // Les mots du serveur (gabarit) : un total qui n'a pas pu être calculé.
    static values = { indisponible: String, indisponibleNote: String };

    connect() {
        this.nomControleur = "LIST-SUMMARY";
        /** @property {{attributs: Object, totaux: Object, valeurs: Object}} barre - La barre rendue par le serveur. */
        this.barre = { attributs: {}, totaux: {}, valeurs: {} };
        /** @property {Set<number>} selectedIds - Les identifiants des lignes cochées. */
        this.selectedIds = new Set();
        /** @property {Object|null|undefined} totauxSelection - Textes du serveur ; undefined : en cours. */
        this.totauxSelection = null;

        const workspacePanel = this.element.closest('[data-tab-id]');
        this.workspaceTabId = workspacePanel ? workspacePanel.dataset.tabId : null;

        // Lecture directe depuis l'attribut HTML du list-manager parent : le premier contexte du
        // Cerveau peut être filtré par workspaceTabId si le panneau est déjà dans le DOM.
        this._readInitialDataFromDom();

        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        this.boundTotauxSelection = this._surTotauxDeLaSelection.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);
        document.addEventListener('app:totaux-selection.ready', this.boundTotauxSelection);
    }

    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
        document.removeEventListener('app:totaux-selection.ready', this.boundTotauxSelection);
    }

    /** La clé d'une sélection : ses identifiants, triés. */
    _cle(ids) {
        return [...ids].map((id) => parseInt(id, 10)).sort((a, b) => a - b).join(',');
    }

    /**
     * Les totaux de la sélection, rendus par le serveur. Une réponse pour une AUTRE sélection
     * que la courante (cases enchaînées) est ignorée.
     * @param {CustomEvent} event
     */
    _surTotauxDeLaSelection(event) {
        const { workspaceTabId, ids, totaux, erreur, suffixe, note } = event.detail || {};
        if (this.workspaceTabId && workspaceTabId !== this.workspaceTabId) return;
        if (this._cle(ids || []) !== this._cle(this.selectedIds)) return;
        // Une ERREUR n'est pas une sélection vide : elle se dit (« indisponible »).
        this.totauxSelection = erreur ? { erreur: true } : (totaux || null);
        // Un total PARTIEL (des lignes cochées ne sont plus dans la liste) : le serveur le dit.
        this.suffixeSelection = suffixe || '';
        this.noteSelection = note || '';
        this.recalculate();
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
        const avant = this._cle(this.selectedIds);
        this.selectedIds = new Set((selection || []).map(s => parseInt(s.id, 10)));
        // Une nouvelle sélection : son total est demandé au serveur (le Cerveau) — en attente.
        if (this._cle(this.selectedIds) !== avant || this.selectedIds.size === 0) {
            this.totauxSelection = this.selectedIds.size ? undefined : null;
        }
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

        // La sélection : le texte du serveur ; « … » pendant le calcul ; « — » sans sélection ;
        // « indisponible » si le calcul a échoué ; la mention du serveur si le total est partiel.
        this.selectionTotalTarget.removeAttribute('title');
        if (this.totauxSelection === undefined) {
            this.selectionTotalTarget.textContent = '…';
        } else if (this.totauxSelection && this.totauxSelection.erreur) {
            this.selectionTotalTarget.textContent = this.indisponibleValue || '—';
            if (this.indisponibleNoteValue) this.selectionTotalTarget.setAttribute('title', this.indisponibleNoteValue);
        } else {
            this.selectionTotalTarget.textContent = ((this.totauxSelection || {})[attribute] ?? '—') + (this.suffixeSelection || '');
            if (this.noteSelection) this.selectionTotalTarget.setAttribute('title', this.noteSelection);
        }
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

}
