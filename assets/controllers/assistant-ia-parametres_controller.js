import { Controller } from '@hotwired/stimulus';

/**
 * @class AssistantIaParametresController
 * @description Rubrique « Paramètres IA » (col-3) : soumission AJAX du
 * formulaire de nommage de l'assistant (un POST classique naviguerait hors de
 * l'espace de travail). Le serveur re-rend le composant complet (feedback
 * succès/erreur inclus) et l'élément est remplacé — Stimulus reconnecte.
 */
export default class extends Controller {
    static targets = ['form', 'submit'];

    static values = {
        url: String,
    };

    async save(event) {
        event.preventDefault();
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: new FormData(this.formTarget),
            });
            if (!response.ok) {
                throw new Error(`Enregistrement impossible (HTTP ${response.status}).`);
            }
            const html = await response.text();
            const template = document.createElement('template');
            template.innerHTML = html.trim();
            const fresh = template.content.firstElementChild;
            if (fresh) {
                this.element.replaceWith(fresh);
            }
        } catch (error) {
            console.error('AssistantIaParametres - enregistrement échoué :', error);
            if (this.hasSubmitTarget) {
                this.submitTarget.disabled = false;
            }
        }
    }
}
