import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static targets = ["title", "body", "confirmButton", "feedback", "progressBarContainer"];

    connect() {
        this.nomControlleur = "Confirmation-dialog";
        this.modal = new Modal(this.element);
        this.boundOpen = this.open.bind(this);

        this.boundAdjustZIndex = this.adjustZIndex.bind(this); // Garde une référence

        this.boundHandleSuccess = this.handleSuccess.bind(this);
        this.boundHandleError = this.handleError.bind(this);
        document.addEventListener('confirmation:open-request', this.boundOpen);
        document.addEventListener('delete:success', this.boundHandleSuccess);
        document.addEventListener('delete:error', this.boundHandleError);
        this.element.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    disconnect() {
        document.removeEventListener('confirmation:open-request', this.boundOpen);
        document.removeEventListener('delete:success', this.boundHandleSuccess);
        document.removeEventListener('delete:error', this.boundHandleError);
        this.element.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    /**
     * Ouvre la modale et prépare l'action de confirmation.
     */
    open(event) {
        const { title, body, onConfirm } = event.detail;
        this.titleTarget.innerHTML = title || 'Confirmation requise';
        this.bodyTarget.innerHTML = body || 'Êtes-vous sûr ?';
        this.onConfirmDetail = onConfirm;
        this.modal.show();
    }

    /**
     * Exécuté lorsque l'utilisateur clique sur "Confirmer".
     */
    confirm() {
        this.toggleLoading(true); // Active le spinner
        this.toggleProgressBar(true);
        this.feedbackTarget.innerHTML = ''; // Nettoie les anciens messages d'erreur

        // Si une action de confirmation a été définie...
        if (this.onConfirmDetail && this.onConfirmDetail.eventName) {
            document.dispatchEvent(new CustomEvent(this.onConfirmDetail.eventName, {
                bubbles: true,
                detail: this.onConfirmDetail.detail || {}
            }));
        }
        // setTimeout(() => { this.close(); }, 50);
    }

    // NOUVEAU : Gère l'événement de succès
    handleSuccess() {
        this.close();
    }

    // NOUVEAU : Gère l'événement d'erreur
    handleError(event) {
        this.toggleLoading(false); // Stoppe le spinner
        this.toggleProgressBar(false);
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
        this.toggleProgressBar(false);
        this.feedbackTarget.innerHTML = '';
        this.modal.hide();
        this.onConfirmDetail = null;
    }

    /**
     * NOUVELLE VERSION : Cherche le z-index le plus élevé et se place au-dessus.
     */
    adjustZIndex() {
        // Trouve tous les backdrops visibles
        const backdrops = document.querySelectorAll('.modal-backdrop.show');

        // S'il y a plus d'un backdrop, cela signifie que nous superposons les modales
        if (backdrops.length > 1) {
            // Trouve le z-index le plus élevé parmi TOUTES les modales actuellement visibles
            const modals = document.querySelectorAll('.modal.show');
            let maxZIndex = 0;
            modals.forEach(modal => {
                // On s'assure de ne pas nous comparer à nous-même si notre z-index est déjà très élevé
                if (modal !== this.element.closest('.modal')) {
                    const zIndex = parseInt(window.getComputedStyle(modal).zIndex) || 1055;
                    if (zIndex > maxZIndex) {
                        maxZIndex = zIndex;
                    }
                }
            });

            // On récupère notre modale et son backdrop (c'est toujours le dernier ajouté)
            const myModal = this.element.closest('.modal');
            const myBackdrop = backdrops[backdrops.length - 1];

            // On définit le z-index de notre modale pour être au-dessus du maximum trouvé,
            // et celui de son backdrop juste en dessous.
            myModal.style.zIndex = maxZIndex + 2;
            myBackdrop.style.zIndex = maxZIndex + 1;
        }
    }

    /**
     * NOUVEAU : Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        if (this.hasProgressBarContainerTarget) {
            this.progressBarContainerTarget.classList.toggle('is-loading', isLoading);
        }
    }
}