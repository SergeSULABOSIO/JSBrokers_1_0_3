import { Controller } from '@hotwired/stimulus';

/**
 * @class AssistantIaParametresController
 * @description Rubrique « Paramètres IA » (col-3) : soumission AJAX du
 * formulaire de nommage de l'assistant (un POST classique naviguerait hors de
 * l'espace de travail). Feedback pendant l'enregistrement : barre de
 * progression globale du workspace + état occupé du bouton (spinner,
 * « Enregistrement… », aria-busy). Le serveur re-rend le composant complet
 * (feedback succès/erreur inclus) et l'élément est remplacé — Stimulus
 * reconnecte.
 */
export default class extends Controller {
    static targets = ['form', 'submit'];

    static values = {
        url: String,
    };

    async save(event) {
        event.preventDefault();
        if (this.saving) return;
        this.saving = true;
        this._setBusy(true);
        document.dispatchEvent(new CustomEvent('app:loading.start'));

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
                this.element.replaceWith(fresh); // bouton re-rendu à l'état repos
            }
        } catch (error) {
            console.error('AssistantIaParametres - enregistrement échoué :', error);
            this._setBusy(false);
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            this.saving = false;
        }
    }

    /** État occupé du bouton Enregistrer (feedback local). */
    _setBusy(busy) {
        if (!this.hasSubmitTarget) return;
        const button = this.submitTarget;
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.querySelector('[data-role="spinner"]').style.display = busy ? 'inline-block' : 'none';
        button.querySelector('[data-role="icon"]').style.display = busy ? 'none' : 'inline-flex';
        button.querySelector('[data-role="label"]').textContent = busy ? 'Enregistrement…' : 'Enregistrer';
    }
}
