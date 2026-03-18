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
        
        this.form = this.element.closest('form');
        this.revenuSelect = this.form.querySelector(`select[name="${revenuName}"]`);
        this.quantiteInput = this.form.querySelector(`input[name="${quantiteName}"]`);

        // 2. ÉCOUTEURS D'ÉVÉNEMENTS 
        if (this.revenuSelect) {
            this.revenuSelect.addEventListener('change', () => {
                this.handleRevenuChange();
            });
            setTimeout(() => this.handleRevenuChange(), 50);
        } 

        if (this.quantiteInput) {
            // La quantité n'est plus utilisée pour un calcul client-side direct du montant.
        }

        this.element.addEventListener('change', () => {
            this.handleTrancheChange(); // Gère la visibilité de la quantité
        });

        // Sécurité pour la mise à jour de l'URL AJAX
        this.element.addEventListener('focus', () => this.updateUrl());
        this.element.addEventListener('mouseenter', () => this.updateUrl());

        setTimeout(() => { 
            this.handleTrancheChange(); // Pour initialiser la visibilité de la quantité
            this.handleRevenuChange(); // Pour initialiser les options de la tranche
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
        const dependentRows = this.form.querySelectorAll('.quantite-form-row'); // Seule la quantité dépend de la tranche
        
        dependentRows.forEach(row => {
            const wasHidden = row.classList.contains('d-none');
            row.classList.toggle('d-none', !hasTranche);
            if (wasHidden && hasTranche) {
                setTimeout(() => window.dispatchEvent(new Event('resize')), 10);
            }
        });
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