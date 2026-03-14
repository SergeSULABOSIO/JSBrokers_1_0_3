import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur pour la gestion de l'Article
 * 1. Filtre les tranches en fonction du Revenu (AJAX).
 * 2. Affiche les champs en cascade : Revenu -> Tranche -> (Quantité, Montant, Taxe).
 */
export default class extends Controller {

    connect() {
        // Identification du champ Revenu lié dans la même ligne de formulaire
        const revenuName = this.element.name.replace(/tranche/, 'revenuFacture');
        this.form = this.element.closest('form');
        this.revenuSelect = this.form.querySelector(`select[name="${revenuName}"]`);

        // On écoute les changements sur le Revenu pour piloter la Tranche
        if (this.revenuSelect) {
            this.revenuSelect.addEventListener('change', () => this.handleRevenuChange());
        }

        // On écoute les changements sur la Tranche pour piloter les champs finaux
        this.element.addEventListener('change', () => this.handleTrancheChange());

        // Initialisation immédiate (avec un léger délai pour TomSelect)
        setTimeout(() => {
            this.handleRevenuChange();
            this.handleTrancheChange();
        }, 50);

        this.element.addEventListener('focus', () => this.updateUrl());
    }

    /**
     * Gère la visibilité de la Tranche selon le choix du Revenu
     */
    handleRevenuChange() {
        if (!this.revenuSelect) return;

        const revenuId = this.revenuSelect.value;
        const hasRevenu = (revenuId && revenuId !== '');

        const trancheRow = this.element.closest('.tranche-form-row');
        if (trancheRow) {
            const wasHidden = trancheRow.classList.contains('d-none');
            trancheRow.classList.toggle('d-none', !hasRevenu);

            // Si le champ apparaît, on force le recalcul de TomSelect
            if (wasHidden && hasRevenu) {
                this.forceRepaint();
            }
        }

        // Si on vide le revenu, on doit vider et masquer ce qui suit
        if (!hasRevenu) {
            this.clearTranche();
        }
        
        this.updateUrl();
    }

    /**
     * Gère la visibilité de Quantité, Montant et Taxe selon la Tranche
     */
    handleTrancheChange() {
        const trancheId = this.element.value;
        const hasTranche = (trancheId && trancheId !== '');

        // Ciblage des parents dépendants via les classes CSS injectées par le PHP
        const dependentRows = this.form.querySelectorAll('.montant-form-row, .quantite-form-row, .taxe-form-row');
        
        dependentRows.forEach(row => {
            const wasHidden = row.classList.contains('d-none');
            row.classList.toggle('d-none', !hasTranche);
            
            if (wasHidden && hasTranche) {
                this.forceRepaint();
            }
        });
    }

    /**
     * Réinitialise le champ Tranche (TomSelect ou Standard)
     */
    clearTranche() {
        if (this.element.tomselect) {
            this.element.tomselect.clear(true);
            this.element.tomselect.clearOptions(); 
        } else {
            this.element.value = '';
        }
        // Propagation pour masquer les champs suivants
        this.handleTrancheChange();
    }

    /**
     * Force TomSelect à relire sa largeur (évite le bug du 0px quand caché)
     */
    forceRepaint() {
        setTimeout(() => {
            window.dispatchEvent(new Event('resize'));
            if (this.element.tomselect) {
                this.element.tomselect.sync();
            }
        }, 15);
    }

    /**
     * Met à jour l'URL AJAX pour filtrer les tranches par Revenu
     */
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
            if (revenuId) {
                url.searchParams.set('live_revenu_id', revenuId);
            } else {
                url.searchParams.delete('live_revenu_id');
            }
            this.element.setAttribute(urlAttr, url.toString());
        } catch (e) {
            console.error("[Tranche DEBUG] Erreur URL:", e);
        }
    }
}