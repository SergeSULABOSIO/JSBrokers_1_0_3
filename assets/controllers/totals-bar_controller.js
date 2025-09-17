// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION } from './base_controller.js';

// --- AJOUT ---
// Le contrôleur écoute maintenant l'événement personnalisé de list-tabs.
const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        console.log(`${this.nomControleur} - Connecté.`);
        this.nomControleur = "TOTALS-BAR";
        this.selectedIds = new Set(); // contiendra les IDs des lignes cochées, ex: {12, 25}
        this.boundHandleContext = this.handleContextChange.bind(this);
        this.boundHandleSelection = this.handleSelectionChange.bind(this);
        document.addEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    disconnect() {
        // Nettoyage des nouveaux écouteurs.
        document.removeEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    handleContextChange(event) {
        const { numericAttributes, numericData } = event.detail;
        this.numericData = numericData || {}; // On stocke les données
        this.updateAttributeSelector(numericAttributes || {}); // On met à jour le dropdown
        this.recalculate(); // On recalcule tout
    }

    handleSelectionChange(event) {
        const selectedEntities = event.detail.selection || [];
        this.selectedIds = new Set(selectedEntities.map(e => e.id));
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


    recalculate() {
        const attribute = this.attributeSelectorTarget.value;
        if (!attribute) { // Si pas d'attribut sélectionné (liste vide)
            this.displayTotals(0, 0);
            return;
        }

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
        console.log(`${this.nomControleur} - Total Sélection mis à jour :`, globalTotal, selectionTotal);
    }


    displayTotals(globalTotal, selectionTotal) {
        this.globalTotalTarget.textContent = this.formatCurrency(globalTotal);
        this.selectionTotalTarget.textContent = this.formatCurrency(selectionTotal);
    }

    formatCurrency(valueInCents) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' }).format(valueInCents / 100);
    }
}