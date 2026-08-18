import PickerBase from './picker-base_controller.js';

/**
 * @class ReversementRetroPickerController
 * @description Saisie d'un reversement de rétrocommission à un agent interne : une affaire,
 * ou plusieurs cochées d'un coup — auquel cas c'est UN SEUL virement qui est enregistré.
 *
 * Hérite du socle des pickers autonomes (overlay, fermeture ✕/backdrop/Échap, restitution
 * du focus, barre de progression, zone d'erreur inline, événements vers le cerveau) : il ne
 * reste ici que la logique propre au reversement.
 *
 * CE CONTRÔLEUR NE CALCULE AUCUN MONTANT MÉTIER. Les soldes exigibles sont posés par le
 * serveur dans le gabarit ; il ne fait qu'additionner ce que l'utilisateur a coché, pour
 * lui montrer le total avant qu'il valide. La référence de LOT, elle, est générée côté
 * serveur : la laisser au navigateur laisserait deux versements se mélanger.
 */
export default class extends PickerBase {
    static pickerName = 'REVERSEMENT-RETRO-PICKER';

    static targets = ['ligne', 'coche', 'montant', 'date', 'reference', 'compte', 'apercu', 'executer'];

    static values = {
        submitUrl: String,
    };

    connect() {
        super.connect();
        this.enCours = false;
        this.recalculer();
    }

    /** La case d'en-tête : coche ou décoche tout, puis recalcule une seule fois. */
    toutCocher(event) {
        const cocher = event.target.checked;
        this.cocheTargets.forEach((c) => { c.checked = cocher; });
        this.recalculer();
    }

    /**
     * Met à jour l'aperçu et l'état du bouton. C'est le seul retour immédiat dont dispose
     * l'utilisateur avant de valider un décaissement : il doit y lire le NOMBRE de lignes
     * et le TOTAL, pas seulement « prêt ».
     */
    recalculer() {
        const lignes = this._lignesCochees();
        const total = lignes.reduce((somme, l) => somme + l.montant, 0);

        if (this.hasExecuterTarget) {
            this.executerTarget.disabled = this.enCours || lignes.length === 0 || total <= 0;
        }

        if (!this.hasApercuTarget) return;

        if (lignes.length === 0) {
            this.apercuTarget.textContent = 'Aucune affaire cochée.';
            return;
        }

        const montant = total.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        this.apercuTarget.textContent = lignes.length === 1
            ? `1 reversement de ${montant}.`
            : `${lignes.length} lignes, réglées par UN SEUL virement de ${montant} — une seule écriture comptable.`;
    }

    /** Envoie le lot au serveur, qui pose la référence de lot et écrit les lignes. */
    async _onActionClick(event) {
        if (!event.target.closest('[data-picker-executer]')) return;
        if (this.enCours) return;

        const lignes = this._lignesCochees();
        if (lignes.length === 0) {
            this._showError('Cochez au moins une affaire à régler.');
            return;
        }

        this.enCours = true;
        this._showError(null);
        this._progress(true);
        if (this.hasExecuterTarget) this.executerTarget.disabled = true;

        try {
            const response = await fetch(this.submitUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    lignes,
                    paidAt: this.hasDateTarget ? this.dateTarget.value : null,
                    reference: this.hasReferenceTarget ? this.referenceTarget.value : null,
                    compteBancaireId: this.hasCompteTarget ? this.compteTarget.value : null,
                }),
            });
            const result = await response.json();
            if (!response.ok) throw result;

            // Le cerveau notifie et rafraîchit la liste : les colonnes « payée » et
            // « solde » de l'agent tombent alors à leur nouvelle valeur.
            this._notifyCerveau('client:retroagent.reversement-enregistre', {
                message: result.message,
                agentNom: null,
            });
            this.close();
        } catch (error) {
            this.enCours = false;
            this._progress(false);
            this._showError(error?.message || "Le reversement n'a pas pu être enregistré.");
            this.recalculer();
        }
    }

    /**
     * Les lignes cochées, avec leur montant. Une ligne cochée dont le montant a été mis à
     * zéro n'est PAS envoyée : le serveur la refuserait, autant ne pas l'inclure — mais on
     * ne la décoche pas d'autorité, l'utilisateur est peut-être en train de saisir.
     * @private
     */
    _lignesCochees() {
        const lignes = [];
        this.ligneTargets.forEach((ligne, index) => {
            const coche = this.cocheTargets[index];
            if (!coche || !coche.checked) return;

            const montant = parseFloat((this.montantTargets[index]?.value || '0').replace(',', '.'));
            if (!Number.isFinite(montant) || montant <= 0) return;

            lignes.push({
                avenantId: parseInt(ligne.dataset.avenantId, 10),
                montant: Math.round(montant * 100) / 100,
            });
        });

        return lignes;
    }
}
