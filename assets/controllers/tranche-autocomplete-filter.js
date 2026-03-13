import { Controller } from '@hotwired/stimulus';

/*
 * Ce contrôleur Stimulus met à jour l'URL d'appel AJAX du champ Tranche
 * en y injectant l'ID du Revenu sélectionné juste au-dessus.
 * Compatible 100% Asset Mapper.
 */
export default class extends Controller {
    connect() {
        // 1. On cherche le champ "Revenu"
        // Comme défini dans tes formulaires, il s'appelle 'revenuFacture'
        this.revenuSelect = document.querySelector('select[name="revenuFacture"]');
        
        // 2. On identifie le conteneur du champ Tranche (pour modifier l'URL)
        this.autocompleteWrapper = this.element.closest('[data-controller*="autocomplete"]');

        if (this.revenuSelect) {
            // On écoute chaque changement sur le Revenu
            this.revenuSelect.addEventListener('change', () => this.updateAutocompleteUrl());
            
            // On exécute au chargement pour gérer l'état initial (mode édition)
            setTimeout(() => this.updateAutocompleteUrl(), 100);
        }

        // Sécurité supplémentaire : mise à jour au focus
        this.element.addEventListener('focus', () => this.updateAutocompleteUrl());
        this.element.addEventListener('mouseenter', () => this.updateAutocompleteUrl());
    }

    updateAutocompleteUrl() {
        if (!this.autocompleteWrapper || !this.revenuSelect) return;

        const revenuId = this.revenuSelect.value;

        // Détection de l'attribut URL selon la version de Symfony UX
        let urlAttr = this.autocompleteWrapper.hasAttribute('data-symfony--ux-autocomplete--autocomplete-url-value') 
            ? 'data-symfony--ux-autocomplete--autocomplete-url-value' 
            : (this.autocompleteWrapper.hasAttribute('data-autocomplete-url-value') ? 'data-autocomplete-url-value' : null);

        if (!urlAttr) return;

        // Mémoriser l'URL de base
        if (!this.baseUrl) {
            this.baseUrl = this.autocompleteWrapper.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            
            if (revenuId && revenuId !== '') {
                // On injecte l'ID du revenu "en direct" pour le filtre côté PHP
                url.searchParams.set('live_revenu_id', revenuId);
            } else {
                url.searchParams.delete('live_revenu_id');
            }
            
            this.autocompleteWrapper.setAttribute(urlAttr, url.toString());
        } catch (e) {
            console.error("[TrancheAutocomplete DEBUG] Erreur URL:", e);
        }
    }
}