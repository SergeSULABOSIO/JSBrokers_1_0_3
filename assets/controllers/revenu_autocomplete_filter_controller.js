import { Controller } from '@hotwired/stimulus';

/*
 * Ce contrôleur Stimulus minimaliste prend l'ID de la Note
 * fourni par Symfony (ArticleType) et l'ajoute à la requête AJAX interne
 * de Symfony UX Autocomplete.
 */
export default class extends Controller {
    static values = {
        noteId: String
    }

    connect() {
        if (!this.noteIdValue) return; // Si l'ID de la note n'est pas défini, on ne fait rien

        // On récupère l'URL d'API générée par Symfony UX Autocomplete
        const originalUrl = this.element.getAttribute('data-autocomplete-url-value');
        
        if (originalUrl) {
            const url = new URL(originalUrl, window.location.origin);
            
            // On ajoute notre paramètre "note_id" à cette URL
            url.searchParams.set('note_id', this.noteIdValue);
            
            // On met à jour l'attribut. UX Autocomplete l'utilisera pour sa requête AJAX !
            this.element.setAttribute('data-autocomplete-url-value', url.toString());
        }
    }
}