import { Controller } from '@hotwired/stimulus';

/*
 * Ce contrôleur Stimulus met à jour l'URL d'appel AJAX du champ Tranche
 * en y injectant l'ID du Revenu sélectionné juste au-dessus.
 */
export default class extends Controller {
    connect() {
        // 1. Trouver le formulaire Article parent pour scoper la recherche (évite les conflits entre plusieurs articles)
        this.articleForm = this.element.closest('[data-controller~="conditional-article-fields"]');
        
        if (this.articleForm) {
            // 2. Trouver le select Revenu spécifique à CET article (gère le format collection)
            this.revenuSelect = this.articleForm.querySelector('select[name*="[revenuFacture]"]');
        }
        
        // 3. On identifie le conteneur du champ Tranche pour modifier l'URL AJAX
        this.autocompleteWrapper = this.element.closest('[data-controller*="autocomplete"]');

        if (this.revenuSelect) {
            // On écoute chaque changement sur le Revenu pour actualiser l'URL de la tranche
            this.revenuSelect.addEventListener('change', () => this.updateAutocompleteUrl());
            // On exécute au chargement pour gérer l'état initial
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

        // Mémoriser l'URL de base la première fois
        if (!this.baseUrl) {
            this.baseUrl = this.autocompleteWrapper.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            
            if (revenuId && revenuId !== '') {
                // On injecte l'ID du revenu "en direct" pour le filtre PHP
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