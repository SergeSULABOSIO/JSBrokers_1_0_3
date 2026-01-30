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
    static targets = [
        'content', 'formRow', 'dynamicFieldContainer', 'header', 'title', 'titleIcon', 
        'closeButton', 'progressBarContainer', 'footer', 'feedbackContainer', 'submitButton', 
        'closeFooterButton', 'saveIcon', 'closeIcon'
    ];
    

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute lorsque l'élément du contrôleur est ajouté au DOM.
     * Récupère les données d'initialisation et démarre le processus d'affichage du dialogue.
     * @throws {Error} Si les données d'initialisation (`dialogDetail`) ne sont pas trouvées.
     */
    connect() {
        this.nomControleur = "Dialog-Instance";

        /**
         * @property {boolean} isReloading - Drapeau pour gérer l'état de rechargement de la vue.
         * @private
         */
        this.isReloading = false;

        /**
         * @property {object|null} feedbackOnNextLoad - Stocke un message de feedback à afficher après le prochain rechargement de contenu.
         * @private
         */
        this.feedbackOnNextLoad = null;
        const detail = this.element.dialogDetail;
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [connect] - Code: 1986 - Début - Données:`, detail, this.element, this.contentTarget);
        this.cetteApplication = this.application; 

        this.boundHandleContentReady = this.handleContentReady.bind(this);
        document.addEventListener('ui:dialog.content-ready', this.boundHandleContentReady);
        // NOUVEAU : Écouteur pour la demande de fermeture ciblée venant du cerveau.
        this.boundDoClose = this.doClose.bind(this);
        // NOUVEAU : Écouteur pour la réception du HTML de l'icône.
        this.boundHandleIconLoaded = this.handleIconLoaded.bind(this);
        document.addEventListener('app:icon.loaded', this.boundHandleIconLoaded);
        document.addEventListener('app:dialog.do-close', this.boundDoClose);
        this.requestButtonIcons();

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
        document.removeEventListener('app:icon.loaded', this.boundHandleIconLoaded);
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

        // Afficher les squelettes dans l'en-tête et le pied de page
        this.titleTarget.innerHTML = '<div class="skeleton-line" style="width: 250px; height: 24px;"></div>';
        if (this.hasTitleIconTarget) {
            this.titleIconTarget.innerHTML = ''; // Vider l'icône précédente
        }
        this.closeButtonTarget.disabled = true; // Disable header close button
        this.submitButtonTarget.disabled = true; // Disable submit button
        this.closeFooterButtonTarget.disabled = true; // Disable footer close button

        this.progressBarContainerTarget.classList.add('is-loading'); // Show progress bar

        // CORRECTION : On affiche le squelette du corps sans le remplacer par un spinner.
        this.contentTarget.innerHTML = this._getSkeletonHtml();

        // On retire les classes qui centrent le spinner, car on affiche un squelette complet.
        this.contentTarget.classList.remove('text-center', 'p-5', 'd-flex', 'align-items-center', 'justify-content-center');
        this.contentTarget.style.minHeight = ''; // On retire la hauteur min du spinner

        // Prépare les informations pour la requête que le Cerveau va exécuter
        const payload = {
            dialogId: this.dialogId,
            endpoint: this.entityFormCanvas.parametres.endpoint_form_url,
            entity: this.entity,
            context: this.context,
            entityFormCanvas: this.entityFormCanvas // NOUVEAU : Ajout de l'objet entityFormCanvas
        };

        // Notifie le cerveau pour qu'il charge le contenu
        this.notifyCerveau('ui:dialog.content-request', payload);
    }

    /**
     * NOUVEAU: Gère la réception du contenu HTML envoyé par le Cerveau.
     * @param {CustomEvent} event 
     */
    handleContentReady(event) {
        const { dialogId, html, error, icon } = event.detail;

        // On s'assure que cet événement nous est bien destiné
        if (dialogId !== this.dialogId) {
            return;
        }

        console.log(`${this.nomControleur} - handleContentReady() - Contenu reçu pour ${this.dialogId}`);

        if (error) {
            const errorMessage = error.message || "Une erreur inconnue est survenue.";            
            this.titleTarget.textContent = "Erreur"; // Mettre à jour le titre avec l'erreur
            this.contentTarget.innerHTML = `<div class="alert alert-danger">${errorMessage}</div>`;
            this.contentTarget.classList.remove('text-center', 'p-5', 'd-flex', 'align-items-center', 'justify-content-center');
            this.contentTarget.style.minHeight = ''; // Réinitialiser la hauteur minimale
            // Notifier le cerveau de l'échec de chargement
            this.notifyCerveau('app:error.api', {
                error: `Échec du chargement du formulaire: ${errorMessage}`
            });
            return;
        }
        // Mettre à jour le titre de la modale
        this.titleTarget.textContent = event.detail.title;

        // NOUVEAU : Mettre à jour l'icône du titre
        if (this.hasTitleIconTarget && icon) {
            // On ne construit plus l'icône ici. On demande au cerveau de la fournir.
            this.notifyCerveau('ui:icon.request', {
                iconName: icon,
                iconSize: 28, // Taille adaptée pour un titre de dialogue
                requesterId: this.dialogId // Pour que la réponse nous soit bien destinée
            });
        }

        // On remplace tout le contenu de la modale par le HTML reçu.
        this.contentTarget.innerHTML = html; 

        // On attache l'action de soumission au nouveau formulaire qui vient d'être injecté.
        const form = this.element.querySelector('form'); // Form is now inside modal-body, so search from modal-content
        if (form) {
            form.setAttribute('data-action', 'submit->dialog-instance#submitForm');
        }
        // Réinitialiser les styles du corps de la modale après le chargement du contenu réel


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

        // NOUVEAU : Affiche un message de feedback en attente s'il y en a un.
        if (this.feedbackOnNextLoad) {
            this.showFeedback(this.feedbackOnNextLoad.type, this.feedbackOnNextLoad.message);
            this.feedbackOnNextLoad = null; // On le réinitialise pour la prochaine fois.
        }

        // NOUVEAU : On s'assure que les boutons sont réactivés après un rechargement.
        this.toggleLoading(false); //
        this.toggleProgressBar(false); // Cacher la barre de progression
    }

    /**
     * NOUVEAU: Demande au cerveau de charger les icônes pour les boutons du footer.
     */
    requestButtonIcons() {
        if (this.hasSaveIconTarget) {
            this.notifyCerveau('ui:icon.request', {
                iconName: 'action:save',
                iconSize: 20,
                requesterId: this.dialogId + '-save' // ID unique pour cette requête d'icône
            });
        }
        if (this.hasCloseIconTarget) {
            this.notifyCerveau('ui:icon.request', {
                iconName: 'action:close',
                iconSize: 20,
                requesterId: this.dialogId + '-close' // ID unique
            });
        }
    }

    /**
     * NOUVEAU: Gère la réception du HTML de l'icône et l'injecte.
     * @param {CustomEvent} event
     */
    handleIconLoaded(event) {
        const { html, requesterId } = event.detail;

        // On s'assure que l'icône est bien pour cette instance de dialogue
        if (requesterId === this.dialogId && this.hasTitleIconTarget) {
            this.titleIconTarget.innerHTML = html;
        }

        // NOUVEAU : Gère les icônes des boutons
        if (requesterId === this.dialogId + '-save' && this.hasSaveIconTarget) {
            this.saveIconTarget.innerHTML = html;
        }
        if (requesterId === this.dialogId + '-close' && this.hasCloseIconTarget) {
            this.closeIconTarget.innerHTML = html;
        }
    }

    /**
     * Recharge la vue complète (titre, formulaire, attributs) après une création réussie
     * pour passer en mode édition sans fermer la modale.
     * @private
     */
    reloadView() {
        const mainDialogElement = this.modalOutlet.element;
        mainDialogElement.classList.add('is-edit-mode');

        // CORRECTION : On force l'ajout de la classe pour que le squelette de la colonne des attributs
        // soit visible pendant la transition. Cette classe sera réévaluée correctement
        // dans `handleContentReady` une fois le nouveau contenu chargé.
        mainDialogElement.classList.add('has-attributes-column');

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
 
        // NOUVEAU : Ne pas nettoyer le conteneur de feedback ici pour assurer la persistance du message "Enregistrement en cours..."
        // Le message sera mis à jour par showFeedback() juste après.
        // Désactiver les boutons
        this.toggleLoading(true);
 
        // NOUVEAU : Affiche un message de feedback pendant la soumission.
        this.showFeedback('warning', 'Enregistrement en cours, veuillez patienter...');
 
        this.isReloading = false; // NOUVEAU : On réinitialise le drapeau de rechargement.

        // NOUVEAU : On remplace le corps de la modale par un squelette de chargement
        // tout en conservant l'ancien contenu en cas d'erreur.
        const bodyContainer = this.contentTarget; // this.contentTarget IS the modal-body
        let originalBodyHtml = '';
        if (bodyContainer) {
            originalBodyHtml = bodyContainer.innerHTML;
            bodyContainer.innerHTML = this._getBodySkeletonHtml();
        }

        // 1. On récupère les données du formulaire directement dans un objet FormData.
        const formData = new FormData(event.target);
 
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
 
            // On délègue la gestion du succès à une méthode dédiée.
            this.handleSuccessfulSubmit(result);
 
        } catch (error) {
            console.error(error);

            // NOUVEAU : En cas d'erreur, on restaure le formulaire original pour afficher les erreurs.
            if (bodyContainer && originalBodyHtml) {
                bodyContainer.innerHTML = originalBodyHtml;
            }

            // NOUVEAU : Notifier le cerveau de l'échec de validation
            this.notifyCerveau('app:form.validation-error', {
                message: error.message || 'Erreur de validation',
                errors: error.errors || {}
            });
 
            // NOUVEAU : Affiche le message d'erreur après la restauration du formulaire.
            this.showFeedback('error', error.message || 'Une erreur est survenue.');
            this.feedbackOnNextLoad = null; // S'assurer qu'aucun message de succès ne remplace l'erreur.
 
            if (error.errors) {
                this.displayErrors(error.errors);
            }
        } finally {
            // NOUVEAU : La gestion du spinner est maintenant plus fine.
            // Si une erreur s'est produite (pas de rechargement), on arrête le spinner.
            // Si un rechargement est en cours, on le laisse tourner, il sera arrêté par `handleContentReady`.
            if (!this.isReloading) {
                this.toggleLoading(false);
                this.toggleProgressBar(false);
            }
        }
    }

    /**
     * NOUVEAU : Centralise la logique de traitement après une soumission réussie.
     * @param {object} result - Le résultat JSON de la requête fetch.
     * @private
     */
    handleSuccessfulSubmit(result) {
        // On indique qu'un rechargement est en cours.
        // Le `finally` de `submitForm` ne masquera pas les indicateurs de chargement.
        this.isReloading = true;

        // NOUVEAU : Nettoyer le feedback existant avant d'en afficher un nouveau après le rechargement.

        // On stocke le message de succès pour l'afficher APRÈS le rechargement de la vue.
        this.feedbackOnNextLoad = { type: 'success', message: result.message };

        // Si c'était une création, on met à jour l'état interne pour passer en mode édition.
        if (this.isCreateMode && result.entity) {
            this.entity = result.entity;
            this.isCreateMode = false;
        }

        // On recharge la vue dans tous les cas de succès (création ou édition).
        // `reloadView` va appeler `loadContent`, qui affichera le squelette complet
        // avant de recevoir le contenu final.
        this.reloadView();
    }

    /**
     * NOUVEAU : Initialise les écouteurs pour les champs dynamiques du formulaire.
     * Cette méthode est appelée une fois que le contenu du formulaire est chargé.
     */
    initializeFormVisibility() {
        if (!this.hasDynamicFieldContainerTarget) return; 

        this.sourceFields = new Map();
        this.dynamicFieldContainerTargets.forEach(container => {
            const conditions = JSON.parse(container.dataset.visibilityConditionsValue);
            conditions.forEach(condition => {
                if (!this.sourceFields.has(condition.field)) {
                    this.sourceFields.set(condition.field, []);
                }
                const form = this.element.querySelector('form'); // Search within modal-content
                // Trouve le ou les champs source (peut être une collection de radios)
                const sourceInputs = form.querySelectorAll(`[name="${condition.field}"], [name="${condition.field}[]"]`);
                if (sourceInputs.length > 0) {
                    sourceInputs.forEach(sourceInput => {
                        const listeners = this.sourceFields.get(condition.field);
                        // On s'assure de n'ajouter l'écouteur qu'une seule fois par champ source
                        if (!listeners.find(el => el === sourceInput)) {
                            sourceInput.addEventListener('change', this.checkFormVisibility.bind(this));
                            listeners.push(sourceInput);
                        }
                    });
                }
            });
        });
        // Exécute une première vérification à l'initialisation
        this.checkFormVisibility();
    }

    /**
     * NOUVEAU : Vérifie la visibilité de tous les champs et lignes dynamiques.
     */
    checkFormVisibility() {
        if (!this.hasDynamicFieldContainerTarget) return;

        this.dynamicFieldContainerTargets.forEach(container => {
            const conditions = JSON.parse(container.dataset.visibilityConditionsValue);
            // Le conteneur est visible si TOUTES ses conditions sont remplies
            const isVisible = conditions.every(condition => this.evaluateCondition(condition));
            container.classList.toggle('d-none', !isVisible);
        });

        // Vérifie la visibilité des lignes après avoir masqué/affiché les champs
        this.formRowTargets.forEach(row => {
            // Une ligne est masquée si TOUTES ses colonnes enfants sont masquées
            const columns = Array.from(row.children);
            const visibleColumns = columns.filter(col => !col.classList.contains('d-none'));
            row.classList.toggle('d-none', visibleColumns.length === 0);
        });
    }

    /**
     * NOUVEAU : Évalue une condition de visibilité unique.
     * @param {object} condition - L'objet condition à évaluer.
     * @returns {boolean} - `true` si la condition est remplie, sinon `false`.
     */
    evaluateCondition(condition) {
        const form = this.contentTarget.querySelector('form'); // Search within modal-body
        const fieldName = condition.field;
        const field = form.elements[fieldName]; // Manière robuste de récupérer un champ de formulaire

        if (!field) return false;

        let sourceValue;
        if (field instanceof RadioNodeList) {
            // Cas d'un groupe de boutons radio (expanded: true)
            const checkedRadio = form.querySelector(`[name="${fieldName}"]:checked`);
            if (!checkedRadio) return false;
            sourceValue = checkedRadio.value;
        } else {
            // Cas d'un <select>, <input>, etc. (expanded: false)
            sourceValue = field.value;
        }

        if (sourceValue === null || sourceValue === undefined) return false;

        if (condition.operator === 'in') {
            return condition.value.map(String).includes(String(sourceValue));
        }
        return false;
    }

    /**
     * NOUVEAU : Génère le HTML pour un squelette de chargement du formulaire.
     * @returns {string} Le HTML du squelette.
     * @private
     */
    _getSkeletonHtml() {
        // CORRECTION : Ne retourne que le contenu du corps, pas la balise .modal-body elle-même.
        return `
            <div class="row">
                <div class="col-auto calculated-attributes-column-skeleton" style="width: 400px;">
                    <h5 class="column-title">
                        <div class="skeleton-line" style="width: 180px; height: 20px;"></div>
                    </h5>
                    <div class="skeleton-line mb-3" style="width: 90%; height: 20px;"></div>
                    <div class="skeleton-line mb-3" style="width: 80%; height: 20px;"></div>
                    <div class="skeleton-line mb-3" style="width: 85%; height: 20px;"></div>
                    <div class="skeleton-line" style="width: 75%; height: 20px;"></div>
                </div>
                <div class="col form-column-skeleton">
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
            `;
    }

    _getBodySkeletonHtml() { // Used for submission, so it's just the body content
        return `
            <div class="row">
                <div class="col-auto calculated-attributes-column-skeleton" style="width: 400px;">
                    <h5 class="column-title">
                        <div class="skeleton-line" style="width: 180px; height: 20px;"></div>
                    </h5>
                    <div class="skeleton-line mb-3" style="width: 90%; height: 20px;"></div>
                    <div class="skeleton-line mb-3" style="width: 80%; height: 20px;"></div>
                    <div class="skeleton-line mb-3" style="width: 85%; height: 20px;"></div>
                    <div class="skeleton-line" style="width: 75%; height: 20px;"></div>
                </div>
                <div class="col form-column-skeleton">
                    <div class="text-center text-muted mb-4">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span class="ms-2">Enregistrement des données...</span>
                    </div>
                    <div class="skeleton-line mb-4" style="width: 40%; height: 14px;"></div>
                    <div class="skeleton-line mb-4" style="height: 38px;"></div>
                    <div class="skeleton-line mb-4" style="width: 50%; height: 14px;"></div>
                    <div class="skeleton-line mb-4" style="height: 38px;"></div>
                    <div class="skeleton-line" style="height: 80px;"></div>
                </div>
            </div>
        `;
    }

    /**
     * Affiche un message de feedback (succès, erreur) horodaté dans le pied de page de la modale.
     * @param {'success'|'error'|'warning'} type - Le type de message.
     * @param {string} message - Le message à afficher.
     */
    showFeedback(type, message) {
        const feedbackContainer = this.feedbackContainerTarget;
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
        this.feedbackContainer = this.feedbackContainerTarget;

        const form = this.contentTarget.querySelector('form'); // Search within modal-body
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
        // Gère le bouton de soumission
        if (this.hasSubmitButtonTarget) {
            const submitButton = this.submitButtonTarget;
            const submitSpinner = submitButton.querySelector('.spinner-border');
            const submitIcon = submitButton.querySelector('.button-icon');
            const submitText = submitButton.querySelector('.button-text');

            submitButton.disabled = isLoading;
            if (submitSpinner) {
                submitSpinner.style.display = isLoading ? 'inline-block' : 'none';
            }
            if (submitIcon) {
                submitIcon.style.display = isLoading ? 'none' : 'inline-block';
            }
            if (submitText) {
                submitText.textContent = isLoading ? 'Enregistrement...' : 'Enregistrer';
            }
        }

        // Gère les boutons de fermeture (en-tête et pied de page)
        if (this.hasCloseButtonTarget) {
            this.closeButtonTarget.disabled = isLoading;
        }
        if (this.hasCloseFooterButtonTarget) {
            const closeFooterButton = this.closeFooterButtonTarget;
            const closeIcon = closeFooterButton.querySelector('.button-icon');
            const closeText = closeFooterButton.querySelector('span:not(.button-icon)'); // Assuming the text is the other span
            
            closeFooterButton.disabled = isLoading;
            if (closeIcon) {
                closeIcon.style.display = isLoading ? 'none' : 'inline-block';
            }
            if (closeText) {
                closeText.textContent = isLoading ? 'Fermeture...' : 'Fermer';
            }
        }
    }

    /**
     * Affiche ou cache la barre de progression en haut de la modale.
     * @param {boolean} isLoading - `true` pour afficher la barre, `false` pour la cacher.
     */
    toggleProgressBar(isLoading) {
        // On cherche le conteneur de la barre manuellement
        const progressBarContainer = this.progressBarContainerTarget;
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Déclenche manuellement la soumission du formulaire interne.
     */
    triggerSubmit() {
        const form = this.contentTarget.querySelector('form'); // Form is inside modal-body
        if (form) { // Search within modal-content
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