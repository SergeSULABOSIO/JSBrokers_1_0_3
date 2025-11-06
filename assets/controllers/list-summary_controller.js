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
        console.log(this.nomControleur + " - Code: 1980 - handleStateUpdate - Received selection:", selection, "numericAttributes:", numericAttributes, "numericData:", numericData);

        // Si aucun attribut numérique n'est fourni, on masque la barre et on réinitialise tout.
        // CORRECTION : On vérifie si c'est un tableau non vide.
        if (!Array.isArray(numericAttributes) || numericAttributes.length === 0) {
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
        this.selectedIds = new Set((selection || []).map(s => parseInt(s.id, 10)));
        this.updateAttributeSelector(numericAttributes || {});
        this.recalculate();
    }

    /**
     * Met à jour les options du sélecteur d'attributs.
     * @param {object} attributes - Un objet { clé: libellé } des attributs.
     * @private
     */
    updateAttributeSelector(attributes) {
        console.log(this.nomControleur + " - Code:1980 - updateAttributeSelector - Attributes for selector:", attributes);
        // On vide les options existantes
        this.attributeSelectorTarget.innerHTML = '<option value="">-- Choisir --</option>';

        // On vérifie que les attributs sont bien un tableau (format attendu de `colonnes_numeriques`)
        if (!Array.isArray(attributes) || attributes.length === 0) {
            return;
        }

        // On itère sur le tableau d'objets pour créer les options
        for (const attr of attributes) {
            this.attributeSelectorTarget.appendChild(new Option(attr.titre_colonne, attr.attribut_code));
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
            // CORRECTION : La valeur est directement dans itemData[attribute], pas dans un sous-objet .value
            if (itemData && itemData[attribute] && typeof itemData[attribute].value === 'number') { // Vérifie que la structure est correcte
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
        console.log(this.nomControleur + " - Code: 1980 - displayTotals - Global Total:", globalTotal, "Selection Total:", selectionTotal);
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