import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * @class ConfirmationDialogController
 * @extends Controller
 * @description Gère une boîte de dialogue de confirmation générique.
 * Ce contrôleur écoute les demandes d'ouverture venant du Cerveau, affiche la modale,
 * et notifie le Cerveau de la confirmation de l'utilisateur. Il est entièrement découplé
 * de l'action qu'il confirme.
 */
export default class extends Controller {
    static targets = [
        "title",
        "body",
        "confirmButton",
        "feedback",
        "progressBarContainer",
        "header",
        "irreversibleAlert",
        "itemDetailsContainer",
        "itemList",
        "passwordContainer",
        "passwordField",
        "passwordToggle"
    ];

    connect() {
        this.nomControleur = "ConfirmationDialog";
        this.modal = new Modal(this.element);

        // Centralisation des écouteurs d'événements via le Cerveau
        this.boundHandleCerveauEvent = this.handleCerveauEvent.bind(this);
        document.addEventListener('ui:confirmation.request', this.boundHandleCerveauEvent);
        document.addEventListener('ui:confirmation.error', this.boundHandleCerveauEvent); // NOUVEAU

        this.boundClose = this.close.bind(this);
        document.addEventListener('ui:confirmation.close', this.boundClose);

        // À la fermeture (y compris via Échap ou clic sur le backdrop, sans passer
        // par close()), restaure l'opacité des backdrops restants pour redonner
        // l'assombrissement à la modale du dessous.
        this.boundRestoreBackdrops = this._restoreRemainingBackdrops.bind(this);
        this.element.addEventListener('hidden.bs.modal', this.boundRestoreBackdrops);
    }

    disconnect() {
        document.removeEventListener('ui:confirmation.request', this.boundHandleCerveauEvent);
        document.removeEventListener('ui:confirmation.close', this.boundClose);
        document.removeEventListener('ui:confirmation.error', this.boundHandleCerveauEvent); // NOUVEAU
        this.element.removeEventListener('hidden.bs.modal', this.boundRestoreBackdrops);
    }

    /**
     * Point d'entrée pour les événements venant du Cerveau.
     * Filtre et délègue aux méthodes appropriées.
     * @param {CustomEvent} event
     */
    handleCerveauEvent(event) {
         // On filtre en fonction du type d'événement global, pas du payload.
        switch (event.type) {
            case 'ui:confirmation.request':
                // On vérifie que le payload est correct avant de l'utiliser.
                if (event.detail && event.detail.onConfirm) {
                    this.open(event.detail);
                } else {
                    console.error(`[${this.nomControleur}] Demande de confirmation reçue avec un payload invalide.`, event.detail);
                }
                break;
            case 'ui:confirmation.error': // NOUVEAU : Gère l'erreur spécifique de confirmation
                this.handleError(event.detail);
                break;
        }
    }

    /**
     * Ouvre la modale et prépare l'action de confirmation.
     * @param {object} payload - Les données de l'événement.
     * @param {string} payload.title - Le titre de la modale.
     * @param {string} payload.body - Le corps du message de la modale.
     * @param {object} payload.onConfirm - L'action à notifier au Cerveau en cas de confirmation.
     */
    open(payload) {
        const { title, body, onConfirm, itemDescriptions, headerClass, confirmClass, showIrreversible, requirePassword } = payload;
        this.titleTarget.innerHTML = title || 'Confirmation requise';
        this.bodyTarget.innerHTML = body || 'Êtes-vous sûr ?';
        this.onConfirmDetail = onConfirm;

        // Confirmation forte par mot de passe (optionnelle).
        this.requirePassword = requirePassword === true;
        if (this.hasPasswordContainerTarget) {
            this.passwordContainerTarget.style.display = this.requirePassword ? 'block' : 'none';
        }
        if (this.hasPasswordFieldTarget) {
            this.passwordFieldTarget.value = '';
            this.passwordFieldTarget.type = 'password';
        }
        this._syncPasswordToggle(false);

        // Couleur d'en-tête personnalisable (défaut : bg-danger text-white)
        if (this.hasHeaderTarget) {
            this._defaultHeaderClass = this._defaultHeaderClass || 'bg-danger text-white';
            this.headerTarget.className = 'modal-header ' + (headerClass || this._defaultHeaderClass);
        }

        // Classe du bouton de confirmation personnalisable (défaut : btn btn-danger)
        if (this.hasConfirmButtonTarget) {
            const base = confirmClass || 'btn btn-danger';
            this.confirmButtonTarget.className = base;
        }

        // Alerte "irréversible" masquable
        if (this.hasIrreversibleAlertTarget) {
            this.irreversibleAlertTarget.style.display = (showIrreversible === false) ? 'none' : '';
        }

        // NOUVEAU : Gère l'affichage des descriptions des éléments concernés.
        if (itemDescriptions && itemDescriptions.length > 0) {
            this.itemListTarget.innerHTML = ''; // Vide la liste précédente
            itemDescriptions.forEach(description => {
                const li = document.createElement('li');
                li.className = 'list-group-item py-1 px-0 border-0 d-flex align-items-center bg-transparent';
                
                // Utilise une icône Bootstrap pour la cohérence
                li.innerHTML = `<i class="bi bi-file-earmark-minus me-2"></i> ${this._escapeHtml(description)}`;
                this.itemListTarget.appendChild(li);
            });
            this.itemDetailsContainerTarget.style.display = 'block';
        } else {
            this.itemDetailsContainerTarget.style.display = 'none';
        }

        // Calcule dynamiquement le z-index pour passer au-dessus de toutes les
        // modales ouvertes, quel que soit le niveau d'imbrication.
        const maxZ = Math.max(
            1055,
            ...Array.from(document.querySelectorAll('.modal.show'))
                .map(m => parseInt(window.getComputedStyle(m).zIndex, 10) || 0)
        );
        this.element.style.zIndex = maxZ + 20;

        this.modal.show();

        // Le backdrop vient d'être créé par Bootstrap : c'est le dernier du DOM.
        // On le place juste sous la confirmation, au-dessus des modales existantes,
        // et on masque les backdrops inférieurs pour ne pas cumuler les opacités
        // (cohérent avec modal_controller).
        const backdrops = document.querySelectorAll('.modal-backdrop');
        backdrops.forEach((backdrop, i) => {
            if (i === backdrops.length - 1) {
                backdrop.style.zIndex = maxZ + 10;
                backdrop.style.opacity = '';
            } else {
                backdrop.style.opacity = '0';
            }
        });
    }

    /**
     * Restaure l'opacité des backdrops restants après la fermeture de la modale.
     * À ce stade, Bootstrap a déjà supprimé le backdrop de CETTE modale du DOM.
     * @private
     */
    _restoreRemainingBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
            backdrop.style.opacity = '';
        });
    }

    /**
     * Exécuté lorsque l'utilisateur clique sur "Confirmer".
     * Notifie le Cerveau pour qu'il exécute l'action mise en attente.
     * @fires cerveau:event
     */
    confirm() {
        this.feedbackTarget.innerHTML = ''; // Nettoie les anciens messages d'erreur

        // Confirmation forte : un mot de passe non vide est requis avant de continuer.
        let password;
        if (this.requirePassword) {
            password = this.hasPasswordFieldTarget ? this.passwordFieldTarget.value : '';
            if (!password) {
                this.feedbackTarget.innerHTML = 'Veuillez saisir votre mot de passe.';
                if (this.hasPasswordFieldTarget) this.passwordFieldTarget.focus();
                return;
            }
        }

        this.toggleLoading(true); // Active le spinner
        this.toggleProgressBar(true);

        // Si une action de confirmation a été définie...
        if (this.onConfirmDetail && this.onConfirmDetail.type) {
            const payload = { ...(this.onConfirmDetail.payload || {}) };
            if (this.requirePassword) {
                payload.password = password;
            }
            this.notifyCerveau(this.onConfirmDetail.type, payload);
        }
    }

    /**
     * Bascule l'affichage du mot de passe (masqué / en clair) et met à jour
     * l'icône et les attributs d'accessibilité du bouton.
     */
    togglePassword() {
        if (!this.hasPasswordFieldTarget) return;
        const field = this.passwordFieldTarget;
        const reveal = field.type === 'password';
        field.type = reveal ? 'text' : 'password';
        this._syncPasswordToggle(reveal);
        field.focus();
    }

    /**
     * Synchronise l'icône (œil / œil barré) et les attributs ARIA du bouton bascule.
     * @param {boolean} revealed - true si le mot de passe est affiché en clair.
     * @private
     */
    _syncPasswordToggle(revealed) {
        if (!this.hasPasswordToggleTarget) return;
        const showIcon = this.passwordToggleTarget.querySelector('[data-role="icon-show"]');
        const hideIcon = this.passwordToggleTarget.querySelector('[data-role="icon-hide"]');
        if (showIcon) showIcon.style.display = revealed ? 'none' : '';
        if (hideIcon) hideIcon.style.display = revealed ? '' : 'none';
        this.passwordToggleTarget.setAttribute('aria-pressed', revealed ? 'true' : 'false');
        this.passwordToggleTarget.setAttribute('aria-label', revealed ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    }

    /**
     * Gère un événement d'erreur reçu pendant le processus de confirmation.
     * Affiche le message d'erreur et réactive le bouton.
     * @param {object} payload - Le payload de l'événement d'erreur.
     * @param {string} payload.error - Le message d'erreur.
     */
    handleError(payload) {
        this.toggleLoading(false); // Stoppe le spinner
        this.toggleProgressBar(false);
        this.feedbackTarget.innerHTML = payload.error || "Une erreur est survenue.";
    }

    // Gère l'affichage du spinner et l'état du bouton de confirmation.
    toggleLoading(isLoading) {
        const button = this.confirmButtonTarget;
        const spinner = button.querySelector('.spinner-border');
        const icon = button.querySelector('.button-icon');
        const text = button.querySelector('.button-text');

        if (isLoading) {
            button.disabled = true;
            spinner.style.display = 'inline-block';
            icon.style.display = 'none';
            text.textContent = 'En cours...';
        } else {
            button.disabled = false;
            spinner.style.display = 'none';
            icon.style.display = 'inline-block';
            text.textContent = 'Confirmer';
        }
    }

    /**
     * Ferme la modale.
     */
    close() {
        this.toggleLoading(false);
        this.toggleProgressBar(false);
        this.feedbackTarget.innerHTML = '';
        this.onConfirmDetail = null;

        // Réinitialise les styles personnalisés APRÈS la fin de l'animation de fermeture,
        // pour éviter que l'utilisateur voie brièvement l'en-tête rouge par défaut.
        this.element.addEventListener('hidden.bs.modal', () => {
            if (this.hasHeaderTarget) {
                this.headerTarget.className = 'modal-header bg-danger text-white';
            }
            if (this.hasConfirmButtonTarget) {
                this.confirmButtonTarget.className = 'btn btn-danger';
            }
            if (this.hasIrreversibleAlertTarget) {
                this.irreversibleAlertTarget.style.display = '';
            }
            if (this.hasItemDetailsContainerTarget) {
                this.itemDetailsContainerTarget.style.display = 'none';
                this.itemListTarget.innerHTML = '';
            }
            if (this.hasPasswordContainerTarget) {
                this.passwordContainerTarget.style.display = 'none';
            }
            if (this.hasPasswordFieldTarget) {
                this.passwordFieldTarget.value = '';
                this.passwordFieldTarget.type = 'password';
            }
            this._syncPasswordToggle(false);
        }, { once: true });

        this.modal.hide();
    }

    /**
     * NOUVEAU : Échappe le HTML pour éviter les injections XSS.
     * @param {string} unsafe 
     * @returns 
     */
    _escapeHtml(unsafe) {
        return unsafe
             .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
    }

    /**
     * Affiche ou cache la barre de progression.
     */
    toggleProgressBar(isLoading) {
        if (this.hasProgressBarContainerTarget) {
            this.progressBarContainerTarget.classList.toggle('is-loading', isLoading);
        }
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type de l'événement.
     * @param {object} [payload={}] - Les données associées à l'événement.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}