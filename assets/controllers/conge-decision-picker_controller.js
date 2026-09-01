import PickerBaseController from './picker-base_controller.js';

/**
 * Boîte des quatre gestes du circuit de validation des congés : soumettre, approuver,
 * refuser, annuler.
 *
 * Le HTML (_decision_picker.html.twig) est chargé et inséré par le cerveau
 * (handleCongeDecisionRequest) ; ce contrôleur s'auto-connecte à l'insertion. La coque —
 * focus, fermeture ✕/backdrop/Échap, progression, zone d'erreur — vient du socle
 * picker-base. Ne restent ici que deux gestes métier.
 *
 * ── AUCUN CHIFFRE N'EST FABRIQUÉ ICI ────────────────────────────────────────────────
 * Le décompte, le solde et ce qui empêche encore le geste sont calculés ET rendus par le
 * SERVEUR, du même calcul que la liste, la fiche et les e-mails. Ce contrôleur n'affiche
 * rien qu'il aurait recalculé — c'est la seule manière que l'écran et l'assistant
 * annoncent le même nombre.
 *
 * ── PAS D'APERÇU RAFRAÎCHI EN CONTINU ───────────────────────────────────────────────
 * Contrairement au picker « non renouvelable », la seule saisie possible est un
 * commentaire, et un commentaire ne change ni le décompte ni le solde. Un endpoint
 * d'aperçu supplémentaire n'apprendrait donc rien à personne.
 */
export default class extends PickerBaseController {
    static pickerName = 'CONGE-DECISION-PICKER';

    static targets = ['commentaire', 'executer', 'aideCommentaire'];

    static values = {
        executerUrl: String,
        geste: String,
        commentaireRequis: Boolean,
    };

    connect() {
        super.connect();

        // Un motif est exigé pour annuler une absence DÉJÀ COMMENCÉE : le bouton reste
        // fermé tant que la case est vide, plutôt que de laisser cliquer pour se voir
        // refuser par le serveur (Nielsen 5 : prévenir l'erreur plutôt que la signaler).
        this.boundVerifier = () => this._verifierCommentaire();
        if (this.hasCommentaireTarget) {
            this.commentaireTarget.addEventListener('input', this.boundVerifier);
        }
        this._verifierCommentaire();
    }

    disconnect() {
        if (this.hasCommentaireTarget && this.boundVerifier) {
            this.commentaireTarget.removeEventListener('input', this.boundVerifier);
        }
        super.disconnect();
    }

    _onActionClick(event) {
        const bouton = event.target.closest('[data-picker-executer]');
        if (bouton) this._executer(bouton);
    }

    /**
     * Le bouton de validation suit l'état du commentaire — et seulement lui : si le
     * serveur a déjà jugé le geste impossible, le bouton est rendu désactivé et rien ici
     * ne le rouvre.
     * @private
     */
    _verifierCommentaire() {
        if (!this.hasExecuterTarget || !this.commentaireRequisValue) return;
        if (this.executerTarget.dataset.bloqueParLeServeur === '1') return;

        const rempli = this.hasCommentaireTarget && this.commentaireTarget.value.trim() !== '';
        this.executerTarget.disabled = !rempli;
    }

    _params() {
        return {
            commentaire: this.hasCommentaireTarget ? this.commentaireTarget.value.trim() : '',
        };
    }

    /**
     * Enregistre le geste (POST). Succès → notification du cerveau, qui rafraîchit la
     * liste (les actions offertes basculent, le chip de statut bouge, le solde tombe à sa
     * nouvelle valeur) puis fermeture ; erreur → message inline, bouton réactivé.
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

            this._notifyCerveau('conge:decision.enregistree', {
                message: data.message || 'Décision enregistrée.',
            });
            this.close();
        } catch (error) {
            console.error('[CONGE-DECISION-PICKER] exécution :', error);
            this._showError(error.message || "La décision n'a pas pu être enregistrée.");
            bouton.disabled = false;
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }
}
