import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * @class ConfirmationDialogController
 * @extends Controller
 * @description Gère une boîte de dialogue de confirmation générique.
 * Ce contrôleur écoute les demandes d'ouverture venant du Cerveau, affiche la modale,
 * et notifie le Cerveau de la confirmation de l'utilisateur. Il est entièrement découplé
 * de l'action qu'il confirme.
 */
export default class extends Controller {
    static targets = ["title", "body", "confirmButton", "feedback", "progressBarContainer"];

    connect() {
        this.nomControlleur = "ConfirmationDialog";
        this.modal = new Modal(this.element);

        // Centralisation des écouteurs d'événements via le Cerveau
        this.boundHandleCerveauEvent = this.handleCerveauEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleCerveauEvent);

        // Écouteur pour la gestion du z-index
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);
        this.element.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundHandleCerveauEvent);
        this.element.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
    }

    /**
     * Point d'entrée pour les événements venant du Cerveau.
     * Filtre et délègue aux méthodes appropriées.
     * @param {CustomEvent} event
     */
    handleCerveauEvent(event) {
        const { type, payload } = event.detail;
        switch (type) {
            case 'ui:confirmation.request':
                this.open(payload);
                break;
            case 'ui:confirmation.close':
                this.close();
                break;
            case 'app:error.api':
                // Si une erreur API survient pendant que la confirmation est en attente, on arrête le chargement.
                if (this.confirmButtonTarget.disabled) {
                    this.handleError(payload);
                }
                break;
        }
    }

    /**
     * Ouvre la modale et prépare l'action de confirmation.
     * @param {object} payload - Les données de l'événement.
     * @param {string} payload.title - Le titre de la modale.
     * @param {string} payload.body - Le corps du message de la modale.
     * @param {object} payload.onConfirm - L'action à notifier au Cerveau en cas de confirmation.
     */
    open(payload) {
        const { title, body, onConfirm } = payload;
        this.titleTarget.innerHTML = title || 'Confirmation requise';
        this.bodyTarget.innerHTML = body || 'Êtes-vous sûr ?';
        this.onConfirmDetail = onConfirm;
        this.modal.show();
    }

    /**
     * Exécuté lorsque l'utilisateur clique sur "Confirmer".
     * Notifie le Cerveau pour qu'il exécute l'action mise en attente.
     * @fires cerveau:event
     */
    confirm() {
        this.toggleLoading(true); // Active le spinner
        this.toggleProgressBar(true);
        this.feedbackTarget.innerHTML = ''; // Nettoie les anciens messages d'erreur

        // Si une action de confirmation a été définie...
        if (this.onConfirmDetail && this.onConfirmDetail.type) {
            this.notifyCerveau(this.onConfirmDetail.type, this.onConfirmDetail.payload || {});
        }
    }

    /**
     * Gère un événement d'erreur reçu pendant le processus de confirmation.
     * Affiche le message d'erreur et réactive le bouton.
     * @param {object} payload - Le payload de l'événement d'erreur.
     * @param {string} payload.error - Le message d'erreur.
     */
    handleError(payload) {
        this.toggleLoading(false); // Stoppe le spinner
        this.toggleProgressBar(false);
        this.feedbackTarget.innerHTML = payload.error || "Une erreur est survenue.";
    }

    // Gère l'affichage du spinner et l'état du bouton de confirmation.
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
     * Ajuste le z-index pour s'assurer que cette modale apparaît au-dessus des autres.
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
     * Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        if (this.hasProgressBarContainerTarget) {
            this.progressBarContainerTarget.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type de l'événement.
     * @param {object} [payload={}] - Les données associées à l'événement.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControlleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}