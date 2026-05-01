import { Controller } from '@hotwired/stimulus';
import { enter, leave } from 'el-transition';
/**
 * @class BordereauAnalysisController
 * @description Gère l'interface de l'analyse de bordereau en deux étapes.
 * Etape 1: Sélection de la feuille Excel.
 * Etape 2: Mappage des colonnes de la feuille sélectionnée.
 */
export default class extends Controller {
    static targets = [
        "sheetSelection", "step2", "mappingContainer",
        "mappingSelect", "analysisResult", "submitButton"
    ];

    static values = {
        sheetsData: Object,
    };

    connect() {
        this.requiredMappings = new Set(['reference_police', 'prime_totale', 'commission_ht', 'taxe_commission']);
        this.validationState = new Map(); // Stocke l'état de validation pour chaque colonne mappée

        // Si une seule feuille est détectée, on passe directement à l'étape 2.
        if (this.sheetSelectionTargets.length === 1) {
            this.showStep2();
        }
        this.updateSubmitButtonState();
    }

    /**
     * Action déclenchée lorsqu'un select de mappage est modifié.
     * @param {Event} event
     */
    validateColumn(event) {
        const selectElement = event.currentTarget;
        this.performValidation(selectElement);
    }

    /**
     * Affiche l'étape 2 (mappage) et sélectionne le bon tableau de mappage
     * en fonction de la feuille choisie à l'étape 1.
     */
    showMappingStep() {
        const selectedSheetInput = this.sheetSelectionTargets.find(radio => radio.checked);
        if (!selectedSheetInput) {
            // Cas de sécurité, ne devrait pas arriver avec un radio button.
            return;
        }
        const selectedSheetName = selectedSheetInput.value;

        this.showStep2(selectedSheetName);
    }

    /**
     * Logique d'affichage de l'étape 2.
     * @param {string} sheetName - Le nom de la feuille à afficher. Si null, la première sera affichée.
     */
    showStep2(sheetName = null) {
        enter(this.step2Target);

        this.mappingContainerTargets.forEach(container => {
            const isTargetSheet = sheetName ? container.dataset.sheetName === sheetName : container.dataset.isFirst === 'true';
            container.style.display = isTargetSheet ? 'block' : 'none';
        });
    }

    /**
     * Effectue la validation pour une colonne donnée.
     * @param {HTMLSelectElement} selectElement
     */
    performValidation(selectElement) {
        const mappingType = selectElement.value;
        const columnLetter = selectElement.dataset.columnLetter;
        const sheetName = selectElement.closest('[data-sheet-name]').dataset.sheetName;
        const resultCell = this.element.querySelector(`[data-result-for="${columnLetter}"][data-sheet-name="${sheetName}"]`);

        // Réinitialise l'état de validation pour cette colonne
        this.validationState.delete(columnLetter);

        if (!mappingType) {
            resultCell.innerHTML = ''; // Vide la cellule de résultat si "Ignorer" est sélectionné
            this.updateSubmitButtonState();
            return;
        }

        const sheetData = this.sheetsDataValue[sheetName];
        if (!sheetData) {
            resultCell.innerHTML = this.getFeedbackHtml('error', 'Données de la feuille introuvables.');
            return;
        }

        const invalidRows = [];
        sheetData.forEach((row, index) => {
            const value = row[columnLetter];
            let isValid = false;

            if (mappingType === 'reference_police') {
                isValid = typeof value === 'string' && value.trim() !== '';
            } else { // prime_totale, commission_ht, taxe_commission
                if (value === null || value === undefined || String(value).trim() === '') {
                    isValid = false;
                } else {
                    const cleanedValue = String(value)
                        .replace(/\s/g, '')       // Supprime les espaces
                        .replace(/[^\d,.-]/g, '') // Supprime les symboles monétaires etc.
                        .replace(',', '.');       // Remplace la virgule par un point
                    isValid = !isNaN(parseFloat(cleanedValue)) && isFinite(cleanedValue);
                }
            }

            if (!isValid) {
                invalidRows.push(index + 2); // +2 car l'index est 0-based et on saute la ligne d'en-tête
            }
        });

        if (invalidRows.length === 0) {
            resultCell.innerHTML = this.getFeedbackHtml('success', 'Données valides !');
            this.validationState.set(columnLetter, true);
        } else {
            const message = `Lignes invalides: ${invalidRows.slice(0, 5).join(', ')}${invalidRows.length > 5 ? '...' : ''}`;
            resultCell.innerHTML = this.getFeedbackHtml('error', message);
            this.validationState.set(columnLetter, false);
        }

        this.updateSubmitButtonState();
    }

    /**
     * Met à jour l'état (activé/désactivé) du bouton de soumission.
     */
    updateSubmitButtonState() {
        const activeForm = this.element.querySelector('.column-mapping-form:not([style*="display: none"])');
        if (!activeForm) {
            this.submitButtonTarget.disabled = true;
            return;
        }

        const selects = activeForm.querySelectorAll('select[data-column-letter]');
        const mappedTypes = new Set();
        let allValid = true;

        selects.forEach(select => {
            const mappingType = select.value;
            if (mappingType) {
                mappedTypes.add(mappingType);
                const columnLetter = select.dataset.columnLetter;
                if (this.validationState.get(columnLetter) === false) {
                    allValid = false;
                }
            }
        });

        const hasAllRequired = [...this.requiredMappings].every(type => mappedTypes.has(type));
        this.submitButtonTarget.disabled = !(hasAllRequired && allValid);
    }

    /**
     * Génère le HTML pour un message de feedback.
     * @param {'success'|'error'} type
     * @param {string} message
     * @returns {string}
     */
    getFeedbackHtml(type, message) {
        const icon = type === 'success'
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-check-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0m-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill me-1" viewBox="0 0 16 16"><path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/></svg>';

        return `
            <span class="d-inline-flex align-items-center text-${type === 'success' ? 'success' : 'danger'} small">
                ${icon}
                ${message}
            </span>
        `;
    }
}