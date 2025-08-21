import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Ce contrôleur gère le cycle de vie d'UNE SEULE instance de dialogue.
 * Il est créé dynamiquement par 'dialog-manager' et se détruit à la fermeture.
 */
export default class extends Controller {
    // static targets = ["submitButton", "feedback"];

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

        this.modalNode.addEventListener('hidden.bs.modal', () => {this.modalNode.remove();});
        this.modalNode.addEventListener('shown.bs.modal', this.boundAdjustZIndex);

        await this.loadFormBody();
    }

    /**
     * NOUVELLE FONCTION : Corrige le z-index si plusieurs modales sont ouvertes.
     */
    adjustZIndex() {
        const modals = Array.from(document.querySelectorAll('.modal.show'));
        const backdrops = Array.from(document.querySelectorAll('.modal-backdrop.show'));

        if (modals.length > 1) {
            let zIndexBase = 1050; // z-index de base de Bootstrap

            modals.forEach((modal, index) => {
                const backdrop = backdrops[index];
                zIndexBase += 10; // On augmente de 10 pour chaque nouvelle modale
                modal.style.zIndex = zIndexBase + 5;
                if (backdrop) {
                    backdrop.style.zIndex = zIndexBase;
                }
            });
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

        // On cherche le conteneur de feedback manuellement
        const feedbackContainer = this.elementContenu.querySelector('.feedback-container');
        feedbackContainer.innerHTML = '';

        
        const formData = new FormData(event.target);
        const data = Object.fromEntries(formData.entries());
        if (this.entity && this.entity.id) data.id = this.entity.id;
        if (this.context.notificationSinistreId) data.notificationSinistre = this.context.notificationSinistreId;
        try {
            const response = await fetch(this.canvas.parametres.endpoint_submit_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            const result = await response.json();
            if (!response.ok) throw result;
            document.dispatchEvent(new CustomEvent('collection-manager:refresh-list', {
                detail: { originatorId: this.context.originatorId }
            }));
            document.dispatchEvent(new CustomEvent('main-list:refresh-request'));
            this.close();
        } catch (error) {
            this.feedbackContainer.textContent = error.message || 'Une erreur est survenue.';
            this.toggleLoading(false); // On réactive le bouton en cas d'erreur
        }
    }

    /**
     * Ferme la modale.
     */
    close() {
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
}