import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Ce contrôleur gère le cycle de vie d'UNE SEULE instance de dialogue.
 * Il est créé dynamiquement par 'dialog-manager' et se détruit à la fermeture.
 */
export default class extends Controller {

    connect() {
        // On récupère les données que le dialog-manager a attachées à l'élément.
        const detail = this.element.dialogDetail;
        this.elementContenu = this.element;

        if (detail) {
            // On lance notre logique d'initialisation.
            this.start(detail);
        } else {
            console.error("L'instance de dialogue s'est connectée sans recevoir de données d'initialisation !");
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
                     <div class="feedback-container"></div>
                    <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="25px" height="25px" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8zm3.59-13L12 10.59L8.41 7L7 8.41L10.59 12L7 15.59L8.41 17L12 13.41L15.59 17L17 15.59L13.41 12L17 8.41z"></path></svg>
                        <span>Annuler</span>
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                        <svg xmlns="http://www.w3.org/2000/svg" width="23px" height="23px" viewBox="0 0 24 24" fill="currentColor"><path d="M15.004 3h-10a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10L15.004 3zm-9 16V6h8v4h4v9h-12z"></path></svg>
                        <span>Enregistrer</span>
                    </button>
                </div>
            </form>
        `;

        // 2. On initialise et on AFFICHE la modale. L'utilisateur voit maintenant le spinner.
        const modalNode = this.elementContenu.closest('.modal');
        this.modal = new Modal(modalNode);
        this.modal.show();

        modalNode.addEventListener('hidden.bs.modal', () => {
            modalNode.remove();
        });

        // 3. SEULEMENT MAINTENANT, on lance le chargement asynchrone du formulaire.
        await this.loadFormBody();
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
        const feedbackContainer = this.elementContenu.querySelector('.feedback-container');
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
            feedbackContainer.className = 'alert alert-danger';
            feedbackContainer.textContent = error.message || 'Une erreur est survenue.';
        }
    }

    /**
     * Ferme la modale.
     */
    close() {
        this.modal.hide();
    }
}