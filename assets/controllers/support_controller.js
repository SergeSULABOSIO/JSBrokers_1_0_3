import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant Support (espace courtier).
 *
 * À la soumission de la demande :
 *  1. déclenche la barre de progression du workspace (événement `app:loading.start`,
 *     écouté par workspace-manager — la barre en haut de l'espace de travail) ;
 *  2. envoie la demande en AJAX (JSON { success, message, html }) ;
 *  3. affiche le retour succès/échec dans le toast global (`app:notification.show`,
 *     pattern existant géré par notification-manager) ;
 *  4. remplace le composant par sa version rafraîchie (liste mise à jour + formulaire
 *     vierge en cas de succès, ou champs en erreur sinon) ;
 *  5. masque la barre de progression (`app:loading.stop`).
 *
 * Aucune navigation ni rechargement de page.
 */
export default class extends Controller {
    async submit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;

        document.dispatchEvent(new CustomEvent('app:loading.start'));

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();

            // Retour succès / échec dans le toast (pattern existant).
            this.notify(data.success ? 'success' : 'error', data.message);

            // Composant rafraîchi : Stimulus reconnecte le nouveau nœud.
            if (data.html) {
                this.element.outerHTML = data.html;
            } else if (button) {
                button.disabled = false;
            }
        } catch (error) {
            console.error('[support] Échec de la soumission de la demande :', error);
            this.notify('error', 'Une erreur est survenue. Veuillez réessayer.');
            if (button) button.disabled = false;
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
    }

    /** Émet un toast via le gestionnaire de notifications global. */
    notify(type, text) {
        if (!text) return;
        document.dispatchEvent(new CustomEvent('app:notification.show', {
            detail: { type, text },
        }));
    }
}
