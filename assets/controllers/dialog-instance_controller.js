import { Controller } from '@hotwired/stimulus';
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
    // On déclare un "outlet" pour le contrôleur 'modal' qui gère le cadre.
    static outlets = ['modal'];
    static targets = [ // NOUVEAU : Ajout des cibles pour la visibilité dynamique
        'content', 'formRow', 'dynamicFieldContainer'
    ];
    

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque l'élément du contrôleur est ajouté au DOM.
     * Récupère les données d'initialisation et démarre le processus d'affichage du dialogue.
     * @throws {Error} Si les données d'initialisation (`dialogDetail`) ne sont pas trouvées.
     */
    connect() {
        this.nomControleur = "Dialog-Instance";
        const detail = this.element.dialogDetail;
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [connect] - Code: 1986 - Début - Données:`, detail, this.element, this.contentTarget);
        this.cetteApplication = this.application;

        this.boundHandleContentReady = this.handleContentReady.bind(this);
        document.addEventListener('ui:dialog.content-ready', this.boundHandleContentReady);
        // NOUVEAU : Écouteur pour la demande de fermeture ciblée venant du cerveau.
        this.boundDoClose = this.doClose.bind(this);
        document.addEventListener('app:dialog.do-close', this.boundDoClose);

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
        document.removeEventListener('app:dialog.do-close', this.boundDoClose);
    }

    /**
     * Point d'entrée principal. Initialise les propriétés, affiche la coquille de la modale,
     * puis charge le contenu dynamique.
     * @param {object} detail - L'objet de configuration passé par `dialog-manager`.
     * @param {object} detail.entityFormCanvas - La configuration (canvas) du formulaire.
     * @param {object} detail.entity - L'entité à éditer, ou un objet vide pour une création.
     */
    start(detail) {
        this.dialogId = detail.dialogId; // On stocke l'ID unique
        this.entityFormCanvas = detail.entityFormCanvas;
        this.entity = detail.entity;
        this.userContext = detail.context || {}; //La variable context fourni à ce controlleur l'ID de l'entreprise et l'ID de l'invité
        this.isCreateMode = !(this.entity && this.entity.id);
        this.parentContext = detail.parentContext || null;
        this.formTemplateHTML = detail.formTemplateHTML || null;
        this._logState('start', '1986', detail);
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [start] - Code: 1986 - Start - Context:`, detail.context, this.context);
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
        this.contentTarget.innerHTML = this._getSkeletonHtml();

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
            this.contentTarget.innerHTML = `<div class="modal-body"><div class="alert alert-danger">${errorMessage}</div></div>`;
            // Notifier le cerveau de l'échec de chargement
            this.notifyCerveau('app:error.api', {
                error: `Échec du chargement du formulaire: ${errorMessage}`
            });
            return;
        }

        // On remplace tout le contenu de la modale par le HTML reçu.
        this.contentTarget.innerHTML = html;

        // On attache l'action de soumission au nouveau formulaire qui vient d'être injecté.
        const form = this.contentTarget.querySelector('form');
        if (form) {
            form.setAttribute('data-action', 'submit->dialog-instance#submitForm');
        }

        // NOUVEAU : Initialiser la logique de visibilité dynamique du formulaire
        this.initializeFormVisibility();

        const mainDialogElement = this.modalOutlet.element;

        // On vérifie si le contenu retourné contient des attributs calculés pour ajuster la classe CSS.
        const hasCalculatedAttrs = this.contentTarget.querySelector('.calculated-attributes-list li');
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
     * Notifie le Cerveau en envoyant un événement personnalisé.
     * @param {string} type - Le type d'événement pour le Cerveau (ex: 'ui:selection.updated').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true, detail: { type, source: this.nomControleur || 'Unknown', payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }

    /**
     * Gère la soumission du formulaire via AJAX.
     */
    async submitForm(event) {
        event.preventDefault();
        this.toggleLoading(true);
        this.toggleProgressBar(true);
 
        this.feedbackContainer = this.contentTarget.querySelector('.feedback-container');
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
        if (this.userContext) {
            for (const [key, rawValue] of Object.entries(this.userContext)) {
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
 
        try {
            const response = await fetch(this.entityFormCanvas.parametres.endpoint_submit_url, {
                method: 'POST',
                body: formData // On envoie l'objet FormData directement.
            });
            const result = await response.json();
            if (!response.ok) throw result;
 
            // On notifie le cerveau du succès. C'est lui qui décidera qui doit être rafraîchi.
            this.notifyCerveau('app:entity.saved', {
                entity: result.entity,
                originatorId: this.userContext.originatorId // On passe l'ID de la collection qui a initié l'action.
            });
 
            // Cas 1 : C'était une CRÉATION. On reste dans la modale et on la recharge en mode ÉDITION.
            if (this.isCreateMode && result.entity) {
                this.entity = result.entity; // On stocke la nouvelle entité avec son ID
                this.isCreateMode = false;
                this.showFeedback('success', result.message); // On affiche le message de succès
                await this.reloadView(); // On recharge le contenu de la modale (formulaire, etc.)
            } else {
                // Cas 2 : C'était une ÉDITION. On ferme simplement la modale.
                // Le rafraîchissement de la collection est déjà géré par l'événement 'app:entity.saved'.
                // this.close();
            }
 
        } catch (error) {
            console.error(error);
            // NOUVEAU : Notifier le cerveau de l'échec de validation
            this.notifyCerveau('app:form.validation-error', {
                message: error.message || 'Erreur de validation',
                errors: error.errors || {}
            });
 
            this.showFeedback('error', error.message || 'Une erreur est survenue.');
 
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
                <h5 class="modal-title"><div class="skeleton-line" style="width: 250px; height: 24px;"></div></h5>
                <button type="button" class="btn-close btn-close-white" disabled></button>
            </div>
            <div class="dialog-progress-container is-loading">
                <div class="dialog-progress-bar" role="progressbar"></div>
            </div>
            <div class="modal-body-split">
                <div class="calculated-attributes-column">
                    <div class="skeleton-line mb-4" style="width: 70%; height: 20px;"></div>
                    <div class="skeleton-line mb-3" style="width: 90%;"></div>
                    <div class="skeleton-line mb-3" style="width: 80%;"></div>
                    <div class="skeleton-line" style="width: 85%;"></div>
                </div>
                <div class="form-column p-4">
                    <div class="text-center text-muted mb-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2">Chargement du formulaire, veuillez patienter...</span>
                    </div>
                    <div class="skeleton-line mb-4" style="width: 40%; height: 14px;"></div>
                    <div class="skeleton-line mb-4" style="height: 38px;"></div>
                    <div class="skeleton-line mb-4" style="width: 50%; height: 14px;"></div>
                    <div class="skeleton-line mb-4" style="height: 38px;"></div>
                    <div class="skeleton-line" style="height: 80px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <div class="skeleton-line" style="width: 120px; height: 38px; border-radius: var(--bs-border-radius);"></div>
                <div class="skeleton-line" style="width: 120px; height: 38px; border-radius: var(--bs-border-radius);"></div>
            </div>
        `;
    }

    /**
     * Affiche un message de feedback (succès, erreur) horodaté dans le pied de page de la modale.
     * @param {'success'|'error'|'warning'} type - Le type de message.
     * @param {string} message - Le message à afficher.
     */
    showFeedback(type, message) {
        const feedbackContainer = this.contentTarget.querySelector('.feedback-container');
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
        this.feedbackContainer = this.contentTarget.querySelector('.feedback-container');

        const form = this.contentTarget.querySelector('form');
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
        // On ne ferme plus directement. On demande au cerveau de le faire.
        // Cela garantit que la fermeture est ciblée et orchestrée.
        this.notifyCerveau('ui:dialog.close-request', { dialogId: this.dialogId });
    }

    /**
     * NOUVEAU : Méthode qui exécute la fermeture, appelée par le cerveau.
     * @param {CustomEvent} event
     */
    doClose(event) {
        // On s'assure que l'ordre de fermeture nous est bien destiné.
        if (event.detail.dialogId === this.dialogId) {
            this.toggleProgressBar(false); // On s'assure que la barre est cachée.
            if (this.hasModalOutlet) {
                this.modalOutlet.hide(); // On demande au contrôleur 'modal' de se fermer.
            }
        }
    }
    
    /**
     * Gère l'état visuel du bouton de soumission et des autres boutons (chargement/normal).
     * @param {boolean} isLoading - `true` pour afficher l'état de chargement, `false` sinon.
     */
    toggleLoading(isLoading) {
        // On cherche le bouton manuellement juste quand on en a besoin
        const button = this.contentTarget.querySelector('[data-action*="#triggerSubmit"]');

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
        const closeButtons = this.contentTarget.querySelectorAll('[data-action*="#close"]');
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
        const progressBarContainer = this.contentTarget.querySelector('.dialog-progress-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Déclenche manuellement la soumission du formulaire interne.
     */
    triggerSubmit() {
        const form = this.contentTarget.querySelector('form');
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