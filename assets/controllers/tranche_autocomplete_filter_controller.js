import { Controller } from '@hotwired/stimulus';

/*
 * Contrôleur JS Brokers (Symfony 7.1)
 * Gère la dépendance Revenu -> Tranche et le CALCUL AUTOMATIQUE du montant.
 */
export default class extends Controller {
    static targets = ["formRow"];

    connect() {
        // 1. Identification des champs exacts pour la ligne de collection
        const baseName = this.element.name;
        const revenuName = baseName.replace(/tranche/, 'revenuFacture');
        const quantiteName = baseName.replace(/tranche/, 'quantite');
        const montantName = baseName.replace(/tranche/, 'montant');
        
        this.form = this.element.closest('form');
        this.revenuSelect = this.form.querySelector(`select[name="${revenuName}"]`);
        this.quantiteInput = this.form.querySelector(`input[name="${quantiteName}"]`);
        this.montantInput = this.form.querySelector(`input[name="${montantName}"]`);

        // 2. ÉCOUTEURS D'ÉVÉNEMENTS 
        if (this.revenuSelect) {
            this.revenuSelect.addEventListener('change', () => {
                this.handleRevenuChange();
                this.calculateTotal(); 
            });
            setTimeout(() => this.handleRevenuChange(), 50);
        } 

        if (this.quantiteInput) {
            this.quantiteInput.addEventListener('input', () => this.calculateTotal()); 
        }

        this.element.addEventListener('change', () => {
            this.handleTrancheChange(); 
            this.calculateTotal();
        });

        // Sécurité pour la mise à jour de l'URL AJAX
        this.element.addEventListener('focus', () => this.updateUrl());
        this.element.addEventListener('mouseenter', () => this.updateUrl());

        setTimeout(() => { 
            this.handleTrancheChange(); 
            this.handleRevenuChange();
            this.calculateTotal(); 
        }, 60);
    }

    handleRevenuChange() {
        if (!this.revenuSelect) return;

        const revenuId = this.revenuSelect.value;
        const hasRevenu = (revenuId && revenuId !== '');

        // Gestion de l'affichage dynamique
        let targetRow = null;
        if (this.hasFormRowTarget) {
            targetRow = this.formRowTarget;
        } else {
            targetRow = this.element.closest('.mb-3'); 
        }

        if (targetRow) {
            const wasHidden = targetRow.classList.contains('d-none');
            targetRow.classList.toggle('d-none', !hasRevenu);

            if (wasHidden && hasRevenu) {
                setTimeout(() => {
                    window.dispatchEvent(new Event('resize'));
                    if (this.element.tomselect) this.element.tomselect.sync(); 
                }, 10);
            }
        }

        if (this.element.tomselect) {
            this.element.tomselect.clear(true); 
            this.element.tomselect.clearOptions(); 
        } else {
            this.element.value = '';
        }

        this.updateUrl();
    }

    handleTrancheChange() {
        const hasTranche = (this.element.value && this.element.value !== '');
        const dependentRows = this.form.querySelectorAll('.montant-form-row, .quantite-form-row');
        
        dependentRows.forEach(row => {
            const wasHidden = row.classList.contains('d-none');
            row.classList.toggle('d-none', !hasTranche);
            if (wasHidden && hasTranche) {
                setTimeout(() => window.dispatchEvent(new Event('resize')), 10);
            }
        });
    }

    /**
     * Formule : Montant = Montant TTC du Revenu * Quantité * Taux de la Tranche
     */
    calculateTotal() {
        console.log('%c--- Début Calcul Montant ---', 'color: #0d6efd; font-weight: bold;');

        if (!this.quantiteInput || !this.montantInput) {
            console.error('[ERREUR] Champs Quantité ou Montant introuvables.');
            return;
        }

        const qty = parseFloat(this.quantiteInput.value) || 0;
        let tauxTranche = 0;
        let montantTtcRevenu = 0;

        // 1. Récupérer le Montant TTC depuis le champ Revenu (la seule source fiable)
        if (this.revenuSelect && this.revenuSelect.tomselect) {
            const revenuId = this.revenuSelect.value;
            const revenuOption = this.revenuSelect.tomselect.options[revenuId];
            if (revenuOption) {
                montantTtcRevenu = parseFloat(revenuOption.montantTtc || 0);
            }
        }

        // 2. Récupérer le Taux depuis le champ Tranche
        if (this.element.tomselect) {
            const trancheId = this.element.value;
            const trancheOption = this.element.tomselect.options[trancheId];
            if (trancheOption) {
                // La valeur 'tauxTranche' est déjà un décimal (ex: 0.5) grâce à la stratégie PHP.
                // Il ne faut PAS la re-diviser par 100.
                tauxTranche = parseFloat(trancheOption.taux || 0);
            }
        }

        console.log(`Données de calcul : Montant Revenu TTC = ${montantTtcRevenu}, Quantité = ${qty}, Taux Tranche = ${tauxTranche}`);

        const total = montantTtcRevenu * qty * tauxTranche;
        console.log(`Résultat : ${montantTtcRevenu} * ${qty} * ${tauxTranche} = ${total.toFixed(2)}`);
        this.montantInput.value = total.toFixed(2);
        console.log('%c--- Fin Calcul Montant ---', 'color: #0d6efd; font-weight: bold;');
    }

    updateUrl() {
        if (!this.revenuSelect) return;
        
        const revenuId = this.revenuSelect.value;
        let urlAttr = this.element.hasAttribute('data-symfony--ux-autocomplete--autocomplete-url-value') 
            ? 'data-symfony--ux-autocomplete--autocomplete-url-value' 
            : (this.element.hasAttribute('data-autocomplete-url-value') ? 'data-autocomplete-url-value' : null);

        if (!urlAttr) return;

        if (!this.baseUrl) {
            this.baseUrl = this.element.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            if (revenuId && revenuId !== '') {
                url.searchParams.set('live_revenu_id', revenuId);
            } else {
                url.searchParams.delete('live_revenu_id');
            }
            this.element.setAttribute(urlAttr, url.toString());
        } catch (e) {
            console.error("[TrancheAutocomplete DEBUG] Erreur de mise à jour de l'URL:", e);
        }
    }
}