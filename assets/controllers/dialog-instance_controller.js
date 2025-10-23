import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { buildCustomEventForElement } from './base_controller.js';

/**
 * @file Ce fichier contient le contrôleur Stimulus 'dialog-instance'.
 * @description Ce contrôleur gère le cycle de vie et les interactions d'UNE SEULE instance de dialogue.
 * Il est conçu pour être créé dynamiquement par 'dialog-manager'. Il s'occupe de construire la modale,
 * de charger son contenu (formulaire, attributs) via AJAX, de gérer la soumission du formulaire,
 * et de communiquer les résultats au 'cerveau' de l'application. Il se détruit automatiquement
 * lorsque la modale est fermée.
 */

/**
 * @class DialogInstanceController
 * @extends Controller
 * @description Gère une instance unique et éphémère de boîte de dialogue.
 */
export default class extends Controller {

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque l'élément du contrôleur est ajouté au DOM.
     * Récupère les données d'initialisation et démarre le processus d'affichage du dialogue.
     * @throws {Error} Si les données d'initialisation (`dialogDetail`) ne sont pas trouvées.
     */
    connect() {
        this.nomControlleur = "Dialog-Instance";
        const detail = this.element.dialogDetail;
        this.elementContenu = this.element;
        this.cetteApplication = this.application;
        this.boundAdjustZIndex = this.adjustZIndex.bind(this);
        if (detail) {
            this.start(detail);
        } else {
            console.error("L'instance de dialogue s'est connectée sans recevoir de données d'initialisation !");
        }
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * Nettoie les écouteurs d'événements lorsque le contrôleur est retiré du DOM.
     */
    disconnect() {
        if (this.modalNode) {
            this.modalNode.removeEventListener('shown.bs.modal', this.boundAdjustZIndex);
        }
    }

    /**
     * Point d'entrée principal. Initialise les propriétés, affiche la coquille de la modale,
     * puis charge le contenu dynamique.
     * @param {object} detail - L'objet de configuration passé par `dialog-manager`.
     * @param {object} detail.entityFormCanvas - La configuration (canvas) du formulaire.
     * @param {object} detail.entity - L'entité à éditer, ou un objet vide pour une création.
     */
    async start(detail) {
        this.entityFormCanvas = detail.entityFormCanvas;
        this.entity = detail.entity;
        this.context = detail.context || {};
        this.formTemplateHTML = detail.formTemplateHTML || null; // Récupère le HTML pré-rendu si disponible
        this.isCreateMode = !(this.entity && this.entity.id);

        // Log de démarrage détaillé
        console.groupCollapsed(`${this.nomControlleur} - Verbalisation - start - EDITDIAL(3)`);
        console.log(`| Mode: ${detail.isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', detail.entity);
        console.log('| Contexte:', detail.context);
        console.log('| Canvas:', detail.entityFormCanvas);
        console.groupEnd();

        console.log(this.nomControlleur + " - start:", detail, "isCreateMode: " + this.isCreateMode);
        await this.buildAndShowShell();
        await this.loadFormAndAttributes();
    }

    /**
     * Construit et affiche la structure de base (coquille) de la modale Bootstrap,
     * avec des indicateurs de chargement.
     * @private
     */
    async buildAndShowShell() {
        const title = this.isCreateMode
            ? this.entityFormCanvas.parametres.titre_creation
            : this.entityFormCanvas.parametres.titre_modification.replace('%id%', this.entity.id);

        // --- MODIFICATION : On retire la balise <form> d'ici ---
        // On met l'action de soumission sur l'élément racine du contrôleur
        this.elementContenu.setAttribute('data-action', 'submit->dialog-instance#submitForm');


        this.elementContenu.innerHTML = `
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
                <div class="feedback-container w-100 mb-2" data-dialog-instance-target="feedback">
                    ${this.isCreateMode ? `
                        <div class="alert alert-info d-flex align-items-center p-2" role="alert" style="font-size: 0.85rem; border-left: 5px solid #0dcaf0;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-info-circle-fill flex-shrink-0 me-2" viewBox="0 0 16 16"><path d="M8 16A8 8 0 1 0 8 0a8 8 0 0 0 0 16zm.93-9.412-1 4.705c-.07.34.029.533.304.533.194 0 .487-.07.686-.246l-.088.416c-.287.346-.92.598-1.465.598-.703 0-1.002-.422-.808-1.319l.738-3.468c.064-.293.006-.399-.287-.47l-.451-.081.082-.381 2.29-.287zM8 5.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2z"/></svg>
                            <div>Les champs de type collection (pièces jointes, tâches, etc.) seront éditables après le premier enregistrement.</div>
                        </div>
                    ` : ''}
                </div>
                <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="25px" height="25px" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8zm3.59-13L12 10.59L8.41 7L7 8.41L10.59 12L7 15.59L8.41 17L12 13.41L15.59 17L17 15.59L13.41 12L17 8.41z"></path></svg>
                    <span>Fermer</span>
                </button>
                <button type="button" class="btn btn-primary" data-action="click->dialog-instance#triggerSubmit" data-dialog-instance-target="submitButton">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                    <span class="button-icon"><svg xmlns="http://www.w3.org/2000/svg" width="23px" height="23px" viewBox="0 0 24 24" fill="currentColor"><path d="M15.004 3h-10a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-10L15.004 3zm-9 16V6h8v4h4v9h-12z"></path></svg></span>
                    <span class="button-text">Enregistrer</span>
                </button>
            </div>
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
     * Ajuste le `z-index` de la modale pour s'assurer qu'elle apparaît
     * au-dessus des autres modales déjà ouvertes. Essentiel pour les dialogues imbriqués.
     * @private
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
     * Charge le contenu HTML du formulaire et des attributs calculés depuis le serveur via AJAX.
     * Gère le cas de la création (ID 0) et de l'édition.
     * Notifie le cerveau une fois le chargement terminé.
     * @private
     */
    async loadFormAndAttributes() {//loadFormBody() {
        console.log(this.nomControlleur + " - loadFormAndAttributes() - Code:1986");
        try {
            // 1. On commence avec l'URL de base
            let urlString = this.entityFormCanvas.parametres.endpoint_form_url;

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

            // MISSION 3 : Ajouter idEntreprise et idInvite au chargement du formulaire
            if (this.context.idEntreprise) {
                url.searchParams.set('idEntreprise', this.context.idEntreprise);
            }
            if (this.context.idInvite) {
                url.searchParams.set('idInvite', this.context.idInvite);
            }
            // Note : idInvite n'est pas toujours nécessaire ici, mais on peut l'ajouter par cohérence.

            // 5. On lance la requête avec l'URL finale correctement construite
            const finalUrl = url.pathname + url.search;
            // console.log(this.nomControlleur + " - URL de chargement du formulaire:", finalUrl); // Pour débogage

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

            //On redessine la colonne du formulaire
            if (formContent && formContainer) {
                formContainer.innerHTML = '';
                formContainer.appendChild(formContent);
            }
            //On redessine la colonne des attributs calculables
            if (attributesContent && attributesContainer) {
                const hasCalculatedAttrs = attributesContent.querySelector('.calculated-attributes-list li');
                if (hasCalculatedAttrs) {
                    attributesContainer.innerHTML = '';
                    attributesContainer.appendChild(attributesContent);
                    mainDialogElement.classList.add('has-attributes-column');
                } else {
                    mainDialogElement.classList.remove('has-attributes-column');
                }

                // NOUVEAU : Notifier le cerveau que le dialogue est prêt
                this.notifyCerveau('ui:dialog.opened', {
                    mode: this.isCreateMode ? 'creation' : 'edition',
                    entity: this.entity
                });
            }
            // On s'assure que la classe de mode édition est bien présente si nécessaire
            if (!this.isCreateMode) {
                mainDialogElement.classList.add('is-edit-mode');
            }
            // // CORRECTION : Propager le contexte aux collections dès le premier chargement en mode édition.
            // if (!this.isCreateMode) {
            //     this.propagateContextToCollections();
            // }
        } catch (error) {
            const errorMessage = error.message || "Une erreur inconnue est survenue.";
            this.elementContenu.querySelector('.form-column').innerHTML = `<div class="alert alert-danger">${errorMessage}</div>`;
            // NOUVEAU : Notifier le cerveau de l'échec de chargement
            this.notifyCerveau('app:error.api', {
                error: `Échec du chargement du formulaire: ${errorMessage}`
            });
        }
    }

    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        console.log(this.nomControlleur + " - submitForm() - Code:1986");
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
            for (const [key, rawValue] of Object.entries(this.context)) {
                // CORRECTION : Gestion des valeurs complexes dans le contexte.
                // Si la valeur est un objet avec un 'id', on prend l'id.
                // Sinon, on prend la valeur brute. Cela évite d'envoyer "[object Object]".
                let valueToAppend = rawValue;
                if (typeof rawValue === 'object' && rawValue !== null && 'id' in rawValue) {
                    valueToAppend = rawValue.id;
                }

                formData.append(key, valueToAppend);
            }
        }
        // console.log(`${this.nomControlleur} - SubmitForm - PARENT - ATTRIBUT AND ID:`, this.context);
        // console.log(this.nomControlleur + " - Submit vers le serveur: " + this.entityFormCanvas.parametres.endpoint_submit_url, this.context);
        try {
            const response = await fetch(this.entityFormCanvas.parametres.endpoint_submit_url, {
                method: 'POST',
                body: formData // On envoie l'objet FormData directement.
            });
            const result = await response.json();
            if (!response.ok) throw result;

            this.showFeedback('success', result.message);
            console.log(this.nomControlleur + " submitForm (réponse du serveur) - (0/5) Actualisation de la Collection:", result.entity, this.context.originatorId);

            // NOUVEAU : Notifier le cerveau du succès de l'enregistrement
            this.notifyCerveau('app:entity.saved', {
                entity: result.entity,
                originatorId: this.context.originatorId // Pour savoir quelle collection rafraîchir
            });

            if (result.entity) {
                this.entity = result.entity; // On stocke la nouvelle entité avec son ID
                this.isCreateMode = false;

                await this.reloadView(); // ON APPELLE NOTRE NOUVELLE FONCTION DE RECHARGEMENT
            } else {
                // Si on est déjà en mode édition, on rafraîchit juste les listes
                // sans recharger toute la vue.
                this.notifyCerveau('app:list.refresh-request', {
                    originatorId: this.context.originatorId
                });
            }

        } catch (error) {
            console.error(error);
            // NOUVEAU : Notifier le cerveau de l'échec de validation
            this.notifyCerveau('app:form.validation-error', {
                message: error.message || 'Erreur de validation',
                errors: error.errors || {}
            });

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
     * Recharge la vue complète (titre, formulaire, attributs) après une création réussie
     * pour passer en mode édition sans fermer la modale.
     * @private
     */
    async reloadView() {
        console.log(this.nomControlleur + " - reloadView() - Code:1986");
        this.updateTitle();
        this.modalNode.classList.add('is-edit-mode'); // Affiche la colonne de gauche
        await this.loadFormAndAttributes(); // Recharge le formulaire et les attributs
        this.propagateContextToCollections(); // On propage le contexte aux nouvelles collections
    }

    /**
     * Parcourt toutes les collections chargées dans la modale, les active
     * et leur transmet le contexte du dialogue actuel.
     * @private
     */
    propagateContextToCollections() {
        const collectionElements = this.elementContenu.querySelectorAll('[data-controller="collection"]');
        collectionElements.forEach(element => {
            const controller = this.cetteApplication.getControllerForElementAndIdentifier(element, 'collection');
            if (controller && this.entity && this.entity.id) {
                console.log(this.nomControlleur + " - propagateContextToCollections() - Code:1986 - Transmission à " + element.id);
                // On transmet le contexte du dialogue parent (ex: {notificationSinistre: 123})
                // à la collection enfant (ex: la collection de Tâches).
                if (this.context) {
                    // console.log(`${this.nomControlleur} - propagateContextToCollections() - Code:1986 - Transmission du contexte à la collection enfant '${element.id}':`, this.context, element);
                    Object.assign(controller.contextValue, this.context);
                }
                // On active la collection avec l'ID de l'entité actuelle (ex: OffreIndemnisation)
                controller.enableAndLoad(this.entity.id);
                console.log(this.nomControlleur + " - propagateContextToCollections() - Code:1986 - Transmission à " + element.id + " terminée.");
            }
        });
    }

    /**
     * Affiche un message de feedback (succès, erreur) horodaté dans le pied de page de la modale.
     * @param {'success'|'error'|'warning'} type - Le type de message.
     * @param {string} message - Le message à afficher.
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
     * Met à jour le titre de la boîte de dialogue, typiquement après être passé
     * du mode création au mode édition.
     * @private
     */
    updateTitle() {
        const titleElement = this.elementContenu.querySelector('.modal-title');
        console.log(this.nomControlleur + " - (1) updateTitle() - titleElement:", titleElement);
        console.log(this.nomControlleur + " - (2) updateTitle() - this.entityFormCanvas.parametres:", this.entityFormCanvas.parametres);
        console.log(this.nomControlleur + " - (3) updateTitle() - titre_modification:", this.entityFormCanvas.parametres.titre_modification);
        if (titleElement) {
            titleElement.textContent = this.entityFormCanvas.parametres.titre_modification.replace('%id%', this.entity.id);
            console.log(this.nomControlleur + " - (4) updateTitle() - textContent:", titleElement.textContent);
        }
    }

    /**
     * Affiche les erreurs de validation renvoyées par le serveur
     * à côté des champs de formulaire correspondants.
     * @param {object} errors - Un objet où les clés sont les noms des champs et les valeurs sont les messages d'erreur.
     */
    displayErrors(errors) {
        // --- CORRECTION : S'assurer que la cible du feedback est définie ---
        this.feedbackContainer = this.elementContenu.querySelector('.feedback-container');

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
     * Nettoie les messages d'erreur et les styles d'invalidité du formulaire.
     * @private
     */    
    clearErrors() {
        this.elementContenu.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        this.elementContenu.querySelectorAll('.invalid-feedback').forEach(el => el.remove());
        this.feedbackContainer = this.elementContenu.querySelector('.feedback-container');
        if (this.feedbackContainer) this.feedbackContainer.innerHTML = '';
    }


    /**
     * Ferme la modale et notifie le cerveau de cette action.
     */
    close() {
        // NOUVEAU : Notifier le cerveau de la fermeture
        this.notifyCerveau('ui:dialog.closed', {
            entity: this.entity,
            mode: this.isCreateMode ? 'creation' : 'edition'
        });
        this.toggleProgressBar(false); // <-- CACHER LA BARRE avant de fermer
        this.modal.hide();
    }

    /**
     * Gère l'état visuel du bouton de soumission et des autres boutons (chargement/normal).
     * @param {boolean} isLoading - `true` pour afficher l'état de chargement, `false` sinon.
     */
    toggleLoading(isLoading) {
        // On cherche le bouton manuellement juste quand on en a besoin
        // const button = this.elementContenu.querySelector('button[type="submit"]');
        const button = this.elementContenu.querySelector('[data-action*="#triggerSubmit"]');

        if (!button) return;
        button.disabled = isLoading;
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
        // --- AJOUT : Gère les autres boutons (Fermer, X) ---
        const closeButtons = this.elementContenu.querySelectorAll('[data-action*="#close"]');
        closeButtons.forEach(btn => {
            btn.disabled = isLoading;
        });
    }

    /**
     * Affiche ou cache la barre de progression en haut de la modale.
     * @param {boolean} isLoading - `true` pour afficher la barre, `false` pour la cacher.
     */
    toggleProgressBar(isLoading) {
        // On cherche le conteneur de la barre manuellement
        const progressBarContainer = this.elementContenu.querySelector('.dialog-progress-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Déclenche manuellement la soumission du formulaire interne.
     */
    triggerSubmit() {
        const form = this.elementContenu.querySelector('form');
        if (form) {
            form.requestSubmit();
        }
    }

    /**
     * Méthode centralisée pour envoyer un événement au cerveau.
     * @param {string} type - Le type d'événement pour le cerveau (ex: 'ui:dialog.opened').
     * @param {object} payload - Données additionnelles à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        buildCustomEventForElement(document, 'cerveau:event', true, true, {
            type: type,
            source: this.nomControlleur,
            payload: payload,
            timestamp: Date.now()
        });
    }
}