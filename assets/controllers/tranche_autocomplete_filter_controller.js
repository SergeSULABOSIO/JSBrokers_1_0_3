import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur Stimulus pour la cascade d'affichage et le calcul automatique.
 * Relation : Revenu -> Tranche -> (Quantité & Montant).
 * Formule : Montant Total = Quantité * Prix Unitaire.
 */
export default class extends Controller {
    // Prix unitaire mémorisé pour recalculer le total lors du changement de quantité
    unitPrice = 0;

    connect() {
        // Identification des champs par rapport au nom dynamique (gestion des collections [0], [1]...)
        const baseName = this.element.name; 
        const revenuName = baseName.replace(/tranche/, 'revenuFacture');
        const quantiteName = baseName.replace(/tranche/, 'quantite');
        const montantName = baseName.replace(/tranche/, 'montant');

        this.form = this.element.closest('form');
        this.revenuSelect = this.form.querySelector(`select[name="${revenuName}"]`);
        this.quantiteInput = this.form.querySelector(`input[name="${quantiteName}"]`);
        this.montantInput = this.form.querySelector(`input[name="${montantName}"]`);

        if (this.revenuSelect) {
            this.revenuSelect.addEventListener('change', () => this.handleRevenuChange());
        }

        // Initialisation des données si on charge un article existant
        this.initializeData();

        setTimeout(() => {
            this.handleRevenuChange();
            this.handleTrancheChange();
        }, 60);

        this.element.addEventListener('focus', () => this.updateUrl());
    }

    /**
     * Initialise le prix unitaire basé sur les valeurs actuelles
     */
    initializeData() {
        if (this.montantInput && this.quantiteInput) {
            const montant = parseFloat(this.montantInput.value) || 0;
            const qty = parseFloat(this.quantiteInput.value) || 1;
            if (qty > 0) {
                this.unitPrice = montant / qty;
            }
        }
    }

    handleRevenuChange() {
        if (!this.revenuSelect) return;

        const hasRevenu = (this.revenuSelect.value && this.revenuSelect.value !== '');
        const trancheRow = this.element.closest('.tranche-form-row');

        if (trancheRow) {
            const wasHidden = trancheRow.classList.contains('d-none');
            trancheRow.classList.toggle('d-none', !hasRevenu);
            if (wasHidden && hasRevenu) this.forceRepaint();
        }

        if (!hasRevenu) this.clearTranche();
        this.updateUrl();
    }

    handleTrancheChange() {
        const hasTranche = (this.element.value && this.element.value !== '');
        const dependentRows = this.form.querySelectorAll('.montant-form-row, .quantite-form-row');

        dependentRows.forEach(row => {
            const wasHidden = row.classList.contains('d-none');
            row.classList.toggle('d-none', !hasTranche);
            if (wasHidden && hasTranche) this.forceRepaint();
        });

        // Si une tranche est sélectionnée, on synchronise le prix unitaire
        if (hasTranche) {
            this.initializeData();
        }
    }

    /**
     * Calcule le Montant Total : Qté * Prix Unitaire
     */
    calculateTotal() {
        if (!this.quantiteInput || !this.montantInput) return;
        const qty = parseFloat(this.quantiteInput.value) || 0;
        const total = qty * this.unitPrice;
        this.montantInput.value = total.toFixed(2);
    }

    /**
     * Si l'utilisateur modifie le montant global manuellement, 
     * on met à jour le prix unitaire pour les futurs changements de quantité.
     */
    updateUnitPrice() {
        if (!this.quantiteInput || !this.montantInput) return;
        const qty = parseFloat(this.quantiteInput.value) || 1;
        const montant = parseFloat(this.montantInput.value) || 0;
        if (qty > 0) {
            this.unitPrice = montant / qty;
        }
    }

    clearTranche() {
        if (this.element.tomselect) {
            this.element.tomselect.clear(true);
            this.element.tomselect.clearOptions();
        } else {
            this.element.value = '';
        }
        this.handleTrancheChange();
    }

    forceRepaint() {
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            if (this.element.tomselect) this.element.tomselect.sync();
        }, 20);
    }

    updateUrl() {
        if (!this.revenuSelect) return;
        const revenuId = this.revenuSelect.value;
        let urlAttr = this.element.hasAttribute('data-symfony--ux-autocomplete--autocomplete-url-value') 
            ? 'data-symfony--ux-autocomplete--autocomplete-url-value' 
            : 'data-autocomplete-url-value';

        if (!this.baseUrl) {
            this.baseUrl = this.element.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            if (revenuId) url.searchParams.set('live_revenu_id', revenuId);
            else url.searchParams.delete('live_revenu_id');
            this.element.setAttribute(urlAttr, url.toString());
        } catch (e) {}
    }
}