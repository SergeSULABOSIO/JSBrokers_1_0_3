// assets/controllers/totals-bar_controller.js
import { Controller } from '@hotwired/stimulus';
import { EVEN_CHECKBOX_PUBLISH_SELECTION, EVEN_LISTE_ELEMENT_CHECKED, EVEN_LISTE_PRINCIPALE_ALL_CHECKED, EVEN_LISTE_PRINCIPALE_REFRESHED } from './base_controller.js';


export default class extends Controller {
    static targets = ["globalTotal", "selectionTotal", "attributeSelector"];

    connect() {
        this.nomControleur = "TOTALS-BAR";
        // Écoute les événements déclenchés par la liste des ventes
        this.boundRecalculate = this.recalculate.bind(this);
        document.addEventListener(EVEN_LISTE_PRINCIPALE_REFRESHED, this.boundRecalculate);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundRecalculate);
        this.recalculate();
    }
    
    disconnect() {
        // Nettoyage des écouteurs pour éviter les fuites de mémoire
        document.removeEventListener(EVEN_LISTE_PRINCIPALE_REFRESHED, this.boundRecalculate);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundRecalculate);
    }

    recalculate() {
        const attribute = this.attributeSelectorTarget.value; // 'amountInvoiced', 'amountPaid', etc.
        const dataAttribute = `data-${this.camelToKebab(attribute)}-value`; // 'data-amount-invoiced-value'

        const allRows = document.querySelectorAll(`tr[${dataAttribute}]`);
        const checkedRows = this.getCheckedRows(dataAttribute);

        // console.log(this.nomControleur + " - recalculate", allRows, checkedRows);

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
            currency: 'USD',
        }).format(amount);
    }
    
    camelToKebab(str) {
        return str.replace(/([a-z0-9]|(?=[A-Z]))([A-Z])/g, '$1-$2').toLowerCase();
    }
}