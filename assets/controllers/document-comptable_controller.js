import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant « Documents comptables » (espace courtier).
 *
 * Le composant est rendu côté serveur (DocumentComptableWorkspaceController) ;
 * ce contrôleur ne fait que RECHARGER le composant en AJAX quand l'utilisateur
 * change d'onglet (journal, grand livre, …, suivi fiscal), d'exercice, clique
 * sur « Actualiser », ou toutes les 5 minutes (actualisation automatique) :
 *  1. barre de progression du workspace (`app:loading.start`) ;
 *  2. fetch du composant avec les paramètres `doc` / `exercice` courants ;
 *  3. remplacement du nœud entier (outerHTML) — Stimulus reconnecte le nouveau
 *     composant (values ré-émises par le template, minuteur ré-armé) ;
 *  4. toast d'erreur (`app:notification.show`) en cas d'échec, sans casser l'UI.
 *
 * L'actualisation AUTOMATIQUE ne part que si le composant est réellement
 * visible : onglet workspace actif (offsetParent non nul — les panneaux
 * inactifs sont en display:none) ET onglet navigateur au premier plan
 * (document.hidden). Le minuteur est détruit à la déconnexion (fermeture de
 * l'onglet workspace) — aucune requête fantôme.
 *
 * Les exports Excel sont des liens GET directs (téléchargement navigateur) :
 * aucun traitement ici. Aucun <script> inline dans le template (non exécuté
 * dans les partials AJAX du workspace).
 */
export default class extends Controller {
    /** Période d'actualisation automatique : 5 minutes. */
    static AUTO_REFRESH_MS = 5 * 60 * 1000;

    static values = {
        url: String,
        doc: String,
        exercice: Number,
    };

    connect() {
        this.refreshTimer = setInterval(() => this.#autoRefresh(), this.constructor.AUTO_REFRESH_MS);
    }

    disconnect() {
        clearInterval(this.refreshTimer);
    }

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

    /** Actualisation MANUELLE (bouton « Actualiser ») : recharge le document courant. */
    refresh() {
        this.#reload();
    }

    /**
     * Actualisation AUTOMATIQUE (minuteur) : uniquement si le composant est
     * visible — onglet workspace actif ET onglet navigateur au premier plan.
     */
    #autoRefresh() {
        if (document.hidden || this.element.offsetParent === null) return;
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
