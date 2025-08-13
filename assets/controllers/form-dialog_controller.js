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
        this.buildForm();
        this.modal.show();
    }

    // Construit le formulaire à partir du canvas
    buildForm() {
        // Définit le titre (création vs modification)
        const isEditMode = this.entity && this.entity.id;
        let title = isEditMode 
            ? this.canvas.parametres.titre_modification.replace('%id%', this.entity.id)
            : this.canvas.parametres.titre_creation;
        this.titleTarget.textContent = title;

        this.formBodyTarget.innerHTML = ''; // Vide le formulaire précédent
        
        this.canvas.champs.forEach(field => {
            const value = this.entity[field.code] || '';
            let inputHtml = '';
            
            switch(field.type) {
                case 'textarea':
                    inputHtml = `<textarea id="form_${field.code}" name="${field.code}" class="form-control" ${field.requis ? 'required' : ''}>${value}</textarea>`;
                    break;
                // Ajoutez d'autres 'case' pour 'select', 'relation', etc.
                default:
                    inputHtml = `<input type="${field.type}" id="form_${field.code}" name="${field.code}" value="${value}" class="form-control" placeholder="${field.placeholder || ''}" ${field.requis ? 'required' : ''}>`;
            }

            const formGroupHtml = `
                <div class="mb-3">
                    <label for="form_${field.code}" class="form-label">${field.intitule}</label>
                    ${inputHtml}
                    <div class="invalid-feedback"></div>
                </div>
            `;
            this.formBodyTarget.insertAdjacentHTML('beforeend', formGroupHtml);
        });
        buildCustomEventForElement(document, EVEN_BOITE_DIALOGUE_INITIALIZED, true, true, {});
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
            const response = await fetch(this.canvas.parametres.endpoint_url, {
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