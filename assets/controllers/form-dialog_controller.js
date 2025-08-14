// assets/controllers/form-dialog_controller.js
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { buildCustomEventForElement, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_BOITE_DIALOGUE_INITIALIZED } from './base_controller.js';

export default class extends Controller {
    static targets = ["title", "formBody", "feedback", "submitButton"];

    connect() {
        this.modal = new Modal(this.element);
        // Garde une référence à la fonction liée pour le removeEventListener
        this.boundHandleOpenRequest = this.handleOpenRequest.bind(this);
        document.addEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundHandleOpenRequest);
    }

    disconnect() {
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundHandleOpenRequest);
    }

    // Ouvre et prépare la boîte de dialogue
    handleOpenRequest(event) {
        const { entity, entityFormCanvas } = event.detail;
        this.entity = entity;
        this.canvas = entityFormCanvas;

        this.clearFeedback();
        this.loadAndBuildForm();
        this.modal.show();
    }

    /**
     * NOUVELLE FONCTION (remplace l'ancienne 'buildForm')
     * Charge le HTML du formulaire via AJAX et l'injecte dans la modale.
     */
    async loadAndBuildForm() {
        const isEditMode = this.entity && this.entity.id;
        // Le titre peut être simple, le formulaire contiendra les labels détaillés
        this.titleTarget.textContent = isEditMode ? this.canvas.parametres.titre_modification : this.canvas.parametres.titre_creation;
        
        // let url = '/admin/notificationsinistre/api/get-form';
        let url = this.canvas.parametres.endpoint_form_url;
        if (isEditMode) {
            url += `/${this.entity.id}`;
        }
        
        this.formBodyTarget.innerHTML = '<div class="text-center p-5"><span class="spinner-border"></span></div>'; // Affiche un spinner de chargement

        try {
            const response = await fetch(url);
            // if (!response.ok) throw new Error('Erreur réseau lors du chargement du formulaire.');
            const html = await response.text();
            this.formBodyTarget.innerHTML = html;
        } catch(e) {
            this.formBodyTarget.innerHTML = '<div class="alert alert-danger">Impossible de charger le formulaire.</div>';
        }
    }

    // Soumet le formulaire en AJAX
    async submitForm(event) {
        event.preventDefault();
        this.toggleLoading(true);
        this.clearFeedback();

        const form = this.element.querySelector('form');
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());

        if (this.entity && this.entity.id) {
            data.id = this.entity.id; // Ajoute l'ID pour la modification
        }

        try {
            const response = await fetch(this.canvas.parametres.endpoint_submit_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (response.ok) {
                this.showFeedback('success', result.message);
                // Émet un événement pour dire aux autres composants (ex: la liste) de se rafraîchir
                document.dispatchEvent(new CustomEvent('app:list:refresh'));
                setTimeout(() => this.modal.hide(), 1500);
            } else {
                this.showFeedback('danger', result.message);
                if (result.errors) {
                    this.displayErrors(result.errors);
                }
            }
        } catch (e) {
            this.showFeedback('danger', "Une erreur de connexion est survenue.");
            console.error(e);
        } finally {
            this.toggleLoading(false);
        }
    }

    // Gère l'affichage des erreurs de validation
    displayErrors(errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            const input = this.formBodyTarget.querySelector(`[name="${field}"]`);
            if (input) {
                input.classList.add('is-invalid');
                input.nextElementSibling.textContent = messages.join(', ');
            }
        });
    }

    // Affiche un message de succès ou d'erreur global
    showFeedback(type, message) {
        this.feedbackTarget.className = `alert alert-${type}`;
        this.feedbackTarget.textContent = message;
    }

    clearFeedback() {
        this.formBodyTarget.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        this.feedbackTarget.className = '';
        this.feedbackTarget.textContent = '';
    }

    toggleLoading(isLoading) {
        const buttonText = this.submitButtonTarget.childNodes[2];
        const spinner = this.submitButtonTarget.querySelector('.spinner-border');

        this.submitButtonTarget.disabled = isLoading;
        spinner.style.display = isLoading ? 'inline-block' : 'none';
        buttonText.textContent = isLoading ? ' Enregistrement...' : 'Enregistrer';
    }

    close() {
        this.modal.hide();
    }
}