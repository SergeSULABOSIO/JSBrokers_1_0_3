import PickerBaseController from './picker-base_controller.js';

/**
 * Boîte de MOUVEMENT d'une police : renouvellement, prorogation, annulation,
 * résiliation (actions de la rubrique Avenants — barre d'outils et clic droit).
 *
 * Le HTML (_mouvement_picker.html.twig) est chargé et inséré par le cerveau
 * (handleAvenantMouvementRequest) ; ce contrôleur s'auto-connecte à l'insertion.
 * La coque (focus, fermeture ✕/backdrop/Échap, progression, zone d'erreur) vient du
 * socle picker-base. Ne restent ici que deux gestes métier :
 *
 *  1. RAFRAÎCHIR L'APERÇU quand l'utilisateur change la durée ou la date. L'aperçu
 *     est calculé ET rendu par le serveur (même MouvementAvenantBuilder que
 *     l'assistant) : ce contrôleur ne fabrique aucun libellé et ne recalcule aucune
 *     date — il substitue un fragment. C'est ce qui garantit que l'écran et Ket
 *     annoncent exactement la même chose.
 *  2. EXÉCUTER le mouvement (POST), puis notifier le cerveau et fermer.
 */
export default class extends PickerBaseController {
    static pickerName = 'MOUVEMENT-PICKER';

    static targets = ['duree', 'date', 'apercu', 'executer'];

    static values = {
        apercuUrl: String,
        executerUrl: String,
        libelle: String,
    };

    connect() {
        super.connect();

        // Rafraîchissement différé : on suit la frappe sans marteler le serveur.
        this.boundRefresh = () => {
            clearTimeout(this.refreshTimer);
            this.refreshTimer = setTimeout(() => this._refreshApercu(), 350);
        };
        [this.hasDureeTarget ? this.dureeTarget : null, this.hasDateTarget ? this.dateTarget : null]
            .filter(Boolean)
            .forEach((input) => {
                input.addEventListener('input', this.boundRefresh);
                input.addEventListener('change', this.boundRefresh);
            });

        // Une date d'effet / une durée par défaut est déjà dans le champ : on demande
        // l'aperçu correspondant tout de suite, plutôt que d'afficher « renseignez… »
        // sur un formulaire déjà rempli (Nielsen 1 — l'état visible est le vrai état).
        if (this.hasDureeTarget || this.hasDateTarget) this._refreshApercu();
    }

    disconnect() {
        clearTimeout(this.refreshTimer);
        super.disconnect();
    }

    _onActionClick(event) {
        const bouton = event.target.closest('[data-picker-executer]');
        if (bouton) this._executer(bouton);
    }

    /** Paramètres saisis, tels que les attend le builder côté serveur. */
    _params() {
        const params = {};
        if (this.hasDureeTarget && this.dureeTarget.value) params.dureeJours = this.dureeTarget.value;
        if (this.hasDateTarget && this.dateTarget.value) params.dateEffet = this.dateTarget.value;

        return params;
    }

    /** Recharge le fragment d'aperçu et l'état du bouton de validation. */
    async _refreshApercu() {
        if (!this.apercuUrlValue || !this.hasApercuTarget) return;

        const url = new URL(this.apercuUrlValue, window.location.origin);
        Object.entries(this._params()).forEach(([cle, valeur]) => url.searchParams.set(cle, valeur));

        try {
            const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur ${response.status}`);

            this.apercuTarget.innerHTML = data.html || '';
            if (this.hasExecuterTarget) this.executerTarget.disabled = !data.pret;
            this._showError(null);
        } catch (error) {
            console.error('[MOUVEMENT-PICKER] aperçu :', error);
            // L'aperçu précédent reste affiché : on signale sans effacer le contexte.
            this._showError("L'aperçu n'a pas pu être actualisé.");
            if (this.hasExecuterTarget) this.executerTarget.disabled = true;
        }
    }

    /**
     * Enregistre le mouvement (POST). Succès → notification du cerveau (qui
     * rafraîchit la liste : l'action disparaît, la police change de statut) +
     * fermeture ; erreur → message inline, bouton réactivé (Nielsen 9).
     */
    async _executer(bouton) {
        if (!this.executerUrlValue || this.enCours) return;

        this.enCours = true;
        bouton.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(this.executerUrlValue, {
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

            this._notifyCerveau('avenant:mouvement.enregistre', {
                message: data.message || `${this.libelleValue || 'Mouvement'} enregistré.`,
            });
            this.close();
        } catch (error) {
            console.error('[MOUVEMENT-PICKER] exécution :', error);
            this._showError(error.message || "Le mouvement n'a pas pu être enregistré.");
            bouton.disabled = false;
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }
}
