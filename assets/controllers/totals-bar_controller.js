// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION } from './base_controller.js';

const EVT_CONTEXT_CHANGED = 'list-tabs:context-changed';

export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        this.nomControleur = "TOTALS-BAR";
        console.log(`${this.nomControleur} - Connecté.`);

        this.activeListElement = null; // Pour stocker l'élément conteneur de la liste active

        // On lie les méthodes à 'this' pour s'assurer qu'elles s'exécutent dans le bon contexte.
        this.boundHandleContextChange = this.handleContextChange.bind(this);
        this.boundRecalculateOnSelectionChange = this.recalculate.bind(this, { fullRecalculate: false });

        // Mise en place des écouteurs d'événements.
        document.addEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContextChange);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundRecalculateOnSelectionChange);
    }

    disconnect() {
        // Nettoyage des écouteurs
        document.removeEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContextChange);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundRecalculate);
    }

    /**
     * NOUVEAU : Gère le changement de contexte de la liste. C'est le cœur de la nouvelle logique.
     */
    handleContextChange(event) {
        const { listElement, numericAttributes } = event.detail;
        console.log(`${this.nomControleur} - Contexte changé. Nouvelle liste active :`, listElement, numericAttributes);
        this.activeListElement = listElement;
        this.updateAttributeSelector(numericAttributes || {});
        // On lance un recalcul complet (global + sélection) pour le nouveau contexte.
        this.recalculate({ fullRecalculate: true });
    }

    /**
     * Met à jour dynamiquement les options du menu déroulant <select>.
     */
    updateAttributeSelector(attributes) {
        this.attributeSelectorTarget.innerHTML = '';
        const hasAttributes = Object.keys(attributes).length > 0;
        this.element.style.display = hasAttributes ? 'flex' : 'none'; // Cache toute la barre si pas d'attributs

        for (const [key, label] of Object.entries(attributes)) {
            const option = new Option(label, key);
            this.attributeSelectorTarget.appendChild(option);
        }
    }

    /**
     * La fonction de calcul principale, maintenant plus intelligente.
     * Elle accepte une option pour savoir si elle doit recalculer le total global,
     * ce qui optimise les performances lors d'un simple changement de sélection.
     */
    recalculate(options = { fullRecalculate: true }) {
        // Si aucune liste n'est active ou si le sélecteur n'a pas de valeur, on réinitialise et on arrête.
        if (!this.activeListElement || !this.attributeSelectorTarget.value) {
            this.globalTotalTarget.innerHTML = this.formatCurrency(0);
            this.selectionTotalTarget.innerHTML = this.formatCurrency(0);
            return;
        }

        const attribute = this.attributeSelectorTarget.value; // 'amountInvoiced', 'amountPaid', etc.
        const dataAttribute = `data-${this.camelToKebab(attribute)}-value`; // 'data-amount-invoiced-value'

        console.log(`${this.nomControleur} - Recalcul sur l'attribut : ${attribute}`);

        const checkedRows = this.getCheckedRowsScoped(dataAttribute);
        const selectionTotal = this.sumValues(checkedRows, dataAttribute);
        this.selectionTotalTarget.textContent = this.formatCurrency(selectionTotal);

        // Le total global est plus coûteux à calculer, on ne le fait que si nécessaire.
        if (options.fullRecalculate) {
            const allRows = this.activeListElement.querySelectorAll(`tr[${dataAttribute}]`);
            const globalTotal = this.sumValues(allRows, dataAttribute);
            this.globalTotalTarget.textContent = this.formatCurrency(globalTotal);
        }
        console.log(`${this.nomControleur} - Total Sélection mis à jour :`, selectionTotal);
    }

    /**
     * NOUVELLE MÉTHODE SCOPÉE : Ne cherche les cases cochées qu'à l'intérieur de la liste active.
     */
    getCheckedRowsScoped(dataAttribute) {
        if (!this.activeListElement) return [];
        return Array.from(this.activeListElement.querySelectorAll('input[type="checkbox"]:checked'))
            .map(checkbox => checkbox.closest(`tr[${dataAttribute}]`))
            .filter(row => row !== null);
    }

    sumValues(elements, dataAttribute) {
        return Array.from(elements).reduce((sum, el) => {
            return sum + (parseInt(el.getAttribute(dataAttribute), 10) || 0);
        }, 0);
    }

    formatCurrency(valueInCents) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' }).format(valueInCents / 100);
    }

    camelToKebab(str) {
        return str.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
    }
}