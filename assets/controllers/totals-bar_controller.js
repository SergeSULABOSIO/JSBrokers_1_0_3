// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        // Écoute les événements déclenchés par la liste des ventes
        this.boundRecalculate = this.recalculate.bind(this);
        window.addEventListener('sales-list:initialized', this.boundRecalculate);
        window.addEventListener('sales-list:refreshed', this.boundRecalculate);
        window.addEventListener('sales-list:element-checked', this.boundRecalculate);
        window.addEventListener('sales-list:all-checked', this.boundRecalculate);
    }
    
    disconnect() {
        // Nettoyage des écouteurs pour éviter les fuites de mémoire
        window.removeEventListener('sales-list:initialized', this.boundRecalculate);
        window.removeEventListener('sales-list:refreshed', this.boundRecalculate);
        window.removeEventListener('sales-list:element-checked', this.boundRecalculate);
        window.removeEventListener('sales-list:all-checked', this.boundRecalculate);
    }

    recalculate() {
        const attribute = this.attributeSelectorTarget.value; // 'amountInvoiced', 'amountPaid', etc.
        const dataAttribute = `data-${this.camelToKebab(attribute)}-value`; // 'data-amount-invoiced-value'

        const allRows = document.querySelectorAll(`tr[${dataAttribute}]`);
        const checkedRows = this.getCheckedRows(dataAttribute);

        // Calcul du total global
        const globalTotal = this.sumValues(allRows, dataAttribute);

        // Calcul du total de la sélection
        const selectionTotal = this.sumValues(checkedRows, dataAttribute);
        
        // Mise à jour de l'affichage
        this.globalTotalTarget.innerHTML = this.formatCurrency(globalTotal);
        this.selectionTotalTarget.innerHTML = this.formatCurrency(selectionTotal);
    }

    getCheckedRows(dataAttribute) {
        // Sélectionne uniquement les lignes cochées qui ont l'attribut de données nécessaire
        return Array.from(document.querySelectorAll('input[type="checkbox"]:checked'))
            .map(checkbox => checkbox.closest(`tr[${dataAttribute}]`))
            .filter(row => row !== null); // Filtre pour ne garder que les <tr> de la liste
    }

    sumValues(elements, dataAttribute) {
        return Array.from(elements).reduce((sum, el) => {
            const value = parseInt(el.getAttribute(dataAttribute), 10) || 0;
            return sum + value;
        }, 0);
    }
    
    formatCurrency(valueInCents) {
        const amount = valueInCents / 100;
        return new Intl.NumberFormat('fr-FR', {
            style: 'currency',
            currency: 'EUR',
        }).format(amount);
    }
    
    camelToKebab(str) {
        return str.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
    }
}