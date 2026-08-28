import PickerBaseController from './picker-base_controller.js';

/**
 * @class PartageConditionsPickerController
 * @description Choisir la condition de partage d'un agent, et la rattacher aux affaires
 * sélectionnées — depuis n'importe quel écran de leur arbre.
 *
 * Hérite du socle des pickers autonomes (Échap, clic sur l'arrière-plan, restitution du
 * focus, filtre de recherche, zone d'erreur, barre de progression) : il ne reste ici que
 * l'écriture.
 *
 * ── LE CLIC VAUT ACCORD ─────────────────────────────────────────────────────────────
 * Les implications sont écrites dans l'entête du picker, pas dans une seconde boîte : on
 * rattache parfois dix affaires à la suite, et enchaîner deux dialogues à chaque fois
 * ferait cliquer sans lire — ce qui est l'inverse d'un consentement éclairé. Le
 * DÉTACHEMENT, lui, garde sa confirmation : c'est le geste qui défait.
 *
 * ── LE REFUS SE LIT ICI, PAS DANS UN TOAST ──────────────────────────────────────────
 * Un lot refusé parce qu'une affaire est déjà prise doit s'expliquer là où l'utilisateur
 * regarde, avec la liste sous les yeux (Nielsen 9). Le toast, lui, annonce le succès.
 */
export default class extends PickerBaseController {
    static pickerName = 'PARTAGE-CONDITIONS-PICKER';

    static values = {
        submitUrl: String,
        ids: String,
    };

    _onActionClick(event) {
        const bouton = event.target.closest('[data-picker-affect]');
        if (bouton) this._rattacher(bouton);
    }

    /**
     * Rattache la condition de la ligne aux affaires sélectionnées.
     *
     * Les identifiants sont ceux de la SÉLECTION D'ORIGINE (des avenants, des tranches…) :
     * c'est le serveur qui remonte à l'affaire et dédoublonne. Le navigateur n'a pas à
     * connaître l'arbre.
     */
    async _rattacher(bouton) {
        const conditionId = parseInt(bouton.dataset.conditionId, 10);
        if (!conditionId || this.enCours) return;
        this.enCours = true;

        bouton.disabled = true;
        this._progress(true);
        this._showError(null);

        try {
            // LA DESTINATION VIENT DE LA LIGNE. Chaque bouton porte son verbe — rattacher
            // ou détacher selon que la condition est déjà posée — et donc sa route. Le
            // repli sur `submitUrlValue` garde le contrôleur utilisable par un picker qui
            // n'aurait qu'un seul geste à offrir.
            const reponse = await fetch(bouton.dataset.actionUrl || this.submitUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    ids: (this.idsValue || '').split(',').map((n) => parseInt(n, 10)).filter(Boolean),
                    conditionId,
                }),
            });
            const data = await reponse.json().catch(() => ({}));
            if (!reponse.ok) throw new Error(data.message || `Erreur serveur: ${reponse.status}`);

            // Le cerveau notifie et rafraîchit la liste : le voyant « Effort commercial »
            // apparaît alors sur chaque ligne concernée, et les deux actions s'inversent.
            this._notifyCerveau('client:partage.updated', {
                message: data.message || 'Le partage de l’affaire a été mis à jour.',
            });
            this.close();
        } catch (error) {
            bouton.disabled = false;
            this._showError(error.message || 'Le rattachement a échoué. Réessayez, ou vérifiez la sélection.');
        } finally {
            this.enCours = false;
            this._progress(false);
        }
    }
}
