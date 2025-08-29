import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Ce contrôleur gère le cycle de vie d'UNE SEULE instance de dialogue.
 * Il est créé dynamiquement par 'dialog-manager' et se détruit à la fermeture.
 */
export default class extends Controller {
    // 1. SUPPRIMEZ complètement la déclaration des 'targets', on va les chercher dynamiquement avec querySelector
    // static targets = ["submitButton", "feedback", "progressBarContainer"];

    connect() {
        this.nomControlleur = "Dialog-Instance";
        const detail = this.element.dialogDetail;
        this.elementContenu = this.element;
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);

        if (detail) {
            this.start(detail);
            // console.log(this.nomControlleur + " - connect", this.elementContenu, this.targets);
        } else {
            console.error("L'instance de dialogue s'est connectée sans recevoir de données d'initialisation !");
        }
    }

    disconnect() {
        if (this.modalNode) {
            this.modalNode.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
        }
    }

    /**
     * Reçoit les détails, AFFICHE la modale avec un spinner, PUIS charge le formulaire.
     */
    async start(detail) {
        this.canvas = detail.entityFormCanvas;
        this.entity = detail.entity;
        this.context = detail.context || {};

        console.log(this.nomControlleur + " - start:", detail);

        // 1. On construit immédiatement la structure de la modale avec un spinner dans le corps.
        const isEditMode = this.entity && this.entity.id;
        const title = isEditMode
            ? this.canvas.parametres.titre_modification.replace('%id%', this.entity.id)
            : this.canvas.parametres.titre_creation;

        this.elementContenu.innerHTML = `
            <form data-action="submit->dialog-instance#submitForm">
                <div class="modal-header">
                    <h5 class="modal-title">${title}</h5>
                    <button type="button" class="btn-close btn-close-white" data-action="click->dialog-instance#close"></button>
                </div>
                <div class="progress-bar-container" data-dialog-instance-target="progressBarContainer">
                    <div class="progress-bar-animated" role="progressbar"></div>
                </div>
                <div class="modal-body">
                    <div class="text-center p-5">
                        <div class="spinner-border" style="width: 3rem; height: 3rem;" role="status">
                            <span class="visually-hidden">Chargement...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="feedback-container w-100 text-danger mb-2" data-dialog-instance-target="feedback"></div>
                    <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="25px" height="25px" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8zm3.59-13L12 10.59L8.41 7L7 8.41L10.59 12L7 15.59L8.41 17L12 13.41L15.59 17L17 15.59L13.41 12L17 8.41z"></path></svg>
                        <span>Annuler</span>
                    </button>
                    <button type="submit" class="btn btn-primary" data-dialog-instance-target="submitButton">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                        <span class="button-icon"><svg xmlns="http://www.w3.org/2000/svg" width="23px" height="23px" viewBox="0 0 24 24" fill="currentColor"><path d="M15.004 3h-10a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10L15.004 3zm-9 16V6h8v4h4v9h-12z"></path></svg></span>
                        <span class="button-text">Enregistrer</span>
                    </button>
                </div>
            </form>
        `;

        this.modalNode = this.elementContenu.closest('.modal');
        this.modal = new Modal(this.modalNode);
        this.modal.show();

        this.modalNode.addEventListener('hidden.bs.modal', () => { this.modalNode.remove(); });
        this.modalNode.addEventListener('shown.bs.modal', this.boundAdjustZIndex);

        await this.loadFormBody();
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
                if (modal !== this.elementContenu.closest('.modal')) {
                    const zIndex = parseInt(window.getComputedStyle(modal).zIndex) || 1055;
                    if (zIndex > maxZIndex) {
                        maxZIndex = zIndex;
                    }
                }
            });

            // On récupère notre modale et son backdrop (c'est toujours le dernier ajouté)
            const myModal = this.elementContenu.closest('.modal');
            const myBackdrop = backdrops[backdrops.length - 1];

            // On définit le z-index de notre modale pour être au-dessus du maximum trouvé,
            // et celui de son backdrop juste en dessous.
            myModal.style.zIndex = maxZIndex + 2;
            myBackdrop.style.zIndex = maxZIndex + 1;
        }
    }



    /**
     * Charge le contenu du formulaire et le place dans le .modal-body.
     */
    async loadFormBody() {
        try {
            let url = this.canvas.parametres.endpoint_form_url;
            if (this.entity && this.entity.id) {
                url += `/${this.entity.id}`;
            }
            console.log(this.nomControlleur + " - loadFormBody:", url);
            const response = await fetch(url);
            if (!response.ok) throw new Error("Le formulaire n'a pas pu être chargé.");

            this.elementContenu.querySelector('.modal-body').innerHTML = await response.text();
        } catch (error) {
            this.elementContenu.querySelector('.modal-body').innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        }
    }

    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        event.preventDefault();
        this.toggleLoading(true);
        this.toggleProgressBar(true);

        this.feedbackContainer = this.elementContenu.querySelector('.feedback-container');
        this.clearErrors(); // On nettoie les anciennes erreurs
        if (this.feedbackContainer) {
            this.feedbackContainer.innerHTML = '';
        }

        // 1. On récupère les données du formulaire directement dans un objet FormData.
        const formData = new FormData(event.target);

        // 2. On AJOUTE nos données de contexte (ID, parent, etc.) à cet objet.
        if (this.entity && this.entity.id) {
            formData.append('id', this.entity.id);
        }
        // On fusionne tout le contexte. C'est plus simple et plus dynamique.
        if (this.context) {
            for (const [key, value] of Object.entries(this.context)) {
                formData.append(key, value);
            }
        }
        try {
            const response = await fetch(this.canvas.parametres.endpoint_submit_url, {
                method: 'POST',
                body: formData // On envoie l'objet FormData directement.
            });
            const result = await response.json();
            if (!response.ok) throw result;

            document.dispatchEvent(new CustomEvent('collection-manager:refresh-list', { detail: { originatorId: this.context.originatorId } }));
            document.dispatchEvent(new CustomEvent('main-list:refresh-request'));
            this.close();

        } catch (error) {
            if (this.feedbackContainer) {
                this.feedbackContainer.textContent = error.message || 'Une erreur est survenue.';
            }
            if (error.errors) {
                this.displayErrors(error.errors);
            }
            this.toggleLoading(false);
        } finally {
            // S'assure que la barre disparaît dans tous les cas
            this.toggleProgressBar(false);
        }
    }

    /**
     * NOUVEAU : Affiche les erreurs de validation à côté de chaque champ.
     */
    displayErrors(errors) {
        const form = this.elementContenu.querySelector('form');
        for (const [fieldName, messages] of Object.entries(errors)) {
            // NOUVELLE GESTION : Si le nom du champ est vide, c'est une erreur globale.
            if (fieldName === '') {
                if (this.feedbackContainer) {
                    const globalErrors = messages.join('<br>');
                    // On ajoute l'erreur globale au conteneur de feedback général
                    this.feedbackContainer.innerHTML += `<div class="mt-2">${globalErrors}</div>`;
                }
                continue; // On passe au champ suivant
            }

            const input = form.querySelector(`[name="${fieldName}"]`);
            if (input) {
                // Ajoute la classe Bootstrap pour le style d'erreur
                input.classList.add('is-invalid');

                // Crée et insère le message d'erreur juste après le champ
                const errorDiv = document.createElement('div');
                errorDiv.className = 'invalid-feedback d-block'; // d-block pour le forcer à être visible
                errorDiv.textContent = messages.join(', ');
                input.parentNode.insertBefore(errorDiv, input.nextSibling);
            }
        }
    }


    /**
     * NOUVEAU : Nettoie les messages d'erreur précédents.
     */
    clearErrors() {
        this.elementContenu.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        this.elementContenu.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
        this.feedbackContainer = this.elementContenu.querySelector('.feedback-container');
        if (this.feedbackContainer) this.feedbackContainer.innerHTML = '';
    }


    /**
     * Ferme la modale.
     */
    close() {
        this.toggleProgressBar(false); // <-- CACHER LA BARRE avant de fermer
        this.modal.hide();
    }

    /**
     * AJOUT : Gère l'état visuel du bouton de soumission.
     */
    toggleLoading(isLoading) {
        // On cherche le bouton manuellement juste quand on en a besoin
        const button = this.elementContenu.querySelector('button[type="submit"]');
        if (!button) return;

        const spinner = button.querySelector('.spinner-border');
        const icon = button.querySelector('.button-icon');
        const text = button.querySelector('.button-text');

        if (isLoading) {
            button.disabled = true;
            spinner.style.display = 'inline-block';
            icon.style.display = 'none';
            text.textContent = 'Enregistrement...';
        } else {
            button.disabled = false;
            spinner.style.display = 'none';
            icon.style.display = 'inline-block';
            text.textContent = 'Enregistrer';
        }
    }

    /**
     * NOUVEAU : Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        // On cherche le conteneur de la barre manuellement
        const progressBarContainer = this.elementContenu.querySelector('.progress-bar-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }
}