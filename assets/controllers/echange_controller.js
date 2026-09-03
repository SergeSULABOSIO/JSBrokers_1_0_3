import { Controller } from '@hotwired/stimulus';

/**
 * Contrôleur du composant « Importation / Exportation » (espace de travail).
 *
 * Deux gestes, un seul principe : la barre de progression GLOBALE du workspace
 * s'allume au clic — avant la première opération coûteuse, pas après le premier octet
 * reçu — et s'éteint dans un `finally`.
 *
 * ⚠ L'EXTINCTION N'EST JAMAIS SUR LE SEUL CHEMIN NOMINAL. Succès, erreur réseau,
 * exception, refus de droits, solde épuisé, périmètre vide : tous ces chemins passent
 * par le même bloc. Une barre restée allumée laisse croire que l'application travaille
 * encore, ce qui est pire que pas de barre du tout.
 *
 * Aucun indicateur propre à la rubrique n'est créé : le composant global existe déjà,
 * et deux barres qui se contredisent valent moins qu'une seule. Le mode reste
 * INDÉTERMINÉ — le serveur produit le classeur d'un bloc et n'émet aucune progression,
 * et simuler un pourcentage serait mentir sur ce qu'on sait.
 */
export default class extends Controller {
    static targets = ['boutonExport'];

    static values = {
        url: String,
        onglet: String,
        exportUrl: String,
    };

    /**
     * Verrou de réentrance. Le bouton désarmé suffit à l'utilisateur ; ce drapeau, lui,
     * couvre le reste : une touche Entrée maintenue, un second déclencheur ajouté plus
     * tard, un appel programmatique. Deux exports simultanés, ce sont deux occurrences.
     */
    #exportEnCours = false;

    /** Changement d'onglet (bouton pill) : `data-echange-onglet-param`. */
    changeOnglet(event) {
        const onglet = event.params.onglet;
        if (!onglet || onglet === this.ongletValue) return;
        this.ongletValue = onglet;
        this.#reload();
    }

    /**
     * Génère l'export et déclenche le téléchargement.
     *
     * Le fichier est récupéré en `fetch` plutôt que par un lien direct, pour deux
     * raisons qui tiennent l'une à l'autre : on peut alors désarmer le bouton pendant
     * toute l'opération, et on peut LIRE le corps d'un refus (402 solde insuffisant,
     * 422 périmètre vide) au lieu d'ouvrir un onglet affichant un message d'erreur brut.
     */
    async exporter() {
        if (this.#exportEnCours) return;
        this.#exportEnCours = true;
        this.#armerBouton(false);
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        // Une graine stable par clic : si la requête est rejouée (retry réseau), le
        // serveur reconnaît la même opération et ne facture pas deux fois.
        const graine = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
        let objectUrl = null;

        try {
            const response = await fetch(`${this.exportUrlValue}?op=${encodeURIComponent(graine)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                // Le serveur explique POURQUOI il refuse ; on relaie son texte plutôt
                // qu'un « une erreur est survenue » qui n'aide personne.
                const motif = (await response.text()).trim();
                throw new Error(motif || `HTTP ${response.status}`);
            }

            const blob = await response.blob();
            objectUrl = URL.createObjectURL(blob);

            const lien = document.createElement('a');
            lien.href = objectUrl;
            lien.download = this.#nomFichier(response) || 'jsbrokers.xlsx';
            document.body.appendChild(lien);
            lien.click();
            lien.remove();

            document.dispatchEvent(new CustomEvent('app:notification.show', {
                detail: { type: 'success', text: 'Export généré. Le téléchargement a démarré.' },
            }));

            // L'opération vient de consommer une occurrence : le bandeau de facturation
            // et l'historique affichent des chiffres désormais faux. On recharge.
            this.#reload();
        } catch (error) {
            console.error('[echange] Échec de l’export :', error);
            document.dispatchEvent(new CustomEvent('app:notification.show', {
                detail: { type: 'error', text: error.message || "L'export n'a pas pu être généré." },
            }));
        } finally {
            // Tous les chemins de sortie passent ici, sans exception.
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            this.#armerBouton(true);
            this.#exportEnCours = false;
        }
    }

    /** Nom de fichier annoncé par le serveur (Content-Disposition), s'il est lisible. */
    #nomFichier(response) {
        const entete = response.headers.get('Content-Disposition') || '';
        const match = entete.match(/filename\*?=(?:UTF-8'')?"?([^";]+)"?/i);

        return match ? decodeURIComponent(match[1]) : null;
    }

    #armerBouton(actif) {
        if (!this.hasBoutonExportTarget) return;
        this.boutonExportTarget.disabled = !actif;
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
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
    }
}
