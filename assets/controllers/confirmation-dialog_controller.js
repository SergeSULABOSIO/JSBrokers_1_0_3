import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static targets = ["title", "body"];

    connect() {
        this.nomControlleur = "Confirmation-dialog";
        this.modal = new Modal(this.element);
        // Écoute l'événement global pour s'ouvrir
        this.boundOpen = this.open.bind(this);
        document.addEventListener('confirmation:open-request', this.boundOpen);
    }

    disconnect() {
        document.removeEventListener('confirmation:open-request', this.boundOpen);
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
        // Si une action de confirmation a été définie...
        if (this.onConfirmDetail && this.onConfirmDetail.eventName) {
            // ...on la déclenche.
            document.dispatchEvent(new CustomEvent(this.onConfirmDetail.eventName, {
                bubbles: true,
                detail: this.onConfirmDetail.detail || {}
            }));
        }
        this.close();
    }

    /**
     * Ferme la modale.
     */
    close() {
        this.modal.hide();
        this.onConfirmDetail = null; // Nettoie l'action stockée
    }
}