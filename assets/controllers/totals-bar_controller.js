// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        this.nomControleur = "TOTALS-BAR";
        console.log(`${this.nomControleur} - Connecté et à l'écoute.`);

        this.numericData = {};
        this.selectedIds = new Set(); // contiendra les IDs des lignes cochées, ex: {12, 25}

        this.boundHandleStateUpdate = this.handleStateUpdate.bind(this);
        // document.addEventListener('ui:selection.changed', this.boundHandleStateUpdate);

        // --- CORRECTION : Demander activement le contexte au démarrage ---
        // On attend un court instant que les autres contrôleurs soient prêts, puis on demande le contexte.
        // setTimeout(() => document.dispatchEvent(new CustomEvent('totals-bar:request-context')), 100);
    }

    disconnect() {
        // document.removeEventListener('ui:selection.changed', this.boundHandleStateUpdate);
    }

    handleStateUpdate(event) {
        const { selection, numericAttributes, numericData } = event.detail;

        // --- CORRECTION : Logique de masquage/affichage centralisée ---
        if (!numericAttributes || Object.keys(numericAttributes).length === 0) {
            this.element.style.display = 'none'; // On masque la barre
            this.numericData = {};
            this.selectedIds.clear();
            this.updateAttributeSelector({}); // Vide le sélecteur
            this.displayTotals(0, 0); // Réinitialise les affichages
            return; // Important : on sort de la fonction
        }

        // Si on arrive ici, c'est qu'il y a des données à afficher.
        this.element.style.display = 'flex'; // On s'assure que la barre est visible
        this.numericData = numericData || {};
        this.selectedIds = new Set((selection || []).map(id => parseInt(id, 10)));
        this.updateAttributeSelector(numericAttributes || {});
        this.recalculate();
    }


    updateAttributeSelector(attributes) {
        this.attributeSelectorTarget.innerHTML = '';
        // La logique d'affichage/masquage est maintenant gérée dans handleContextChange

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