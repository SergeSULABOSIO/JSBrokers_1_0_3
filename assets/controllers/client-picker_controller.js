import PickerBaseController from './picker-base_controller.js';

/**
 * Picker de CLIENTS à rattacher à un portefeuille, en mode AUTONOME (action spéciale
 * « Ajouter des clients au portefeuille » de la rubrique Portefeuilles : menu
 * contextuel, toolbar ou volet du dialogue d'édition).
 *
 * Le HTML (_client_picker.html.twig, servi avec ?standalone=1) est chargé et inséré
 * dans le DOM par le cerveau (handlePortefeuilleClientPickerRequest) ; ce contrôleur
 * s'auto-connecte à l'insertion. Le comportement de coque (focus, fermeture, filtrage,
 * progression) vient du socle picker-base ; ne restent ici que les actions métier :
 * ajout (PUT attach) et retrait (DELETE detach) d'un client, la ligne et les compteurs
 * étant mis à jour sur place. À CHAQUE succès, on notifie le cerveau
 * (client:portefeuille.updated) qui affiche le toast et rafraîchit la liste active —
 * les colonnes agrégées du portefeuille se recalculent immédiatement — et le picker
 * RESTE ouvert pour enchaîner les ajouts. (Le même template, ouvert depuis le widget
 * collection du dialogue d'édition, reste piloté par collection_controller.)
 */
export default class extends PickerBaseController {
    static pickerName = 'CLIENT-PICKER';

    _onActionClick(event) {
        const attachBtn = event.target.closest('[data-picker-attach]');
        if (attachBtn) { this._run(attachBtn, true); return; }
        const detachBtn = event.target.closest('[data-picker-detach]');
        if (detachBtn) this._run(detachBtn, false);
    }

    /**
     * Exécute l'ajout (PUT) ou le retrait (DELETE) du client de la ligne. Succès →
     * bascule de la ligne + compteur, toast et rafraîchissement de la liste via le
     * cerveau ; erreur → message inline dans le picker (Nielsen 9).
     */
    async _run(button, isAttach) {
        const row = button.closest('[data-picker-row]');
        const url = isAttach ? button.dataset.attachUrl : button.dataset.detachUrl;
        if (!url || this.actionRunning) return;
        this.actionRunning = true;

        button.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(url, {
                method: isAttach ? 'PUT' : 'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur: ${response.status}`);

            this._setRowState(row, isAttach);
            this._updateCount(isAttach ? 1 : -1);
            // Le cerveau affiche le toast et rafraîchit la liste des portefeuilles
            // (colonnes agrégées) ; le picker reste ouvert pour l'ajout suivant.
            this._notifyCerveau('client:portefeuille.updated', {
                message: data.message || (isAttach ? 'Client ajouté au portefeuille.' : 'Client retiré du portefeuille.'),
            });
        } catch (error) {
            button.disabled = false;
            this._showError(error.message || "Action impossible. Réessayez ou contactez le propriétaire de l'espace.");
        } finally {
            this.actionRunning = false;
            this._progress(false);
        }
    }

    /**
     * Met à jour une ligne selon son nouvel état, SANS reconstruire les boutons : on
     * bascule seulement la visibilité des boutons « Ajouter »/« Retirer » (déjà rendus
     * côté serveur avec leurs icônes) et on rafraîchit la pastille de statut — même
     * mécanique que le picker du widget collection (collection_controller).
     */
    _setRowState(row, isCurrent) {
        if (!row) return;
        row.dataset.pickerState = isCurrent ? 'current' : 'free';
        const statusCell = row.querySelector('[data-picker-status]');
        const attachBtn = row.querySelector('[data-picker-attach]');
        const detachBtn = row.querySelector('[data-picker-detach]');

        if (statusCell) {
            statusCell.innerHTML = isCurrent
                ? '<span class="jsb-picker-chip jsb-picker-chip--current">Dans ce portefeuille</span>'
                : '<span class="jsb-picker-chip jsb-picker-chip--free">Sans portefeuille</span>';
        }
        if (attachBtn) { attachBtn.hidden = isCurrent; attachBtn.disabled = false; }
        if (detachBtn) { detachBtn.hidden = !isCurrent; detachBtn.disabled = false; }
    }

    /** Met à jour en direct le compteur « N dans ce portefeuille ». */
    _updateCount(delta) {
        const el = this.element.querySelector('[data-picker-count-current]');
        if (!el) return;
        const current = parseInt(el.textContent, 10) || 0;
        el.textContent = Math.max(0, current + delta);
    }
}
