import PickerBaseController from './picker-base_controller.js';

/**
 * Boîte « cette police n'est pas à renouveler » : signaler, corriger le motif, rétablir
 * (actions de la rubrique Avenants — barre d'outils, clic droit, fiche — et clic droit du
 * widget Renouvellements du tableau de bord).
 *
 * Le HTML (_non_renouvelable_picker.html.twig) est chargé et inséré par le cerveau
 * (handleAvenantNonRenouvelableRequest) ; ce contrôleur s'auto-connecte à l'insertion.
 * La coque (focus, fermeture ✕/backdrop/Échap, progression, zone d'erreur) vient du socle
 * picker-base. Ne restent ici que deux gestes métier, calqués sur mouvement-picker :
 *
 *  1. RAFRAÎCHIR L'APERÇU pendant la saisie du motif. L'aperçu est calculé ET rendu par le
 *     SERVEUR (même service que l'outil de l'assistante) : ce contrôleur ne fabrique aucun
 *     libellé, notamment pas les montants restant à recouvrer — il substitue un fragment.
 *     C'est ce qui garantit que l'écran et Ket annoncent exactement la même chose.
 *  2. ENREGISTRER la décision (POST), puis notifier le cerveau et fermer.
 */
export default class extends PickerBaseController {
    static pickerName = 'NON-RENOUVELABLE-PICKER';

    static targets = ['motif', 'apercu', 'executer'];

    static values = {
        apercuUrl: String,
        executerUrl: String,
        mode: String,
    };

    connect() {
        super.connect();

        // Rafraîchissement différé : on suit la frappe sans marteler le serveur.
        this.boundRefresh = () => {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => this._refreshApercu(), 350);
        };
        if (this.hasMotifTarget) {
            this.motifTarget.addEventListener('input', this.boundRefresh);
            this.motifTarget.addEventListener('change', this.boundRefresh);
        }
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
        super.disconnect();
    }

    _onActionClick(event) {
        const bouton = event.target.closest('[data-picker-executer]');
        if (bouton) this._executer(bouton);
    }

    /** Le mode voyage en query (l'URL le porte déjà) ; seul le motif est saisi. */
    _params() {
        const params = {};
        if (this.hasMotifTarget) params.motif = this.motifTarget.value;

        return params;
    }

    /** Ajoute le mode à une URL : les trois gestes partagent les mêmes endpoints. */
    _url(base) {
        const url = new URL(base, window.location.origin);
        if (this.modeValue) url.searchParams.set('mode', this.modeValue);

        return url;
    }

    /** Recharge le fragment d'aperçu et l'état du bouton de validation. */
    async _refreshApercu() {
        if (!this.apercuUrlValue || !this.hasApercuTarget) return;

        const url = this._url(this.apercuUrlValue);
        Object.entries(this._params()).forEach(([cle, valeur]) => url.searchParams.set(cle, valeur));

        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            this.apercuTarget.innerHTML = data.html || '';
            if (this.hasExecuterTarget) this.executerTarget.disabled = !data.pret;
            this._showError(null);
        } catch (error) {
            console.error('[NON-RENOUVELABLE-PICKER] aperçu :', error);
            // L'aperçu précédent reste affiché : on signale sans effacer le contexte.
            this._showError("L'aperçu n'a pas pu être actualisé.");
            if (this.hasExecuterTarget) this.executerTarget.disabled = true;
        }
    }

    /**
     * Enregistre la décision (POST). Succès → notification du cerveau (qui rafraîchit la
     * liste : la police quitte ou réintègre son chip, les actions basculent) + fermeture ;
     * erreur → message inline, bouton réactivé (Nielsen 9).
     */
    async _executer(bouton) {
        if (!this.executerUrlValue || this.enCours) return;

        this.enCours = true;
        bouton.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(this._url(this.executerUrlValue), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(this._params()),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur ${response.status}`);
            }

            this._notifyCerveau('avenant:non-renouvelable.enregistre', {
                message: data.message || 'Décision enregistrée.',
            });
            this.close();
        } catch (error) {
            console.error('[NON-RENOUVELABLE-PICKER] exécution :', error);
            this._showError(error.message || "La décision n'a pas pu être enregistrée.");
            bouton.disabled = false;
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }
}
