// assets/controllers/notification-manager_controller.js
import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap'; // Important: importer Toast
import { EVEN_SHOW_TOAST } from './base_controller.js';

export default class extends Controller {
    connect() {
        this.nomControleur = "NOTIFICATION-MANAGER";

        // On écoute un événement global qui peut être déclenché depuis n'importe où
        document.addEventListener(EVEN_SHOW_TOAST, this.show.bind(this));
    }

    disconnect() {
        document.removeEventListener(EVEN_SHOW_TOAST, this.show.bind(this));
    }

    show(event) {
        console.log(this.nomControleur + " - Show", event.detail);
        const { text, type = 'info' } = event.detail;

        // Map pour associer un type à une classe Bootstrap et une icône
        const options = {
            success: { class: 'text-bg-success', icon: '✅' },
            error: { class: 'text-bg-danger', icon: '❌' },
            info: { class: 'text-bg-info', icon: 'ℹ️' },
            warning: { class: 'text-bg-warning', icon: '⚠️' }
        };

        const config = options[type];

        // Création dynamique de l'HTML du toast
        const toastHTML = `
            <div class="toast align-items-center ${config.class} border-0 p-2 m-1" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        ${config.icon} ${text}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
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