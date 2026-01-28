import { Controller } from '@hotwired/stimulus';

/**
 * @file Ce fichier contient le contrôleur Stimulus 'dialog-manager'.
 * @description Ce contrôleur est le "chef d'orchestre" des boîtes de dialogue.
 * Attaché à un élément global (ex: <body>), il écoute les demandes d'ouverture de dialogue
 * et crée une nouvelle instance de modale Bootstrap à la volée pour chaque demande.
 * Il agit comme une usine, déléguant la gestion de chaque instance de dialogue
 * au contrôleur 'dialog-instance'.
 */

/**
 * @class DialogManagerController
 * @extends Controller
 * @description Gère la création et l'injection de nouvelles boîtes de dialogue dans le DOM.
 */
export default class extends Controller {
    /**
     * Le template HTML de base pour une nouvelle boîte de dialogue modale.
     * Contient une structure Bootstrap Modal et un élément avec `data-controller="dialog-instance"`
     * qui prendra le relais pour gérer le contenu et les interactions spécifiques au dialogue.
     * @type {string}
     */
    modalTemplate = `
        <div 
            class="modal fade app-dialog" 
            tabindex="-1"
            data-controller="modal"
            data-bs-backdrop="static"
            data-bs-keyboard="false"
        >
            <div class="modal-dialog modal-xl modal-dialog-scrollable"> 
                <div class="modal-content" data-controller="dialog-instance" data-dialog-instance-modal-outlet=".modal"> 
                    <div class="modal-header" data-dialog-instance-target="header">
                        <h5 class="modal-title d-flex align-items-center">
                            <span data-dialog-instance-target="titleIcon" class="me-2"></span>
                            <span data-dialog-instance-target="title"><div class="skeleton-line" style="width: 250px; height: 24px;"></div></span>
                        </h5>
                        <button type="button" class="btn-close" data-dialog-instance-target="closeButton" disabled></button> 
                    </div>
                    <div class="dialog-progress-container is-loading" data-dialog-instance-target="progressBarContainer"> 
                        <div class="dialog-progress-bar" role="progressbar"></div>
                    </div>
                    <div data-dialog-instance-target="content" class="modal-body text-center p-5 d-flex align-items-center justify-content-center" style="min-height: 100px;"> 
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                    <div class="modal-footer" data-dialog-instance-target="footer"> 
                        <div class="feedback-container me-auto" data-dialog-instance-target="feedbackContainer"> 
                            <!-- Feedback message will be injected here -->
                        </div>
                        <button type="button" class="btn btn-secondary" data-action="click->dialog-instance#close" data-dialog-instance-target="closeFooterButton"> 
                            <svg xmlns="http://www.w3.org/2000/svg" width="25px" height="25px" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8s8 3.59 8 8s-3.59 8-8 8zm3.59-13L12 10.59L8.41 7L7 8.41L10.59 12L7 15.59L8.41 17L12 13.41L15.59 17L17 15.59L13.41 12L17 8.41z"></path></svg>
                            <span>Fermer</span>
                        </button>
                        <button type="button" class="btn btn-primary" data-action="click->dialog-instance#triggerSubmit" data-dialog-instance-target="submitButton"> 
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                            <svg class="button-icon" xmlns="http://www.w3.org/2000/svg" width="23px" height="23px" viewBox="0 0 24 24" fill="currentColor"><path d="M15.004 3h-10a2 0 0 0-2 2v14a2 0 0 0 2 2h14a2 0 0 0 2-2v-10L15.004 3zm-9 16V6h8v4h4v9h-12z"></path></svg>
                            <span class="button-text">Enregistrer</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    /**
     * Méthode du cycle de vie de Stimulus, exécutée lorsque le contrôleur est connecté au DOM.
     * Met en place l'écouteur d'événement global pour les demandes d'ouverture de dialogue.
     */
    connect() {
        this.nomControleur = "Dialogue-Manager";
        this.boundOpen = this.open.bind(this);
        // Écoute l'événement générique pour ouvrir N'IMPORTE QUEL dialogue
        document.addEventListener('app:boite-dialogue:init-request', this.boundOpen);
    }

    /**
     * Méthode du cycle de vie de Stimulus, exécutée lorsque le contrôleur est déconnecté du DOM.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener('app:boite-dialogue:init-request', this.boundOpen);
    }

    /**
     * Gère la demande d'ouverture d'un dialogue. Crée une nouvelle modale,
     * lui attache les données de configuration, et l'ajoute au DOM.
     * @param {CustomEvent} event - L'événement personnalisé qui a déclenché l'ouverture.
     * @property {object} event.detail - Les données de configuration pour le `dialog-instance_controller`.
     *                                   Doit contenir `entityFormCanvas` et `entity`.
     */
    open(event) {
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [open] - Code: 99 - Début - Données:`, event.detail);
        const detail = event.detail;
        // NOUVEAU: Générer un ID unique pour chaque instance de dialogue
        detail.dialogId = `dialog-instance-${crypto.randomUUID()}`;

        console.groupCollapsed(`${this.nomControleur} - open - EDITDIAL(2)`);
        console.log(`| Mode: ${detail.isCreationMode ? 'Création' : 'Édition'}`);
        console.log('| Entité:', detail.entity);
        console.log('| Contexte:', detail.context);
        console.log('| Canvas:', detail.entityFormCanvas);
        console.groupEnd();

        if (!detail || !detail.entityFormCanvas) {
            console.error(`[${this.nomControleur}] Échec de l'ouverture: le payload est invalide ou 'entityFormCanvas' est manquant.`, detail);
            return;
        }
        // 1. Crée un nouvel élément HTML pour la modale à partir du template
        const modalElement = this.createModalElement();

        // NOUVEAU : On rend la liaison entre l'instance et sa modale parente infaillible
        // en utilisant des ID uniques pour éviter toute ambiguïté potentielle.
        const modalId = `modal-container-${detail.dialogId}`;
        modalElement.id = modalId;

        const instanceElement = modalElement.querySelector('[data-controller="dialog-instance"]');
        // NOUVEAU: Assigner l'ID à l'élément pour un ciblage facile et pour le débogage
        instanceElement.id = detail.dialogId;

        // On met à jour l'outlet pour qu'il cible l'ID unique de sa modale parente.
        instanceElement.setAttribute('data-dialog-instance-modal-outlet', `#${modalId}`);

        instanceElement.dialogDetail = event.detail; // On attache les données ici

        // On ajoute l'élément au body. Stimulus va maintenant le détecter et connecter le contrôleur.
        document.body.appendChild(modalElement);
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [open] - Code: 99 - Fin - Données:`, event.detail);
    }

    /**
     * Crée un élément DOM à partir du template HTML de la modale.
     * @returns {HTMLElement} L'élément racine de la nouvelle modale.
     */
    createModalElement() {
        const parser = new DOMParser();
        const doc = parser.parseFromString(this.modalTemplate, 'text/html');
        return doc.body.firstChild;
    }
}