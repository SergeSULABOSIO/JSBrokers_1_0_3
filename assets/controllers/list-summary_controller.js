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
        this.boundHandleContextChanged = this.handleContextChanged.bind(this);
        document.addEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:context.changed', this.boundHandleContextChanged);
    }

    /**
     * Gère la mise à jour de l'état reçue du Cerveau.
     * Met à jour les données internes et déclenche un recalcul.
     * @param {CustomEvent} event - L'événement `app:context.changed`.
     */
    handleContextChanged(event) {
        const { selection, numericAttributesAndValues, formCanvas } = event.detail;
        // console.log(`${this.nomControleur} - Contexte reçu.`, { selection, numericAttributesAndValues, formCanvas });

        // On dérive la liste des attributs numériques directement depuis le canvas, notre source de vérité.
        const numericAttributes = formCanvas?.liste?.filter(
            attr => ['Nombre', 'Entier', 'Calcul'].includes(attr.type)
        ) || [];

        // Si le canvas ne définit aucun attribut numérique, on masque la barre et on réinitialise.
        if (numericAttributes.length === 0) {
            this.element.style.display = 'none';
            this.numericData = {};
            this.selectedIds.clear();
            this.updateAttributeSelector([]);
            this.displayTotals(0, 0);
            return;
        }

        // La barre est utile, on s'assure qu'elle est visible et on met à jour l'état interne.
        this.element.style.display = 'flex';
        this.numericData = numericAttributesAndValues || {};
        this.selectedIds = new Set((selection || []).map(s => parseInt(s.id, 10)));
        this.updateAttributeSelector(numericAttributes);
        this.recalculate();
    }

    /**
     * Met à jour les options du sélecteur d'attributs.
     * @param {object} attributes - Un objet { clé: libellé } des attributs.
     * @private
     */
    updateAttributeSelector(attributes) {
        const currentValue = this.attributeSelectorTarget.value;
        this.attributeSelectorTarget.innerHTML = '<option value="">-- Choisir --</option>';

        // On itère sur le tableau d'objets pour créer les options
        for (const attr of attributes) {
            // On utilise `intitule` et `code` comme standard, provenant du canvas.
            this.attributeSelectorTarget.appendChild(new Option(attr.intitule, attr.code));
        }

        // On essaie de restaurer la sélection précédente si l'option existe toujours.
        this.attributeSelectorTarget.value = currentValue;
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

        // On utilise des méthodes fonctionnelles pour un code plus clair et moins sujet aux erreurs.
        const allValues = Object.values(this.numericData);

        // Calcul du total global
        const globalTotal = allValues
            .map(item => item[attribute]?.value || 0) // Extrait la valeur de l'attribut, ou 0 si non trouvée
            .reduce((sum, value) => sum + value, 0); // Somme toutes les valeurs

        // Calcul du total de la sélection
        const selectionTotal = Object.entries(this.numericData)
            .filter(([id, item]) => this.selectedIds.has(parseInt(id, 10))) // Garde uniquement les éléments sélectionnés
            .map(([id, item]) => item[attribute]?.value || 0) // Extrait leur valeur
            .reduce((sum, value) => sum + value, 0); // Somme les valeurs de la sélection

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
        // Note : La devise 'EUR' est utilisée par défaut pour un contexte francophone.
        // Ceci pourrait être rendu dynamique via une configuration globale.
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(valueInCents / 100);
    }
}