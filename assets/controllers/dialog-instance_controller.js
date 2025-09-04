import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Ce contrôleur gère le cycle de vie d'UNE SEULE instance de dialogue.
 * Il est créé dynamiquement par 'dialog-manager' et se détruit à la fermeture.
 */
export default class extends Controller {

    connect() {
        this.nomControlleur = "Dialog-Instance";
        const detail = this.element.dialogDetail;
        this.elementContenu = this.element;
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);
        if (detail) {
            this.start(detail);
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
        this.isCreateMode = !(this.entity && this.entity.id);
        console.log(this.nomControlleur + " - start:", detail, "isCreateMode: " + this.isCreateMode);
        await this.buildAndShowShell();
        await this.loadFormAndAttributes();
    }


    async buildAndShowShell() {
        const title = this.isCreateMode
            ? this.canvas.parametres.titre_creation
            : this.canvas.parametres.titre_modification.replace('%id%', this.entity.id);

        this.elementContenu.innerHTML = `
            <form data-action="submit->dialog-instance#submitForm">
                <div class="modal-header">
                    <h5 class="modal-title">${title}</h5>
                    <button type="button" class="btn-close btn-close-white" data-action="click->dialog-instance#close"></button>
                </div>
                <div class="dialog-progress-container" data-dialog-instance-target="progressBarContainer">
                    <div class="dialog-progress-bar" role="progressbar"></div>
                </div>
                <div class="modal-body-split">
                    <div class="calculated-attributes-column">
                        <div class="text-center p-5">
                            <div class="spinner-border text-light spinner-border-sm"></div>
                        </div>
                    </div>
                    <div class="form-column">
                        <div class="text-center p-5">
                            <div class="spinner-border" style="width: 3rem; height: 3rem;" role="status">
                                <span class="visually-hidden">Chargement...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="feedback-container w-100 text-danger mb-2" data-dialog-instance-target="feedback"></div>
                    <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close">
                        <svg xmlns="http://www.w3.org/2000/svg" width="25px" height="25px" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8zm3.59-13L12 10.59L8.41 7L7 8.41L10.59 12L7 15.59L8.41 17L12 13.41L15.59 17L17 15.59L13.41 12L17 8.41z"></path></svg>
                        <span>Fermer</span>
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
        // On ajoute la classe pour le mode édition si nécessaire
        if (!this.isCreateMode) {
            this.modalNode.classList.add('is-edit-mode');
        }
        this.modalNode.addEventListener('hidden.bs.modal', () => { this.modalNode.remove(); });
        this.modalNode.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
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
    async loadFormAndAttributes() {//loadFormBody() {
        try {
            // 1. On commence avec l'URL de base
            let urlString = this.canvas.parametres.endpoint_form_url;

            // 2. Si c'est une édition, on ajoute l'ID à l'URL
            if (this.entity && this.entity.id) {
                urlString += `/${this.entity.id}`;
            }

            // 3. On crée un objet URL pour gérer facilement les paramètres
            const url = new URL(urlString, window.location.origin);

            // 4. Si une valeur par défaut a été passée dans le contexte, on l'ajoute
            if (this.context.defaultValue) {
                url.searchParams.set(`default_${this.context.defaultValue.target}`, this.context.defaultValue.value);
            }

            // 5. On lance la requête avec l'URL finale correctement construite
            const finalUrl = url.pathname + url.search;
            console.log(this.nomControlleur + " - URL de chargement du formulaire:", finalUrl); // Pour débogage

            const response = await fetch(finalUrl);
            if (!response.ok) throw new Error("Le formulaire n'a pas pu être chargé.");

            const html = await response.text();
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const attributesContent = doc.querySelector('.calculated-attributes-column-content');
            const formContent = doc.querySelector('.form-column-content');

            const attributesContainer = this.elementContenu.querySelector('.calculated-attributes-column');
            const formContainer = this.elementContenu.querySelector('.form-column');
            const mainDialogElement = this.elementContenu.closest('.app-dialog');

            if (formContent && formContainer) {
                formContainer.innerHTML = '';
                formContainer.appendChild(formContent);
            }
            if (attributesContent && attributesContainer) {
                const hasCalculatedAttrs = attributesContent.querySelector('.calculated-attributes-list li');
                if (hasCalculatedAttrs) {
                    attributesContainer.innerHTML = '';
                    attributesContainer.appendChild(attributesContent);
                    mainDialogElement.classList.add('has-attributes-column');
                } else {
                    mainDialogElement.classList.remove('has-attributes-column');
                }
            }
            // On s'assure que la classe de mode édition est bien présente si nécessaire
            if (!this.isCreateMode) {
                mainDialogElement.classList.add('is-edit-mode');
            }


            // if (attributesContent && attributesContainer) {
            //     attributesContainer.innerHTML = '';
            //     attributesContainer.appendChild(attributesContent);
            // }
            // this.elementContenu.querySelector('.modal-body').innerHTML = await response.text();
        } catch (error) {
            this.elementContenu.querySelector('.form-column').innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
            // this.elementContenu.querySelector('.modal-body').innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
        }
    }

    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        event.preventDefault();
        this.toggleLoading(true);
        this.toggleProgressBar(true);
        // this.clearErrors(); // On nettoie les anciennes erreurs

        this.feedbackContainer = this.elementContenu.querySelector('.feedback-container');
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

            this.showFeedback('success', result.message); // On affiche le message de succès
            document.dispatchEvent(new CustomEvent('collection-manager:refresh-list', { detail: { originatorId: this.context.originatorId } }));
            document.dispatchEvent(new CustomEvent('main-list:refresh-request'));

            if (this.isCreateMode && result.entity) {
                this.entity = result.entity; // On stocke la nouvelle entité avec son ID
                this.isCreateMode = false;
                // await this.reloadForm(); // On recharge le formulaire
                await this.reloadView(); // ON APPELLE NOTRE NOUVELLE FONCTION DE RECHARGEMENT
            }
            // Si on était déjà en mode édition, on ne fait rien de plus.

        } catch (error) {
            if (this.feedbackContainer) {
                this.feedbackContainer.textContent = error.message || 'Une erreur est survenue.';
                this.showFeedback('success', error.message);
            }
            if (error.errors) {
                this.displayErrors(error.errors);
            }
        } finally {
            // S'assure que la barre disparaît dans tous les cas
            this.toggleLoading(false);
            this.toggleProgressBar(false);
        }
    }

    /**
     * NOUVEAU : Recharge la vue complète (titre + colonnes)
     */
    async reloadView() {
        this.updateTitle();
        this.modalNode.classList.add('is-edit-mode'); // Affiche la colonne de gauche
        await this.loadFormAndAttributes(); // Recharge le formulaire et les attributs
    }

    /**
     * NOUVEAU : Affiche un message stylisé dans le conteneur de feedback.
     */
    showFeedback(type, message) {
        const feedbackContainer = this.elementContenu.querySelector('.feedback-container');
        if (!feedbackContainer) return;

        // On formate la date et l'heure actuelles [cite: 7, 8]
        const now = new Date();
        const date = now.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const time = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        const timestamp = `Dernière mise à jour le ${date} à ${time} ::`;

        // On détermine la classe CSS à utiliser en fonction du type
        let feedbackClass = '';
        switch (type) {
            case 'success':
                feedbackClass = 'feedback-success'; // [cite: 5]
                break;
            case 'error':
                feedbackClass = 'feedback-error'; // [cite: 4]
                break;
            case 'warning':
                feedbackClass = 'feedback-warning'; // [cite: 6]
                break;
        }

        // On crée le message HTML et on l'ajoute au conteneur
        feedbackContainer.innerHTML = `
            <div class="feedback-message ${feedbackClass}">
                <span class="timestamp">${timestamp}</span>
                <span>${message}</span>
            </div>
        `;
    }


    /**
     * NOUVEAU : Recharge le formulaire pour refléter le nouvel état (création -> édition).
     */
    async reloadForm() {
        this.updateTitle(); // Met à jour le titre
        await this.loadFormBody(); // Recharge le corps du formulaire (avec les collections activées)
    }

    /**
     * NOUVEAU : Met à jour le titre de la boîte de dialogue.
     */
    updateTitle() {
        const titleElement = this.elementContenu.querySelector('.modal-title');
        if (titleElement) {
            titleElement.textContent = this.canvas.parametres.titre_modification.replace('%id%', this.entity.id);
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
        const progressBarContainer = this.elementContenu.querySelector('.dialog-progress-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }
}