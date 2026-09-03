import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant « Importation / Exportation » (espace de travail).
 *
 * Le composant est rendu côté serveur (EchangeController) ; ce contrôleur ne fait que
 * RECHARGER le composant en AJAX au changement d'onglet, sur le même patron que
 * `document-comptable` :
 *  1. barre de progression GLOBALE du workspace (`app:loading.start`) ;
 *  2. fetch du composant avec l'onglet demandé ;
 *  3. remplacement du nœud entier (outerHTML) — Stimulus reconnecte le nouveau
 *     composant, les `values` étant ré-émises par le template ;
 *  4. toast d'erreur (`app:notification.show`) en cas d'échec, sans casser l'UI.
 *
 * ⚠ LA BARRE S'ÉTEINT DANS UN `finally`, JAMAIS SUR LE SEUL CHEMIN NOMINAL. Succès,
 * erreur réseau, exception, refus de droits, solde épuisé : tous ces chemins passent
 * par le même bloc. Une barre restée allumée laisse croire que l'application travaille
 * encore, ce qui est pire que pas de barre du tout.
 *
 * Aucun indicateur propre à la rubrique n'est créé : le composant global existe déjà,
 * et deux barres qui se contredisent valent moins qu'une seule.
 */
export default class extends Controller {
    static values = {
        url: String,
        onglet: String,
    };

    /** Changement d'onglet (bouton pill) : `data-echange-onglet-param`. */
    changeOnglet(event) {
        const onglet = event.params.onglet;
        if (!onglet || onglet === this.ongletValue) return;
        this.ongletValue = onglet;
        this.#reload();
    }

    async #reload() {
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        try {
            const url = `${this.urlValue}?onglet=${encodeURIComponent(this.ongletValue)}`;
            const response = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            this.element.outerHTML = await response.text();
        } catch (error) {
            console.error('[echange] Échec du rechargement :', error);
            document.dispatchEvent(new CustomEvent('app:notification.show', {
                detail: { type: 'error', text: "Impossible de charger cet onglet. Veuillez réessayer." },
            }));
        } finally {
            // Tous les chemins de sortie passent ici, y compris l'échec réseau.
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
    }
}
