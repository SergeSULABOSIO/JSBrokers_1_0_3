// assets/controllers/form-dialog_controller.js
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { buildCustomEventForElement, EVEN_BOITE_DIALOGUE_INIT_REQUEST, EVEN_BOITE_DIALOGUE_INITIALIZED, EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST } from './base_controller.js';

export default class extends Controller {
    static targets = ["title", "formBody", "feedback", "submitButton"];

    connect() {
        this.nomControleur = "FORM-DIALOG";
        this.modal = new Modal(this.element);

        // --- AJOUTEZ CE BLOC DE DÉBOGAGE ---
        console.log("FORM-DIALOG Controller connected to:", this.element);
        if (!this.hasFormBodyTarget) {
            console.error("ERREUR: La cible 'formBody' est manquante lors de la connexion du contrôleur !");
        }
        // --- FIN DU BLOC DE DÉBOGAGE ---

        
        // Garde une référence à la fonction liée pour le removeEventListener
        this.boundHandleOpenRequest = this.handleOpenRequest.bind(this);
        document.addEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundHandleOpenRequest);
    }

    disconnect() {
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundHandleOpenRequest);
    }

    // Ouvre et prépare la boîte de dialogue
    handleOpenRequest(event) {
        const { entity, entityFormCanvas, context } = event.detail;
        this.entity = entity;
        this.canvas = entityFormCanvas;
        this.context = context || {}; // On stocke le contexte
        console.log(this.nomControleur + " - handleOpenRequest", event.detail);

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
        // Définit le titre (création vs modification)
        let title = isEditMode
            ? this.canvas.parametres.titre_modification.replace('%id%', this.entity.id)
            : this.canvas.parametres.titre_creation;
        this.titleTarget.textContent = title;

        // let url = '/admin/notificationsinistre/api/get-form';
        let url = this.canvas.parametres.endpoint_form_url;
        if (isEditMode) {
            url += `/${this.entity.id}`;
        }

        this.formBodyTarget.innerHTML = '<div class="text-center p-5"><span class="spinner-border"></span></div>'; // Affiche un spinner de chargement

        try {
            const response = await fetch(url);
            if (!response.ok) throw new Error('Erreur réseau lors du chargement du formulaire.');
            const html = await response.text();
            this.formBodyTarget.innerHTML = html;
        } catch (e) {
            this.formBodyTarget.innerHTML = '<div class="alert alert-danger">Impossible de charger le formulaire.</div>';
            console.error(e);        
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

        // --- AJOUT : INJECTER L'ID DU PARENT ---
        if (this.context.notificationSinistreId) {
            data.notificationSinistre = this.context.notificationSinistreId;
        }

        // --- AJOUTEZ CETTE LIGNE DE DÉBOGAGE ---
        console.log(this.nomControleur + " - Données envoyées au serveur :", data);
        // ------------------------------------

        try {
            const response = await fetch(this.canvas.parametres.endpoint_submit_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (response.ok) {
                this.showFeedback('success', result.message);

                // ÉVÉNEMENT 1: Pour rafraîchir la liste principale (ex: table des notifications)
                document.dispatchEvent(new CustomEvent(EVEN_LISTE_PRINCIPALE_REFRESH_REQUEST));

                // NOUVEL ÉVÉNEMENT 2: Pour notifier les autres composants (comme notre collection-manager)
                this.element.dispatchEvent(new CustomEvent('form-dialog:success', {
                    bubbles: true,
                    detail: {
                        message: result.message,
                        entity: result.contact, // L'entité retournée par le serveur
                        submitUrl: this.canvas.parametres.endpoint_submit_url // L'URL qui a été utilisée
                    }
                }));

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
        // VÉRIFICATION : On utilise "has...Target" pour s'assurer que la cible existe
        if (this.hasFormBodyTarget) {
            console.log(this.nomControleur + " - clearFeedback", this.formBodyTarget);
            this.formBodyTarget.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        }
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.className = '';
            this.feedbackTarget.textContent = '';
        }
        
        // this.formBodyTarget.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        // this.feedbackTarget.className = '';
        // this.feedbackTarget.textContent = '';
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