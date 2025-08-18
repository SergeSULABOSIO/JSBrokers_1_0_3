import { Controller } from '@hotwired/stimulus';
import { EVEN_BOITE_DIALOGUE_INIT_REQUEST } from './base_controller.js';

/**
 * Ce contrôleur est le "chef d'orchestre" des boîtes de dialogue.
 * Attaché au <body>, il écoute les demandes d'ouverture de dialogue
 * et crée une nouvelle instance de modale à la volée pour chaque demande.
 */
export default class extends Controller {
    // Le template HTML d'une modale vide.
    // Il contient le contrôleur 'dialog-instance' qui prendra le relais.
    modalTemplate = `
        <div class="modal fade" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content" data-controller="dialog-instance">
                    <div class="modal-body text-center p-5">
                        <div class="spinner-border" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `;

    connect() {
        this.boundOpen = this.open.bind(this);
        // Écoute l'événement générique pour ouvrir N'IMPORTE QUEL dialogue
        document.addEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundOpen);
        // document.addEventListener('dialog:open-request', this.open.bind(this));
    }

    disconnect() {
        // Nettoie l'écouteur
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundOpen);
        // document.removeEventListener('dialog:open-request', this.open.bind(this));
    }

    /**
     * Gère la demande d'ouverture d'un dialogue.
     * @param {CustomEvent} event L'événement contenant les détails du dialogue à ouvrir.
     */
    open(event) {
        // 1. Crée un nouvel élément HTML pour la modale à partir du template
        const modalElement = this.createModalElement();
        
        // 2. Ajoute ce nouvel élément directement au body
        document.body.appendChild(modalElement);

        // 3. Récupère l'instance du contrôleur 'dialog-instance' que Stimulus vient d'attacher
        const dialogInstanceController = this.application.getControllerForElementAndIdentifier(
            modalElement.querySelector('[data-controller="dialog-instance"]'),
            'dialog-instance'
        );

        // 4. Passe les détails de la demande (formulaire à charger, contexte, etc.)
        //    au nouveau contrôleur pour qu'il puisse s'initialiser et prendre le relais.
        dialogInstanceController.initialize(event.detail);
    }
    
    /**
     * Crée un élément DOM à partir du template HTML.
     * @returns {HTMLElement} L'élément racine de la nouvelle modale.
     */
    createModalElement() {
        const parser = new DOMParser();
        const doc = parser.parseFromString(this.modalTemplate, 'text/html');
        return doc.body.firstChild;
    }
}