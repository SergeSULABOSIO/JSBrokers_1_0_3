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
        const { selection, numericAttributesAndValues } = event.detail;
        console.log(`${this.nomControleur} - Contexte reçu.`, { selection, numericAttributesAndValues });

        const hasNumericData = numericAttributesAndValues && Object.keys(numericAttributesAndValues).length > 0;
        let numericAttributes = [];

        // La barre des totaux doit toujours être visible pour afficher un statut.
        this.element.style.display = 'flex';

        if (hasNumericData) {
            // On prend le premier enregistrement comme modèle pour les en-têtes (options du sélecteur).
            const attributesModel = Object.values(numericAttributesAndValues)[0];
            
            // On extrait les paires code/intitulé pour peupler le sélecteur.
            numericAttributes = Object.entries(attributesModel).map(([code, details]) => ({
                code: code,
                intitule: details.description
            }));
        }

        // On met à jour l'état interne dans tous les cas.
        this.numericData = numericAttributesAndValues || {};
        this.selectedIds = new Set((selection || []).map(s => parseInt(s.id, 10)));
        
        // On met à jour le sélecteur avec les attributs trouvés (ou un message si vide).
        this.updateAttributeSelector(numericAttributes);

        // On recalcule les totaux, ce qui mettra à jour l'affichage.
        this.recalculate();
    }

    /**
     * Met à jour les options du sélecteur d'attributs.
     * @param {Array<object>} attributes - Un tableau d'objets { code, intitule }.
     * @private
     */
    updateAttributeSelector(attributes) {
        const currentValue = this.attributeSelectorTarget.value;
        this.attributeSelectorTarget.innerHTML = ''; // On vide complètement

        if (attributes.length === 0) {
            this.attributeSelectorTarget.innerHTML = '<option value="">Aucune valeur numérique à calculer</option>';
            this.attributeSelectorTarget.disabled = true;
            this.element.classList.add('is-disabled');
        } else {
            this.attributeSelectorTarget.disabled = false;
            this.element.classList.remove('is-disabled');
            this.attributeSelectorTarget.innerHTML = '<option value="">-- Choisir --</option>';
            for (const attr of attributes) {
                this.attributeSelectorTarget.appendChild(new Option(attr.intitule, attr.code));
            }
            // On essaie de restaurer la sélection précédente si l'option existe toujours.
            this.attributeSelectorTarget.value = currentValue;
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
        // console.log(this.nomControleur + " - Code: 1980 - displayTotals - Global Total:", globalTotal, "Selection Total:", selectionTotal);
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