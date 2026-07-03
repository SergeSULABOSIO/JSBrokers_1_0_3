import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant « Documents comptables » (espace courtier).
 *
 * Le composant est rendu côté serveur (DocumentComptableWorkspaceController) ;
 * ce contrôleur ne fait que RECHARGER le composant en AJAX quand l'utilisateur
 * change d'onglet (journal, grand livre, …, suivi fiscal) ou d'exercice :
 *  1. barre de progression du workspace (`app:loading.start`) ;
 *  2. fetch du composant avec les nouveaux paramètres `doc` / `exercice` ;
 *  3. remplacement du nœud entier (outerHTML) — Stimulus reconnecte le nouveau
 *     composant, dont les `values` sont ré-émises par le template ;
 *  4. toast d'erreur (`app:notification.show`) en cas d'échec, sans casser l'UI.
 *
 * Les exports Excel sont des liens GET directs (téléchargement navigateur) :
 * aucun traitement ici. Aucun <script> inline dans le template (non exécuté
 * dans les partials AJAX du workspace).
 */
export default class extends Controller {
    static values = {
        url: String,
        doc: String,
        exercice: Number,
    };

    /** Changement d'onglet (bouton pill) : `data-document-comptable-doc-param`. */
    changeDoc(event) {
        const doc = event.params.doc;
        if (!doc || doc === this.docValue) return;
        this.docValue = doc;
        this.#reload();
    }

    /** Changement d'exercice (select). */
    changeExercice(event) {
        this.exerciceValue = parseInt(event.target.value, 10);
        this.#reload();
    }

    async #reload() {
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        try {
            const url = `${this.urlValue}?doc=${encodeURIComponent(this.docValue)}&exercice=${this.exerciceValue}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.element.outerHTML = await response.text();
        } catch (error) {
            console.error('[document-comptable] Échec du rechargement :', error);
            document.dispatchEvent(new CustomEvent('app:notification.show', {
                detail: { type: 'error', text: 'Impossible de charger le document demandé. Veuillez réessayer.' },
            }));
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
    }
}
