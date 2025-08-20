import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static targets = ["title", "body", "confirmButton", "feedback"];

    connect() {
        this.nomControlleur = "Confirmation-dialog";
        this.modal = new Modal(this.element);
        // Écoute l'événement global pour s'ouvrir
        this.boundOpen = this.open.bind(this);
        this.bountHandleSuccess = this.handleSuccess.bind(this);
        this.boundHandleError = this.handleError.bind(this);
        document.addEventListener('confirmation:open-request', this.boundOpen);
        document.addEventListener('delete:success', this.bountHandleSuccess);
        document.addEventListener('delete:error', this.boundHandleError);
    }

    disconnect() {
        document.removeEventListener('confirmation:open-request', this.boundOpen);
        document.addEventListener('delete:success', this.bountHandleSuccess);
        document.addEventListener('delete:error', this.boundHandleError);
    }

    /**
     * Ouvre la modale et prépare l'action de confirmation.
     */
    open(event) {
        const { title, body, onConfirm } = event.detail;

        // Met à jour le contenu de la modale avec les messages fournis
        this.titleTarget.innerHTML = title || 'Confirmation requise';
        this.bodyTarget.innerHTML = body || 'Êtes-vous sûr ?';

        // Stocke l'événement à déclencher si l'utilisateur confirme
        this.onConfirmDetail = onConfirm;
        this.modal.show();
    }

    /**
     * Exécuté lorsque l'utilisateur clique sur "Confirmer".
     */
    confirm() {
        this.toggleLoading(true); // Active le spinner
        this.feedbackTarget.innerHTML = ''; // Nettoie les anciens messages d'erreur

        // Si une action de confirmation a été définie...
        if (this.onConfirmDetail && this.onConfirmDetail.eventName) {
            // ...on la déclenche.
            document.dispatchEvent(new CustomEvent(this.onConfirmDetail.eventName, {
                bubbles: true,
                detail: this.onConfirmDetail.detail || {}
            }));
        }
    }

    // NOUVEAU : Gère l'événement de succès
    handleSuccess() {
        this.close();
    }

    // NOUVEAU : Gère l'événement d'erreur
    handleError(event) {
        this.toggleLoading(false); // Stoppe le spinner
        this.feedbackTarget.innerHTML = event.detail.message || "Une erreur est survenue.";
    }

    // NOUVEAU : Gère l'affichage du spinner
    toggleLoading(isLoading) {
        const button = this.confirmButtonTarget;
        const spinner = button.querySelector('.spinner-border');
        const icon = button.querySelector('.button-icon');
        const text = button.querySelector('.button-text');

        if (isLoading) {
            button.disabled = true;
            spinner.style.display = 'inline-block';
            icon.style.display = 'none';
            text.textContent = 'Suppression...';
        } else {
            button.disabled = false;
            spinner.style.display = 'none';
            icon.style.display = 'inline-block';
            text.textContent = 'Confirmer';
        }
    }

    /**
     * Ferme la modale.
     */
    close() {
        this.toggleLoading(false); // S'assure que le bouton est réinitialisé en cas de fermeture manuelle
        this.feedbackTarget.innerHTML = '';
        this.modal.hide();
        this.onConfirmDetail = null;
    }
}