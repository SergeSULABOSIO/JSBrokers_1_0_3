import { Controller } from '@hotwired/stimulus';

/**
 * @class ListSummaryController
 * @extends Controller
 * @description Gère l'affichage d'une barre de résumé (totaux) pour une liste.
 * Ce contrôleur écoute les changements de contexte de l'application, reçoit des données numériques,
 * et calcule des totaux globaux et de sélection pour un attribut choisi par l'utilisateur.
 */
export default class extends Controller {
    /**
     * @property {HTMLElement[]} globalTotalTargets - L'élément où afficher le total global.
     * @property {HTMLElement[]} selectionTotalTargets - L'élément où afficher le total de la sélection.
     * @property {HTMLSelectElement[]} attributeSelectorTargets - Le sélecteur <select> pour choisir l'attribut à totaliser.
     */
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque le contrôleur est connecté au DOM.
     */
    connect() {
        this.nomControleur = "LIST-SUMMARY";
        console.log(`${this.nomControleur} - Connecté et à l'écoute.`);

        /**
         * @property {object} numericData - Stocke les données numériques brutes reçues.
         * @private
         */
        this.numericData = {};
        /**
         * @property {Set<number>} selectedIds - L'ensemble des IDs des éléments actuellement sélectionnés.
         * @private
         */
        this.selectedIds = new Set();

        // --- CORRECTION : Lier et activer l'écouteur d'événement ---
        this.boundHandleStateUpdate = this.handleStateUpdate.bind(this);
        document.addEventListener('ui:selection.changed', this.boundHandleStateUpdate);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('ui:selection.changed', this.boundHandleStateUpdate);
    }

    /**
     * Gère la mise à jour de l'état reçue du Cerveau.
     * Met à jour les données internes et déclenche un recalcul.
     * @param {CustomEvent} event - L'événement `ui:selection.changed`.
     */
    handleStateUpdate(event) {
        const { selection, numericAttributes, numericData } = event.detail;

        // Si aucun attribut numérique n'est fourni, on masque la barre et on réinitialise tout.
        if (!numericAttributes || Object.keys(numericAttributes).length === 0) {
            this.element.style.display = 'none';
            this.numericData = {};
            this.selectedIds.clear();
            this.updateAttributeSelector({});
            this.displayTotals(0, 0);
            return;
        }

        // Sinon, on s'assure que la barre est visible et on met à jour les données.
        this.element.style.display = 'flex';
        this.numericData = numericData || {};
        this.selectedIds = new Set((selection || []).map(id => parseInt(id, 10)));
        this.updateAttributeSelector(numericAttributes || {});
        this.recalculate();
    }

    /**
     * Met à jour les options du sélecteur d'attributs.
     * @param {object} attributes - Un objet { clé: libellé } des attributs.
     * @private
     */
    updateAttributeSelector(attributes) {
        this.attributeSelectorTarget.innerHTML = '';
        for (const [key, label] of Object.entries(attributes)) {
            this.attributeSelectorTarget.appendChild(new Option(label, key));
        }
    }

    /**
     * Calcule et affiche les totaux en fonction de l'attribut sélectionné.
     * Déclenché par un changement d'état ou par l'utilisateur via le sélecteur.
     */
    recalculate() {
        const attribute = this.attributeSelectorTarget.value;
        if (!attribute) {
            this.displayTotals(0, 0);
            return;
        }

        let globalTotal = 0;
        let selectionTotal = 0;

        for (const id in this.numericData) {
            const itemData = this.numericData[id];
            if (itemData && itemData[attribute]) {
                const value = itemData[attribute].value; // La valeur est en centimes
                globalTotal += value;

                if (this.selectedIds.has(parseInt(id, 10))) {
                    selectionTotal += value;
                }
            }
        }
        this.displayTotals(globalTotal, selectionTotal);
    }

    /**
     * Affiche les totaux formatés dans le DOM.
     * @param {number} globalTotal - Le total pour tous les éléments.
     * @param {number} selectionTotal - Le total pour les éléments sélectionnés.
     * @private
     */
    displayTotals(globalTotal, selectionTotal) {
        this.globalTotalTarget.textContent = this.formatCurrency(globalTotal);
        this.selectionTotalTarget.textContent = this.formatCurrency(selectionTotal);
    }

    /**
     * Formate une valeur en centimes en une chaîne de caractères monétaire.
     * @param {number} valueInCents - La valeur en centimes.
     * @returns {string} La valeur formatée (ex: "1 234,56 €").
     * @private
     */
    formatCurrency(valueInCents) {
        // Note : La devise 'USD' est utilisée comme exemple, à adapter si nécessaire.
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' }).format(valueInCents / 100);
    }
}