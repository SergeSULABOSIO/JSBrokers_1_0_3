import PickerBaseController from './picker-base_controller.js';

/**
 * Picker de PORTEFEUILLE cible pour un client (actions « Affecter à un portefeuille » /
 * « Transférer vers un autre portefeuille » de la rubrique Clients).
 *
 * Le HTML (_portefeuille_picker.html.twig) est chargé et inséré dans le DOM par le
 * cerveau (handleClientPortefeuillePickerRequest) ; ce contrôleur s'auto-connecte à
 * l'insertion. Le comportement de coque (focus, fermeture ✕/backdrop/Échap, filtrage,
 * progression) vient du socle picker-base ; ne reste ici que l'action métier : le PUT
 * d'affectation. Au succès, on notifie le cerveau (client:portefeuille.updated) qui
 * affiche la notification et rafraîchit la liste, puis on ferme.
 */
export default class extends PickerBaseController {
    static pickerName = 'PORTEFEUILLE-PICKER';

    static values = {
        clientNom: String,
    };

    _onActionClick(event) {
        const affectBtn = event.target.closest('[data-picker-affect]');
        if (affectBtn) this._affect(affectBtn);
    }

    /**
     * Affecte/transfère le client vers le portefeuille de la ligne (PUT). Le choix dans
     * le picker VAUT confirmation : succès → notification du cerveau (message serveur
     * détaillé) + fermeture ; erreur → message inline dans le picker (Nielsen 9).
     */
    async _affect(button) {
        const url = button.dataset.affectUrl;
        if (!url || this.affectRunning) return;
        this.affectRunning = true;

        button.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(url, {
                method: 'PUT',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || `Erreur serveur: ${response.status}`);

            // Le cerveau affiche la notification et rafraîchit la liste active
            // (hasPortefeuille et la ligne secondaire se recalculent au refresh).
            this._notifyCerveau('client:portefeuille.updated', {
                message: data.message || 'Portefeuille mis à jour.',
            });
            this.close();
        } catch (error) {
            button.disabled = false;
            this._showError(error.message || "Affectation impossible. Réessayez ou contactez le propriétaire de l'espace.");
        } finally {
            this.affectRunning = false;
            this._progress(false);
        }
    }
}
