import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant Support (espace courtier).
 *
 * Soumet la demande de support en AJAX puis remplace l'intégralité du composant
 * par sa version rafraîchie renvoyée par le serveur (accusé de réception, liste
 * mise à jour, formulaire vierge) — sans recharger la page ni l'espace de travail.
 * Sur erreur HTTP, on réactive le bouton sans remplacer le composant.
 */
export default class extends Controller {
    async submit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            // Remplace ce nœud : Stimulus déconnecte l'ancien contrôleur et
            // reconnecte le nouveau composant injecté.
            this.element.outerHTML = html;
        } catch (error) {
            console.error('[support] Échec de la soumission de la demande :', error);
            if (button) button.disabled = false;
        }
    }
}
