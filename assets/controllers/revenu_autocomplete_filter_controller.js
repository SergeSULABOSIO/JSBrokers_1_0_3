import { Controller } from '@hotwired/stimulus';

/*
 * Ce contrôleur Stimulus met à jour l'URL d'appel AJAX de Symfony UX Autocomplete
 * en y injectant l'ID de la note ou le destinataire sélectionné en direct sur l'interface.
 */
export default class extends Controller {
    static values = {
        noteId: String
    }

    connect() {
        // Trouver le conteneur parent généré par Symfony UX
        this.wrapper = this.element.closest('[data-controller*="autocomplete"]');
        
        if (!this.wrapper) {
            console.warn("[RevenuAutocomplete DEBUG] Le wrapper UX Autocomplete est introuvable !");
            return;
        }

        // On met à jour l'URL immédiatement
        this.updateAutocompleteUrl();

        // On intercepte les interactions utilisateur pour être sûr que l'URL est à jour
        this.element.addEventListener('focus', () => this.updateAutocompleteUrl());
        this.element.addEventListener('mouseenter', () => this.updateAutocompleteUrl());
    }

    updateAutocompleteUrl() {
        if (!this.wrapper) return;

        // Détecter l'attribut exact utilisé selon la version de Symfony UX
        let urlAttr = null;
        if (this.wrapper.hasAttribute('data-symfony--ux-autocomplete--autocomplete-url-value')) {
            urlAttr = 'data-symfony--ux-autocomplete--autocomplete-url-value';
        } else if (this.wrapper.hasAttribute('data-autocomplete-url-value')) {
            urlAttr = 'data-autocomplete-url-value';
        }

        if (!urlAttr) return;

        // Mémoriser l'URL de base la première fois
        if (!this.baseUrl) {
            this.baseUrl = this.wrapper.getAttribute(urlAttr);
        }

        try {
            const url = new URL(this.baseUrl, window.location.origin);
            
            // 1. Priorité aux données persistées (ID de la note envoyé depuis PHP)
            if (this.noteIdValue) {
                url.searchParams.set('note_id', this.noteIdValue);
            }

            // 2. OVERRIDE LIVE : Lecture en direct du formulaire parent
            const addressedToInput = document.querySelector('input[type="radio"][name*="[addressedTo]"]:checked') || document.querySelector('input[type="radio"][name="addressedTo"]:checked');
            
            if (addressedToInput) {
                const addressedTo = parseInt(addressedToInput.value);
                
                if (addressedTo === 1) { // 1 = L'assureur
                    const assureurSelect = document.querySelector('select[name*="[assureur]"]') || document.querySelector('select[name="assureur"]');
                    if (assureurSelect && assureurSelect.value) {
                        url.searchParams.set('live_assureur_id', assureurSelect.value);
                    }
                } else if (addressedTo === 0) { // 0 = Le client
                    const clientSelect = document.querySelector('select[name*="[client]"]') || document.querySelector('select[name="client"]');
                    if (clientSelect && clientSelect.value) {
                        url.searchParams.set('live_client_id', clientSelect.value);
                    }
                } else if (addressedTo === 2) { // 2 = L'intermédiaire (Partenaire)
                    const partenaireSelect = document.querySelector('select[name*="[partenaire]"]') || document.querySelector('select[name="partenaire"]');
                    if (partenaireSelect && partenaireSelect.value) {
                        url.searchParams.set('live_partenaire_id', partenaireSelect.value);
                    }
                } else if (addressedTo === 3) { // 3 = L'autorité fiscale
                    const autoriteSelect = document.querySelector('select[name*="[autoritefiscale]"]') || document.querySelector('select[name="autoritefiscale"]');
                    if (autoriteSelect && autoriteSelect.value) {
                        url.searchParams.set('live_autorite_id', autoriteSelect.value);
                    }
                }
            }

            // On écrase l'attribut sur le wrapper parent
            this.wrapper.setAttribute(urlAttr, url.toString());
        } catch (e) {
            console.error("[RevenuAutocomplete DEBUG] Erreur lors du formatage de l'URL:", e);
        }
    }
}