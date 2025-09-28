// assets/controllers/notification-manager_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap'; // Important: importer Toast

export default class extends Controller {
    connect() {
        this.nomControleur = "NOTIFICATION-MANAGER";
        // --- CORRECTION : Lier la méthode une seule fois et écouter l'événement diffusé par le cerveau ---
        this.boundShow = this.show.bind(this);
        document.addEventListener('app:notification.show', this.boundShow);
    }

    disconnect() {
        // --- CORRECTION : Nettoyer le bon écouteur ---
        document.removeEventListener('app:notification.show', this.boundShow);
    }

    show(event) {
        console.log(this.nomControleur + " - Show", event.detail);
        const { text, type = 'info' } = event.detail;

        // Map pour associer un type à une classe Bootstrap et une icône
        const options = {
            success: { bg: 'bg-success', text: 'text-white', icon: '✅' },
            error: { bg: 'bg-danger', text: 'text-white', icon: '❌' },
            info: { bg: 'bg-info', text: 'text-dark', icon: 'ℹ️' },
            warning: { bg: 'bg-warning', text: 'text-dark', icon: '⚠️' }
        };

        const config = options[type];

        // Détermine la classe du bouton de fermeture en fonction du fond
        const closeButtonClass = (config.text === 'text-white') ? 'btn-close-white' : '';

        // Création dynamique de l'HTML du toast
        const toastHTML = `
            <div class="toast align-items-center ${config.bg} ${config.text} bg-opacity-75 border border-secondary p-2 m-1" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${config.icon} ${text}
                    </div>
                    <button type="button" class="btn-close ${closeButtonClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        `;

        // Ajout du toast au conteneur
        this.element.insertAdjacentHTML('beforeend', toastHTML);

        const newToastEl = this.element.lastElementChild;
        const toast = new Toast(newToastEl, {
            delay: 4000 // Le toast disparaît après 4 secondes
        });

        // Supprimer le toast du DOM une fois qu'il est caché pour garder le HTML propre
        newToastEl.addEventListener('hidden.bs.toast', () => {
            newToastEl.remove();
        });

        toast.show();
    }
}