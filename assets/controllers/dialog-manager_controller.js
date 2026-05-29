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
            <div class="modal-dialog modal-xl modal-dialog-centered"> 
                <div class="modal-content custom-modal-scroll" data-controller="dialog-instance" data-dialog-instance-modal-outlet=".modal">
                    <div class="modal-header" data-dialog-instance-target="header">
                        <div class="d-flex align-items-center">
                            <span data-dialog-instance-target="titleIcon" class="me-2"></span>
                            <h5 class="modal-title mb-0" data-dialog-instance-target="title"><div class="skeleton-line" style="width: 250px; height: 24px;"></div></h5>
                        </div>
                        <button type="button" class="btn-close" aria-label="Fermer" data-controller="ripple" data-action="click->dialog-instance#close" data-dialog-instance-target="closeButton" disabled></button>
                    </div>
                    <div class="dialog-progress-container is-loading" data-dialog-instance-target="progressBarContainer"
                         role="progressbar" aria-label="Chargement du contenu" aria-valuemin="0" aria-valuemax="100">
                        <div class="dialog-progress-bar" aria-hidden="true"></div>
                    </div>
                    <div data-dialog-instance-target="content" class="modal-body p-0"> 
                        <div class="d-flex justify-content-center align-items-center h-100" style="min-height: 200px;">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" data-dialog-instance-target="footer"> 
                        <div class="feedback-container me-auto" role="status" aria-live="polite" aria-atomic="true" data-dialog-instance-target="feedbackContainer">
                            <!-- Feedback message will be injected here -->
                        </div>
                        <button type="button" class="btn btn-secondary d-inline-flex align-items-center gap-2" data-controller="ripple" data-action="click->dialog-instance#close" data-dialog-instance-target="closeFooterButton">
                            <span class="button-icon d-inline-flex" data-dialog-instance-target="closeIcon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></span>
                            <span class="button-text">Fermer</span>
                        </button>
                        <button type="button" class="btn btn-primary d-inline-flex align-items-center gap-2" data-controller="ripple" data-action="click->dialog-instance#triggerSubmit" data-dialog-instance-target="submitButton">
                            <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true" style="display: none;"></span>
                            <span class="button-icon d-inline-flex" data-dialog-instance-target="saveIcon"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg></span>
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

        // On rend la liaison entre l'instance et sa modale parente infaillible
        // en utilisant des ID uniques pour éviter toute ambiguïté potentielle.
        const modalId = `modal-container-${detail.dialogId}`;
        modalElement.id = modalId;

        const instanceElement = modalElement.querySelector('[data-controller="dialog-instance"]');
        instanceElement.id = detail.dialogId;

        // Lie la modale à son titre pour les lecteurs d'écran (WCAG 4.1.2)
        const titleId = `dialog-title-${detail.dialogId}`;
        modalElement.setAttribute('aria-labelledby', titleId);
        modalElement.querySelector('[data-dialog-instance-target="title"]').id = titleId;

        // On met à jour l'outlet pour qu'il cible l'ID unique de sa modale parente.
        instanceElement.setAttribute('data-dialog-instance-modal-outlet', `#${modalId}`);

        instanceElement.dialogDetail = event.detail; // On attache les données ici

        // NOUVEAU : On demande les icônes des boutons en amont, dès la création de la modale.
        this.requestButtonIcons(detail.dialogId);

        // On ajoute l'élément au body. Stimulus va maintenant le détecter et connecter le contrôleur.
        document.body.appendChild(modalElement);
        console.log(`[${++window.logSequence}] - [${this.nomControleur}] - [open] - Code: 99 - Fin - Données:`, event.detail);
    }

    /**
     * NOUVEAU : Demande les icônes pour les boutons du dialogue au Cerveau.
     * Ces requêtes sont faites en parallèle du chargement du contenu du dialogue.
     * @param {string} dialogId - L'ID unique du dialogue.
     */
    requestButtonIcons(dialogId) {
        // On utilise le même format de 'requesterId' que 'dialog-instance' utilisait,
        // pour que son écouteur 'handleIconLoaded' puisse intercepter la réponse.
        this.notifyCerveau('ui:icon.request', {
            iconName: 'action:save',
            iconSize: 20,
            requesterId: `${dialogId}-save`
        });

        this.notifyCerveau('ui:icon.request', {
            iconName: 'action:close',
            iconSize: 20,
            requesterId: `${dialogId}-close`
        });
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

    /**
     * NOUVEAU : Méthode utilitaire pour notifier le Cerveau.
     * @param {string} type 
     * @param {object} payload 
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true, detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}