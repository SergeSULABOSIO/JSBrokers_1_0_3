import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { decisionEchap, OUVERTS_DANS_LE_DIALOGUE } from './echap-dialogue.js';
import { infobulleOuverte } from './infobulle_controller.js';
import { reserverLInstallation, libererLInstallation } from './gestionnaire-dialogues.js';

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
                    <!-- Barre d'actions de la fiche : HORS du corps, elle occupe toute la
                         largeur au-dessus des deux volets et ne défile pas avec eux. Même
                         barre que celle du workspace (.context-toolbar, barre-actions.js). -->
                    <div class="dialog-barre-actions" data-dialog-instance-target="barreActions" hidden>
                        <div class="context-toolbar" role="toolbar" aria-label="Actions sur la fiche"
                             data-controller="infobulle" data-infobulle-source-value="titre" data-infobulle-placement-value="bouton" data-dialog-instance-target="barreActionsContenu"></div>
                    </div>
                    <!-- PAS de « p-0 » ici : son !important masquait la règle
                         .custom-modal-scroll .modal-body, seule source de la gouttière du
                         corps — nulle désormais, volets jointifs comme au workspace. -->
                    <div data-dialog-instance-target="content" class="modal-body">
                        <div class="d-flex justify-content-center align-items-center h-100" style="min-height: 200px;">
                            <div class="spinner-border" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" data-dialog-instance-target="footer"> 
                        <!-- DEUX ZONES D'ANNONCE, présentes dès l'ouverture : le message y est INSÉRÉ.
                             « alert » pour une erreur, « status » sinon — jamais un aria-live
                             changé à la volée, peu fiable selon les lecteurs d'écran. -->
                        <div class="feedback-container me-auto" data-dialog-instance-target="feedbackContainer">
                            <div role="alert" aria-atomic="false" data-dialog-instance-target="feedbackAlerte"></div>
                            <div role="status" aria-atomic="false" data-dialog-instance-target="feedbackStatut"></div>
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
        // UN SEUL GESTIONNAIRE ACTIF (gestionnaire-dialogues.js) : l'analyse du bordereau, injectée
        // dans l'espace de travail, en apportait un second — chaque ouverture créait alors DEUX
        // dialogues identiques, donc deux enregistrements possibles. Celui-ci reste inerte.
        this.inerte = false;
        if (!reserverLInstallation(this.element)) {
            this.inerte = true;
            return;
        }
        this.boundOpen = this.open.bind(this);
        document.addEventListener('app:boite-dialogue:init-request', this.boundOpen);

        // LA PILE DES DIALOGUES OUVERTS, dans l'ordre où ils se sont ouverts : c'est elle qui
        // dit lequel est AU-DESSUS (l'ordre du DOM ne le dit pas — la confirmation est dans
        // base.html.twig, avant les dialogues ajoutés à la volée). Empilé dès `show` (avant
        // l'animation) : un second Échap rapide vise déjà la confirmation qui s'ouvre.
        this.pile = [];
        this.boundEmpiler = (event) => {
            if (!event.target.classList?.contains('modal')) return;
            this.pile = this.pile.filter((m) => m !== event.target);
            this.pile.push(event.target);
        };
        this.boundDepiler = (event) => {
            this.pile = this.pile.filter((m) => m !== event.target);
            this._rendreLeFocus(event.target);
        };
        document.addEventListener('show.bs.modal', this.boundEmpiler);
        document.addEventListener('hidden.bs.modal', this.boundDepiler);

        // ÉCHAP, décidé en phase de CAPTURE : avant que l'autocomplétion, le menu ou le picker
        // ouvert ne se referme lui-même — sinon on croirait qu'il n'y avait rien d'ouvert, et
        // le dialogue se fermerait avec lui (chantier 2, echap-dialogue.js).
        this.boundEchap = this._surEchap.bind(this);
        document.addEventListener('keydown', this.boundEchap, true);
    }

    /**
     * Méthode du cycle de vie de Stimulus, exécutée lorsque le contrôleur est déconnecté du DOM.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        if (this.inerte) return;
        libererLInstallation(this.element);
        document.removeEventListener('app:boite-dialogue:init-request', this.boundOpen);
        document.removeEventListener('show.bs.modal', this.boundEmpiler);
        document.removeEventListener('hidden.bs.modal', this.boundDepiler);
        document.removeEventListener('keydown', this.boundEchap, true);
    }

    /**
     * Échap : ferme le dialogue du DESSUS, sauf si quelque chose y est ouvert ou si l'on écrit
     * dans un éditeur riche. Un dialogue de formulaire passe par SA fermeture (`close()`), qui
     * demande confirmation quand une saisie n'est pas enregistrée.
     * @param {KeyboardEvent} event
     * @private
     */
    _surEchap(event) {
        const dessus = [...this.pile].reverse().find((m) => m.isConnected);
        const instance = dessus?.querySelector('[data-controller~="dialog-instance"]');
        const genre = !dessus ? null
            : instance ? 'formulaire'
            : dessus.id === 'confirmation-dialog-modal' ? 'confirmation'
            : dessus.classList.contains('app-dialog') ? 'simple'
            : 'autre';
        const focus = document.activeElement;

        const decision = decisionEchap({
            touche: event.key,
            enComposition: event.isComposing,
            dejaTraitee: event.defaultPrevented,
            repetee: event.repeat,
            echapLocal: !!focus?.closest?.('[data-pagination-jump-input], [data-echap-local]'),
            dessus: genre,
            dansEditeurRiche: !!focus?.closest?.('.ql-editor, .ql-tooltip'),
            sousElementOuvert: !!dessus?.querySelector(OUVERTS_DANS_LE_DIALOGUE),
            infobulleOuverte: infobulleOuverte(),
        });
        if (decision === 'laisser') return;

        event.preventDefault();
        event.stopPropagation();
        if (decision === 'fermer-formulaire') {
            this.application.getControllerForElementAndIdentifier(instance, 'dialog-instance')?.close();
        } else if (decision === 'fermer-confirmation') {
            // Annuler la question : son propre contrôleur fait le ménage (confirmation-dialog#close).
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));
        } else {
            Modal.getOrCreateInstance(dessus).hide();
        }
    }

    /**
     * Le focus revient à l'élément qui a OUVERT le dialogue (WCAG 2.4.3) — s'il existe encore
     * et qu'aucun autre dialogue ne l'a pris entre-temps.
     * @param {HTMLElement} modale
     * @private
     */
    _rendreLeFocus(modale) {
        const ouvreur = modale?.__ouvreur;
        if (!ouvreur || !ouvreur.isConnected || typeof ouvreur.focus !== 'function') return;
        const actif = document.activeElement;
        if (actif && actif !== document.body && !modale.contains(actif)) return;
        ouvreur.focus({ preventScroll: true });
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

        // L'élément qui a ouvert le dialogue : le focus y reviendra à la fermeture.
        const ouvreur = document.activeElement;
        if (ouvreur && ouvreur !== document.body) modalElement.__ouvreur = ouvreur;

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