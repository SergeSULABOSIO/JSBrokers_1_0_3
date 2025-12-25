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
        this.isCreateMode = !(this.entity && this.entity.id);
        this.context = detail.context || {};
        this.parentContext = detail.parentContext || null; // NOUVEAU : On récupère le contexte du parent.
        this.formTemplateHTML = detail.formTemplateHTML || null; // Récupère le HTML pré-rendu si disponible
        this._logState('start', '1986', detail);
        
        // Affiche la coquille vide de la modale (qui contient un spinner par défaut)
        this.modalNode = this.element.closest('.modal');
        this.modal = new Modal(this.modalNode);
        this.modal.show();
        this.modalNode.addEventListener('hidden.bs.modal', () => { this.modalNode.remove(); });
        this.modalNode.addEventListener('shown.bs.modal', this.boundAdjustZIndex);
        
        // Charge le contenu complet depuis le serveur
        await this.loadContent();
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
            if (modal !== this.element.closest('.modal')) {
                    const zIndex = parseInt(window.getComputedStyle(modal).zIndex) || 1055;
                    if (zIndex > maxZIndex) {
                        maxZIndex = zIndex;
                    }
                }
            });

            // On récupère notre modale et son backdrop (c'est toujours le dernier ajouté)
        const myModal = this.element.closest('.modal');
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
    async loadContent() {
        this._logState("loadContent", "1986", this.detail);
        console.log(this.nomControlleur + " - loadContent() - Code:1986 - this.entity:", this.entity);
        // NOUVEAU : On affiche le squelette de chargement pour une meilleure UX.
        this.element.innerHTML = this._getSkeletonHtml();
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
            console.log(this.nomControlleur + " - Code: 1986 - URL de chargement du formulaire:" + finalUrl); // Pour débogage

            const response = await fetch(finalUrl);
            if (!response.ok) throw new Error("Le contenu de la boîte de dialogue n'a pas pu être chargé.");

            const html = await response.text();
            
            // On remplace tout le contenu de la modale par le HTML reçu.
            this.element.innerHTML = html;

            // On attache l'action de soumission au nouveau formulaire qui vient d'être injecté.
            const form = this.element.querySelector('form');
            if (form) {
                form.setAttribute('data-action', 'submit->dialog-instance#submitForm');
            }

            const mainDialogElement = this.element.closest('.app-dialog');

            // On vérifie si le contenu retourné contient des attributs calculés pour ajuster la classe CSS.
            const hasCalculatedAttrs = this.element.querySelector('.calculated-attributes-list li');
            if (hasCalculatedAttrs) {
                mainDialogElement.classList.add('has-attributes-column');
            } else {
                mainDialogElement.classList.remove('has-attributes-column');
            }

            // NOUVEAU : Notifier le cerveau que le dialogue est prêt et affiché.
            this.notifyCerveau('app:dialog.opened', {
                mode: this.isCreateMode ? 'creation' : 'edition',
                entity: this.entity
            });

            // On s'assure que la classe de mode édition est bien présente si nécessaire
            if (!this.isCreateMode) {
                mainDialogElement.classList.add('is-edit-mode');
            }
        } catch (error) {
            const errorMessage = error.message || "Une erreur inconnue est survenue.";
            this.element.innerHTML = `<div class="modal-body"><div class="alert alert-danger">${errorMessage}</div></div>`;
            // NOUVEAU : Notifier le cerveau de l'échec de chargement
            this.notifyCerveau('app:error.api', {
                error: `Échec du chargement du formulaire: ${errorMessage}`
            });
        }
    }

    /**
     * Recharge la vue complète (titre, formulaire, attributs) après une création réussie
     * pour passer en mode édition sans fermer la modale.
     * @private
     */
    async reloadView() {
        this.modalNode.classList.add('is-edit-mode'); // Affiche la colonne de gauche
        await this.loadContent(); // Recharge le formulaire et les attributs
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
                <h5 class="modal-title"><div class="skeleton-line" style="width: 250px; height: 24px;"></div></h5>
                <button type="button" class="btn-close btn-close-white" disabled></button>
            </div>
            <div class="dialog-progress-container is-loading">
                <div class="dialog-progress-bar" role="progressbar"></div>
            </div>
            <div class="modal-body-split">
                <div class="calculated-attributes-column">
                    <div class="skeleton-line" style="width: 80%; margin-bottom: 1rem;"></div>
                    <div class="skeleton-line" style="width: 60%;"></div>
                </div>
                <div class="form-column">
                    <div class="skeleton-line" style="width: 90%; margin-bottom: 1.5rem;"></div>
                    <div class="skeleton-line" style="width: 100%; margin-bottom: 1.5rem;"></div>
                    <div class="skeleton-line" style="width: 70%; margin-bottom: 1.5rem;"></div>
                    <div class="skeleton-line" style="width: 95%;"></div>
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
     * Affiche les erreurs de validation renvoyées par le serveur
     * à côté des champs de formulaire correspondants.
     * @param {object} errors - Un objet où les clés sont les noms des champs et les valeurs sont les messages d'erreur.
     */
    displayErrors(errors) {
        // --- CORRECTION : S'assurer que la cible du feedback est définie ---
        this.feedbackContainer = this.element.querySelector('.feedback-container');

        const form = this.element.querySelector('form');
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
        this.modal.hide();
    }

    /**
     * Gère l'état visuel du bouton de soumission et des autres boutons (chargement/normal).
     * @param {boolean} isLoading - `true` pour afficher l'état de chargement, `false` sinon.
     */
    toggleLoading(isLoading) {
        // On cherche le bouton manuellement juste quand on en a besoin
        // const button = this.element.querySelector('button[type="submit"]');
        const button = this.element.querySelector('[data-action*="#triggerSubmit"]');

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
        const closeButtons = this.element.querySelectorAll('[data-action*="#close"]');
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
        const progressBarContainer = this.element.querySelector('.dialog-progress-container');
        if (progressBarContainer) {
            progressBarContainer.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Déclenche manuellement la soumission du formulaire interne.
     */
    triggerSubmit() {
        const form = this.element.querySelector('form');
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
            console.groupCollapsed(`${this.nomControlleur} - ${callingFunction}() - Code:${code}`);
            console.log(`| Mode:`, (isCreateMode) ? 'Création' : 'Édition');
            console.log(`| Entité:`, detail.entity);
            console.log(`| Contexte:`, detail.context);
            console.log(`| Canvas:`, detail.entityFormCanvas);
            console.groupEnd();
        }

    }
}