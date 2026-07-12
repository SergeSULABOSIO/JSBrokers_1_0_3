import { Controller } from '@hotwired/stimulus';

/**
 * @class FormSubmitController
 * @extends Controller
 * @description Donne un retour visuel sur le bouton de soumission d'un formulaire
 * (formulaire classique, non-AJAX) : pendant que la requête part vers le serveur,
 * le bouton se met en état « occupé » (spinner + libellé d'attente) et se désactive.
 *
 * Objectifs ergonomie / accessibilité :
 *  - Visibilité de l'état du système (Nielsen #1) : l'utilisateur voit qu'une action
 *    est en cours et comprend qu'il doit patienter.
 *  - Prévention des erreurs (Nielsen #5) : empêche la double-soumission (double-clic).
 *  - `aria-busy="true"` informe les technologies d'assistance de l'état transitoire.
 *
 * Markup attendu :
 *   <form data-controller="form-submit"
 *         data-action="submit->form-submit#submitting"
 *         data-form-submit-loading-text-value="Connexion en cours…">
 *     …
 *     <button type="submit" data-form-submit-target="button">Se connecter</button>
 *   </form>
 */
export default class extends Controller {
    static targets = ['button'];
    static values = {
        loadingText: { type: String, default: 'Veuillez patienter…' },
    };

    connect() {
        // Fin de l'attente : que la soumission ait réussi ou échoué, l'issue est une
        // navigation complète — `pageshow` couvre le chargement de la page suivante
        // ET la restauration depuis le bfcache (retour arrière), où le DOM occupé
        // (spinner, bouton désactivé) serait sinon figé tel quel.
        this.boundReset = this.reset.bind(this);
        window.addEventListener('pageshow', this.boundReset);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.boundReset);
    }

    /**
     * Rétablit l'état de repos : bouton de soumission et liens repassés en état
     * actif avec leur libellé d'origine, garde anti double-soumission levée.
     */
    reset() {
        this.busy = false;

        if (this.hasButtonTarget) {
            const button = this.buttonTarget;
            if (button.dataset.originalLabel !== undefined) {
                button.innerHTML = button.dataset.originalLabel;
            }
            button.removeAttribute('aria-busy');
            button.disabled = false;
        }

        // Liens passés en état occupé via navigating().
        this.element.querySelectorAll('a[aria-busy="true"]').forEach((link) => {
            if (link.dataset.originalLabel !== undefined) {
                link.innerHTML = link.dataset.originalLabel;
            }
            link.removeAttribute('aria-busy');
            link.classList.remove('disabled');
            link.style.pointerEvents = '';
        });
    }

    /**
     * Retour visuel sur un lien de navigation (ex. « Annuler ») : au clic, le lien
     * passe en état occupé (spinner + libellé) le temps que la nouvelle page charge.
     * On ne fait PAS de preventDefault : la navigation par défaut suit son cours.
     * Le libellé d'attente est lu sur l'attribut `data-loading-text` du lien.
     */
    navigating(event) {
        const link = event.currentTarget;

        // Si une soumission est déjà en cours, on neutralise la navigation concurrente.
        if (this.busy) {
            event.preventDefault();
            return;
        }

        const text = link.dataset.loadingText || this.loadingTextValue;

        if (link.dataset.originalLabel === undefined) {
            link.dataset.originalLabel = link.innerHTML;
        }

        link.setAttribute('aria-busy', 'true');
        link.classList.add('disabled');
        link.style.pointerEvents = 'none';
        link.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
            + this.escape(text);
    }

    submitting(event) {
        if (!this.hasButtonTarget) {
            return;
        }

        // Garde anti double-soumission : une seconde soumission est annulée.
        if (this.busy) {
            event.preventDefault();
            return;
        }
        this.busy = true;

        const button = this.buttonTarget;

        // On mémorise le libellé d'origine (utile si la navigation est annulée, ex. retour arrière bfcache).
        if (button.dataset.originalLabel === undefined) {
            button.dataset.originalLabel = button.innerHTML;
        }

        button.setAttribute('aria-busy', 'true');
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>'
            + this.escape(this.loadingTextValue);

        // On désactive APRÈS le déclenchement de la soumission (au tick suivant) :
        // désactiver en plein événement `submit` peut, sur certains navigateurs,
        // annuler l'envoi du formulaire.
        window.requestAnimationFrame(() => {
            button.disabled = true;
        });
    }

    escape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
