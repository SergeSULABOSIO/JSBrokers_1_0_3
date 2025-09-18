// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION } from './base_controller.js';

// --- AJOUT ---
// Le contrôleur écoute maintenant l'événement personnalisé de list-tabs.
const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        this.nomControleur = "TOTALS-BAR";
        console.log(`${this.nomControleur} - Connecté et à l'écoute.`);

        this.numericData = {};
        this.selectedIds = new Set(); // contiendra les IDs des lignes cochées, ex: {12, 25}

        this.boundHandleContext = this.handleContextChange.bind(this);
        this.boundHandleSelection = this.handleSelectionChange.bind(this);

        document.addEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    disconnect() {
        document.removeEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    handleContextChange(event) {
        console.log(`${this.nomControleur} - Contexte changé (nouvel onglet).`);
        const { numericAttributes, numericData } = event.detail;

        this.numericData = numericData || {}; // On stocke les données

        // --- AJOUT : Réinitialiser la sélection lors d'un changement d'onglet ---
        // C'est une bonne pratique pour éviter de conserver une sélection d'un onglet précédent.
        this.selectedIds.clear();

        this.updateAttributeSelector(numericAttributes || {});
        this.recalculate();
    }

    /**
     * --- MODIFICATION MAJEURE ---
     * On corrige la méthode pour qu'elle traite correctement le tableau d'IDs.
     */
    handleSelectionChange(event) {
        console.log(`${this.nomControleur} - Événement de sélection reçu.`, event.detail);

        const selectionPayload = event.detail.selection;

        if (selectionPayload && Array.isArray(selectionPayload)) {
            // AU LIEU DE : new Set(selectionPayload.map(e => e.id))
            // ON FAIT : new Set(selectionPayload) car le tableau contient déjà les IDs.
            // On ajoute parseInt pour s'assurer de comparer des nombres.
            this.selectedIds = new Set(selectionPayload.map(id => parseInt(id, 10)));
            console.log(`${this.nomControleur} - IDs de sélection mis à jour :`, this.selectedIds);
        } else {
            console.warn(`${this.nomControleur} - Le payload de sélection est manquant ou incorrect.`);
            this.selectedIds.clear();
        }

        // this.selectedIds = new Set(selectedEntities.map(e => e.id));
        this.recalculate();
    }


    updateAttributeSelector(attributes) {
        this.attributeSelectorTarget.innerHTML = '';
        const hasAttributes = Object.keys(attributes).length > 0;
        this.element.style.display = hasAttributes ? 'flex' : 'none'; // Affiche ou cache la barre

        for (const [key, label] of Object.entries(attributes)) {
            this.attributeSelectorTarget.appendChild(new Option(label, key));
        }
    }

    /**
     * La méthode de calcul principale. Elle est maintenant appelée à chaque fois
     * et se base sur les états `this.numericData` et `this.selectedIds`.
     */
    recalculate() {
        const attribute = this.attributeSelectorTarget.value;
        if (!attribute) { // Si pas d'attribut sélectionné (liste vide)
            this.displayTotals(0, 0);
            return;
        }

        console.log(`${this.nomControleur} - Lancement du recalcul avec la sélection actuelle :`, this.selectedIds);

        let globalTotal = 0;
        let selectionTotal = 0;

        for (const id in this.numericData) {
            const itemData = this.numericData[id];
            if (itemData && itemData[attribute]) {
                const value = itemData[attribute].value; // La valeur en centimes
                globalTotal += value;

                if (this.selectedIds.has(parseInt(id, 10))) {
                    selectionTotal += value;
                }
            }
        }
        this.displayTotals(globalTotal, selectionTotal);
    }


    displayTotals(globalTotal, selectionTotal) {
        this.globalTotalTarget.textContent = this.formatCurrency(globalTotal);
        this.selectionTotalTarget.textContent = this.formatCurrency(selectionTotal);
    }

    formatCurrency(valueInCents) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' }).format(valueInCents / 100);
    }
}