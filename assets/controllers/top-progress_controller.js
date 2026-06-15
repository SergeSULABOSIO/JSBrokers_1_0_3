import { Controller } from '@hotwired/stimulus';

/**
 * @class TopProgressController
 * @extends Controller
 * @description Affiche une barre de progression globale fixée en haut de la page
 * dès qu'une action de navigation ou de soumission est déclenchée. Reprend le
 * pattern de l'espace de travail (barre cobalt animée, `app:loading.start`).
 *
 * Comme les pages concernées font des navigations complètes (non-AJAX), la barre
 * reste affichée jusqu'au chargement de la page suivante, qui la réinitialise.
 *
 * Objectifs ergonomie (Nielsen #1 — visibilité de l'état du système) :
 *  - l'utilisateur voit immédiatement qu'une action est prise en compte.
 *
 * Markup attendu :
 *   <main data-controller="top-progress">
 *     <div class="ent-progress-bar" data-top-progress-target="bar" role="progressbar" …></div>
 *     <a … data-action="click->top-progress#show">…</a>
 *     <form … data-action="submit->top-progress#show">…</form>
 *   </main>
 */
export default class extends Controller {
    static targets = ['bar'];

    connect() {
        // Cohérence avec l'espace de travail : on réagit aussi à l'événement global.
        this.boundShow = this.show.bind(this);
        document.addEventListener('app:loading.start', this.boundShow);

        // Si la page est restaurée depuis le cache (retour arrière), on masque la barre.
        this.boundReset = this.reset.bind(this);
        window.addEventListener('pageshow', this.boundReset);
    }

    disconnect() {
        document.removeEventListener('app:loading.start', this.boundShow);
        window.removeEventListener('pageshow', this.boundReset);
    }

    show() {
        if (this.hasBarTarget) {
            this.barTarget.style.display = 'block';
        }
    }

    reset() {
        if (this.hasBarTarget) {
            this.barTarget.style.display = 'none';
        }
    }
}
