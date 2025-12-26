import BaseController from './base_controller.js';

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
export default class extends BaseController {
    // On déclare un "outlet" pour le contrôleur 'modal' qui gère le cadre.
    static outlets = ['modal'];

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque l'élément du contrôleur est ajouté au DOM.
     * Récupère les données d'initialisation et démarre le processus d'affichage du dialogue.
     * @throws {Error} Si les données d'initialisation (`dialogDetail`) ne sont pas trouvées.
     */
    connect() {
        this.nomControleur = "Dialog-Instance";
        this.elementDialogInstance = this.element;
        const detail = this.elementDialogInstance.dialogDetail;
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [connect] - Code: 1986 - Début - Données:`, detail);
        this.cetteApplication = this.application;

        this.boundHandleContentReady = this.handleContentReady.bind(this);
        document.addEventListener('ui:dialog.content-ready', this.boundHandleContentReady);

        if (detail) {
            // On encapsule l'appel asynchrone pour gérer les erreurs d'initialisation.
            try {
                this.start(detail);
            } catch (error) {
                console.error(`[${this.nomControleur}] Erreur critique lors du démarrage :`, error);
                if (this.hasModalOutlet) this.modalOutlet.hide();
            }
        } else {
            console.error(`[${this.nomControleur}] L'instance de dialogue s'est connectée sans recevoir de données d'initialisation !`);
            if (this.hasModalOutlet) this.modalOutlet.hide();
        }
    }

    /**
     * La méthode disconnect est vide car le nettoyage est maintenant géré par le contrôleur 'modal'.
     */
    disconnect() {
        document.removeEventListener('ui:dialog.content-ready', this.boundHandleContentReady);
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
        this.isCreateMode = !(this.entity && this.entity.id);
        this.context = detail.context || {};
        this.parentContext = detail.parentContext || null;
        this.formTemplateHTML = detail.formTemplateHTML || null;
        this.dialogId = detail.dialogId; // On stocke l'ID unique
        this._logState('start', '1986', detail);

        // Charge le contenu complet depuis le serveur
        this.loadContent();
    }


    /**
     * Charge le contenu HTML du formulaire et des attributs calculés depuis le serveur via AJAX.
     * Gère le cas de la création (ID 0) et de l'édition.
     * Notifie le cerveau une fois le chargement terminé.
     * @private
     */
    loadContent() {
        this._logState("loadContent", "1986", this.detail);
        console.log(`${this.nomControleur} - loadContent() - Demande de contenu pour ${this.dialogId}`);
        // NOUVEAU : On affiche le squelette de chargement pour une meilleure UX.
        this.elementDialogInstance.innerHTML = this._getSkeletonHtml();

        // Prépare les informations pour la requête que le Cerveau va exécuter
        const payload = {
            dialogId: this.dialogId,
            endpoint: this.entityFormCanvas.parametres.endpoint_form_url,
            entity: this.entity,
            context: this.context
        };

        // Notifie le cerveau pour qu'il charge le contenu
        this.notifyCerveau('ui:dialog.content-request', payload);
    }

    /**
     * NOUVEAU: Gère la réception du contenu HTML envoyé par le Cerveau.
     * @param {CustomEvent} event 
     */
    handleContentReady(event) {
        const { dialogId, html, error } = event.detail;

        // On s'assure que cet événement nous est bien destiné
        if (dialogId !== this.dialogId) {
            return;
        }

        console.log(`${this.nomControleur} - handleContentReady() - Contenu reçu pour ${this.dialogId}`);

        if (error) {
            const errorMessage = error.message || "Une erreur inconnue est survenue.";
            this.elementDialogInstance.innerHTML = `<div class="modal-body"><div class="alert alert-danger">${errorMessage}</div></div>`;
            // Notifier le cerveau de l'échec de chargement
            this.notifyCerveau('app:error.api', {
                error: `Échec du chargement du formulaire: ${errorMessage}`
            });
            return;
        }

        // On remplace tout le contenu de la modale par le HTML reçu.
        this.elementDialogInstance.innerHTML = html;

        // On attache l'action de soumission au nouveau formulaire qui vient d'être injecté.
        const form = this.elementDialogInstance.querySelector('form');
        if (form) {
            form.setAttribute('data-action', 'submit->dialog-instance#submitForm');
        }

        const mainDialogElement = this.modalOutlet.element;

        // On vérifie si le contenu retourné contient des attributs calculés pour ajuster la classe CSS.
        const hasCalculatedAttrs = this.elementDialogInstance.querySelector('.calculated-attributes-list li');
        if (hasCalculatedAttrs) {
            mainDialogElement.classList.add('has-attributes-column');
        } else {
            mainDialogElement.classList.remove('has-attributes-column');
        }

        // NOUVEAU : Notifier le cerveau que le dialogue est prêt et affiché.
        this.notifyCerveau('ui:dialog.opened', {
            mode: this.isCreateMode ? 'creation' : 'edition',
            entity: this.entity
        });

        // On s'assure que la classe de mode édition est bien présente si nécessaire
        if (!this.isCreateMode) {
            mainDialogElement.classList.add('is-edit-mode');
        }
    }

    /**
     * Recharge la vue complète (titre, formulaire, attributs) après une création réussie
     * pour passer en mode édition sans fermer la modale.
     * @private
     */
    reloadView() {
        this.modalOutlet.element.classList.add('is-edit-mode'); // Affiche la colonne de gauche
        this.loadContent(); // Redemande le contenu au Cerveau
    }

    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        console.log(this.nomControleur + " - submitForm() - Code:1986");
        event.preventDefault();
        this.toggleLoading(true);
        this.toggleProgressBar(true);
        // this.clearErrors(); // On nettoie les anciennes erreurs

        this.feedbackContainer = this.element.querySelector('.feedback-container');
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
        // NOUVEAU : On ajoute le parent s'il a été fourni par le cerveau.
        // Cela est utilisé pour lier un contact à une notification, par exemple.
        if (this.parentContext && this.parentContext.id && this.parentContext.fieldName) {
            formData.append(this.parentContext.fieldName, this.parentContext.id);
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
     * NOUVEAU : Génère le HTML pour un squelette de chargement du formulaire.
     * @returns {string} Le HTML du squelette.
     * @private
     */
    _getSkeletonHtml() {
        return `
            <div class="modal-header">
                <button type="button" class="btn-close btn-close-white" disabled></button>
            </div>
            <div class="dialog-progress-container is-loading">
                <div class="dialog-progress-bar" role="progressbar"></div>
            </div>
            <div class="modal-body-split">
                <div class="calculated-attributes-column">
                    <div class="skeleton-line" style="height: 40px;"></div>
                    <div class="skeleton-line" style="height: 40px;"></div>
                </div>
                <div class="form-column">
                    <div class="skeleton-line" style="width: 100%; height: 40px; margin: 2px">Veuillez patienter svp...</div>
                    <div class="skeleton-line" style="width: 100%; height: 40px; padding: 5px; margin: 2px;"></div>
                    <div class="skeleton-line" style="width: 100%; height: 40px; padding: 5px; margin: 2px;"></div>
                    <div class="skeleton-line" style="width: 100%; height: 40px; padding: 5px; margin: 2px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="skeleton-line" style="width: 120px; height: 40px; border-radius: var(--bs-border-radius);"></div>
                <div class="skeleton-line" style="width: 120px; height: 40px; border-radius: var(--bs-border-radius);"></div>
            </div>
        `;
    }

    /**
     * Affiche un message de feedback (succès, erreur) horodaté dans le pied de page de la modale.
     * @param {'success'|'error'|'warning'} type - Le type de message.
     * @param {string} message - Le message à afficher.
     */
    showFeedback(type, message) {
        const feedbackContainer = this.elementDialogInstance.querySelector('.feedback-container');
        if (!feedbackContainer) return;

        // On formate la date et l'heure actuelles [cite: 7, 8]
        const now = new Date();
        const date = now.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
        const time = now.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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
     * Affiche les erreurs de validation renvoyées par le serveur
     * à côté des champs de formulaire correspondants.
     * @param {object} errors - Un objet où les clés sont les noms des champs et les valeurs sont les messages d'erreur.
     */
    displayErrors(errors) {
        // --- CORRECTION : S'assurer que la cible du feedback est définie ---
        this.feedbackContainer = this.elementDialogInstance.querySelector('.feedback-container');

        const form = this.elementDialogInstance.querySelector('form');
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
     * Ferme la modale et notifie le cerveau de cette action.
     */
    close() {
        // NOUVEAU : Notifier le cerveau de la fermeture
        this.notifyCerveau('ui:dialog.closed', {
            entity: this.entity,
            mode: this.isCreateMode ? 'creation' : 'edition'
        });
        this.toggleProgressBar(false); // <-- CACHER LA BARRE avant de fermer
        this.modalOutlet.hide(); // On demande au contrôleur 'modal' de se fermer.
    }

    /**
     * Gère l'état visuel du bouton de soumission et des autres boutons (chargement/normal).
     * @param {boolean} isLoading - `true` pour afficher l'état de chargement, `false` sinon.
     */
    toggleLoading(isLoading) {
        // On cherche le bouton manuellement juste quand on en a besoin
        // const button = this.element.querySelector('button[type="submit"]');
        const button = this.elementDialogInstance.querySelector('[data-action*="#triggerSubmit"]');

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
        const closeButtons = this.elementDialogInstance.querySelectorAll('[data-action*="#close"]');
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
        const progressBarContainer = this.elementDialogInstance.querySelector('.dialog-progress-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Déclenche manuellement la soumission du formulaire interne.
     */
    triggerSubmit() {
        const form = this.elementDialogInstance.querySelector('form');
        if (form) {
            form.requestSubmit();
        }
    }

    /**
     * Affiche l'état des variables vitales du dialogue dans la console pour le débogage.
     * @param {string} callingFunction - Le nom de la fonction qui appelle ce logger.
     * @param {string} code - Un code de suivi pour filtrer les logs.
     * @param {object} detail - L'objet contenant les données à logger.
     * @private
     */
    _logState(callingFunction, code, detail) {
        if (detail) {
            var isCreateMode = true;
            if (detail.entity) {
                if (detail.entity.id) {
                    isCreateMode = false;
                }
            }
            console.groupCollapsed(`${this.nomControleur} - ${callingFunction}() - Code:${code}`);
            console.log(`| Mode:`, (isCreateMode) ? 'Création' : 'Édition');
            console.log(`| Entité:`, detail.entity);
            console.log(`| Contexte:`, detail.context);
            console.log(`| Canvas:`, detail.entityFormCanvas);
            console.groupEnd();
        }

    }
}