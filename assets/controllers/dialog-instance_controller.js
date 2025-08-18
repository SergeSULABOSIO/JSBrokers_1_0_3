import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Ce contrôleur gère le cycle de vie d'UNE SEULE instance de dialogue.
 * Il est créé dynamiquement par 'dialog-manager' et se détruit à la fermeture.
 */
export default class extends Controller {

    /**
     * Initialise le contrôleur avec les données de la demande.
     * C'est le point d'entrée après sa création par le dialog-manager.
     */
    async initialize(detail) {
        this.canvas = detail.entityFormCanvas;
        this.entity = detail.entity;
        this.context = detail.context || {};
        
        // Charge le contenu complet (header, body, footer) de la modale
        await this.loadFullDialogContent();

        // Initialise et affiche la modale Bootstrap
        this.modal = new Modal(this.element.closest('.modal'));
        this.modal.show();

        // Ajoute un écouteur pour s'auto-détruire du DOM après la fermeture
        this.element.closest('.modal').addEventListener('hidden.bs.modal', () => {
            this.element.closest('.modal').remove();
        });
    }

    /**
     * Charge le squelette HTML de la modale (form, header, footer) et le corps du formulaire.
     */
    async loadFullDialogContent() {
        const isEditMode = this.entity && this.entity.id;
        const title = isEditMode
            ? this.canvas.parametres.titre_modification.replace('%id%', this.entity.id)
            : this.canvas.parametres.titre_creation;

        this.element.innerHTML = `
            <form data-action="submit->dialog-instance#submitForm">
                <div class="modal-header">
                    <h5 class="modal-title">${title}</h5>
                    <button type="button" class="btn-close btn-close-white" data-action="click->dialog-instance#close"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center p-5"><span class="spinner-border"></span></div>
                </div>
                <div class="modal-footer">
                    <div class="feedback-container"></div>
                    <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer</button>
                </div>
            </form>
        `;

        let url = this.canvas.parametres.endpoint_form_url;
        if (isEditMode) {
            url += `/${this.entity.id}`;
        }
        
        const response = await fetch(url);
        this.element.querySelector('.modal-body').innerHTML = await response.text();
    }
    
    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        event.preventDefault();
        const feedbackContainer = this.element.querySelector('.feedback-container');
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

            // En cas de succès, on notifie les autres composants
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