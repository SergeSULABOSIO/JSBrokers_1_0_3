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
        console.log(`${this.nomControleur} - Connecté.`);

        // --- MODIFICATION ---
        // On initialise les variables qui contiendront les données en mémoire.
        this.numericData = {}; // contiendra { id1: { attr1: {..}, attr2: {..} }, id2: ... }
        this.selectedIds = new Set(); // contiendra les IDs des lignes cochées, ex: {12, 25}

        // On lie les nouvelles méthodes.
        this.boundHandleContext = this.handleContextChange.bind(this);
        this.boundHandleSelection = this.handleSelectionChange.bind(this);

        // On change les écouteurs d'événements.
        document.addEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.addEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    disconnect() {
        // Nettoyage des nouveaux écouteurs.
        document.removeEventListener(EVT_CONTEXT_CHANGED, this.boundHandleContext);
        document.removeEventListener(EVEN_CHECKBOX_PUBLISH_SELECTION, this.boundHandleSelection);
    }

    /**
     * --- NOUVEAU ---
     * Méthode 1: Reçoit le contexte et TOUTES les données pré-calculées.
     */
    handleContextChange(event) {
        const { numericAttributes, numericData } = event.detail;
        this.numericData = numericData || {}; // On stocke les données
        this.updateAttributeSelector(numericAttributes || {}); // On met à jour le dropdown
        this.recalculate(); // On recalcule tout
    }

    /**
     * --- NOUVEAU ---
     * Méthode 2: Reçoit la liste des éléments sélectionnés et mémorise juste leurs IDs.
     */
    handleSelectionChange(event) {
        const selectedEntities = event.detail.selection || [];
        // On utilise un Set pour des recherches rapides (plus performant qu'un Array).
        this.selectedIds = new Set(selectedEntities.map(e => e.id));
        this.recalculate();
    }


    /**
     * --- NOUVEAU ---
     * Met à jour les options du <select> en se basant sur les attributs fournis.
     */
    updateAttributeSelector(attributes) {
        this.attributeSelectorTarget.innerHTML = '';
        const hasAttributes = Object.keys(attributes).length > 0;
        this.element.style.display = hasAttributes ? 'flex' : 'none'; // Affiche ou cache la barre
        
        for (const [key, label] of Object.entries(attributes)) {
            this.attributeSelectorTarget.appendChild(new Option(label, key));
        }
    }


    /**
     * --- MODIFICATION MAJEURE ---
     * Méthode 3: Recalcule les totaux SANS SCANNER LE DOM.
     * Cette méthode est maintenant ultra-rapide.
     */
    recalculate() {
        const attribute = this.attributeSelectorTarget.value;
        if (!attribute) { // Si pas d'attribut sélectionné (liste vide)
            this.displayTotals(0, 0);
            return;
        }

        let globalTotal = 0;
        let selectionTotal = 0;

        // On itère sur les données en mémoire, pas sur les <tr> du DOM.
        for (const id in this.numericData) {
            const itemData = this.numericData[id];
            if (itemData && itemData[attribute]) {
                const value = itemData[attribute].value; // La valeur en centimes
                globalTotal += value;

                // Si l'ID est dans notre Set de sélection, on l'ajoute au total de la sélection.
                if (this.selectedIds.has(parseInt(id, 10))) {
                    selectionTotal += value;
                }
            }
        }
        this.displayTotals(globalTotal, selectionTotal);
        console.log(`${this.nomControleur} - Total Sélection mis à jour :`, globalTotal, selectionTotal);
    }


    /**
     * --- NOUVEAU ---
     * Méthode dédiée à l'affichage pour garder le code propre.
     */
    displayTotals(globalTotal, selectionTotal) {
        this.globalTotalTarget.textContent = this.formatCurrency(globalTotal);
        this.selectionTotalTarget.textContent = this.formatCurrency(selectionTotal);
    }

    // --- CONSERVATION ---
    // Les méthodes utilitaires restent identiques.
    formatCurrency(valueInCents) {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'USD' }).format(valueInCents / 100);
    }
}