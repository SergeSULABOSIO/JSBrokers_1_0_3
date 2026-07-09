import PickerBaseController from './picker-base_controller.js';

/**
 * Boîte d'ENVOI DU SOA PAR E-MAIL au client (action « Envoyer le SOA par e-mail »
 * de la rubrique Clients).
 *
 * Le HTML (_envoi_picker.html.twig) est chargé et inséré dans le DOM par le cerveau
 * (handleSoaSendRequest) ; ce contrôleur s'auto-connecte à l'insertion. Le comportement
 * de coque (focus, fermeture ✕/backdrop/Échap, progression, zone d'erreur) vient du
 * socle picker-base ; ne reste ici que l'action métier : le POST d'envoi (destinataire
 * choisi + message d'accompagnement facultatif). Au succès, on notifie le cerveau
 * (client:soa.envoye) qui affiche la notification, puis on ferme.
 */
export default class extends PickerBaseController {
    static pickerName = 'SOA-ENVOI-PICKER';

    static values = {
        sendUrl: String,
    };

    _onActionClick(event) {
        const sendBtn = event.target.closest('[data-picker-send]');
        if (sendBtn) this._send(sendBtn);
    }

    /**
     * Envoie le lien SOA au destinataire coché (POST). Succès → notification du cerveau
     * (message serveur détaillé : destinataire + date de validité) + fermeture ;
     * erreur → message inline dans le picker (Nielsen 9), bouton réactivé.
     */
    async _send(button) {
        if (!this.sendUrlValue || this.sendRunning) return;

        const checked = this.element.querySelector('input[name="soa-destinataire"]:checked');
        if (!checked) {
            this._showError('Choisissez d\'abord un destinataire.');
            return;
        }
        const messageField = this.element.querySelector('[data-picker-message]');

        this.sendRunning = true;
        button.disabled = true;
        this._progress(true);
        this._showError(null);
        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    email: checked.value,
                    message: messageField ? messageField.value : null,
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur: ${response.status}`);
            }

            this._notifyCerveau('client:soa.envoye', {
                message: data.message || 'Relevé de compte envoyé.',
            });
            this.close();
        } catch (error) {
            button.disabled = false;
            this._showError(error.message || "Envoi impossible. Réessayez ou vérifiez l'adresse du destinataire.");
        } finally {
            this.sendRunning = false;
            this._progress(false);
        }
    }
}
