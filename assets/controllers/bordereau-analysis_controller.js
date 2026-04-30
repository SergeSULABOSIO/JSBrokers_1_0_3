import { Controller } from '@hotwired/stimulus';

/**
 * @class BordereauAnalysisController
 * @description Gère l'interface de l'analyse de bordereau en deux étapes.
 * Etape 1: Sélection de la feuille Excel.
 * Etape 2: Mappage des colonnes de la feuille sélectionnée.
 */
export default class extends Controller {
    static targets = ["sheetSelection", "step2", "mappingContainer"];

    connect() {
        // Si une seule feuille est détectée, on passe directement à l'étape 2.
        if (this.sheetSelectionTargets.length === 1) {
            this.showStep2();
        }
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
        this.step2Target.classList.remove('d-none');

        this.mappingContainerTargets.forEach(container => {
            const isTargetSheet = sheetName ? container.dataset.sheetName === sheetName : container.dataset.isFirst === 'true';
            container.style.display = isTargetSheet ? 'block' : 'none';
        });
    }
}