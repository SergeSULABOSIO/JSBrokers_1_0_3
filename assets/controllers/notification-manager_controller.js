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

        // Map pour associer un type à une couleur (contextuelle), une icône et
        // un titre d'en-tête. La structure (en-tête + corps) est commune à tous
        // les toasts (cf. .jsb-toast) ; seule la COULEUR varie selon le type.
        const options = {
            success: { bg: 'bg-success', text: 'text-white', icon: '✅', title: 'Succès' },
            error: { bg: 'bg-danger', text: 'text-white', icon: '❌', title: 'Erreur' },
            info: { bg: 'bg-info', text: 'text-dark', icon: 'ℹ️', title: 'Information' },
            warning: { bg: 'bg-warning', text: 'text-dark', icon: '⚠️', title: 'Avertissement' }
        };

        const config = options[type] || options.info;

        // Détermine la classe du bouton de fermeture en fonction du fond
        const closeButtonClass = (config.text === 'text-white') ? 'btn-close-white' : '';

        // Création dynamique de l'HTML du toast — même structure que le toast
        // « Paramètres de l'espace de travail » : en-tête (icône + titre) + corps.
        const toastHTML = `
            <div class="toast jsb-toast ${config.bg} ${config.text} bg-opacity-75" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <span aria-hidden="true">${config.icon}</span>
                    <strong class="ms-2 me-auto">${config.title}</strong>
                    <button type="button" class="btn-close ${closeButtonClass}" data-bs-dismiss="toast" aria-label="Fermer"></button>
                </div>
                <div class="toast-body">
                    ${text}
                </div>
            </div>
        `;

        // Ajout du toast au conteneur
        this.element.insertAdjacentHTML('beforeend', toastHTML);

        const newToastEl = this.element.lastElementChild; //
        const toast = new Toast(newToastEl, {
            delay: 10000 // Le toast disparaît après 10 secondes
        });

        // Supprimer le toast du DOM une fois qu'il est caché pour garder le HTML propre
        newToastEl.addEventListener('hidden.bs.toast', () => {
            newToastEl.remove();
        });

        toast.show();
    }
}