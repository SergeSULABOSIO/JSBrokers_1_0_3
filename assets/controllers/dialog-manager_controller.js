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
        <div 
            class="modal fade app-dialog" 
            tabindex="-1"
            data-bs-backdrop="static"  {# Empêche la fermeture au clic sur le fond #}
            data-bs-keyboard="false"   {# Empêche la fermeture avec la touche Echap #}
        >
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
        this.nomControlleur = "Dialogue-Manager";
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
        console.log(this.nomControlleur + " - (1) Open", event.detail);
        // 1. Crée un nouvel élément HTML pour la modale à partir du template
        const modalElement = this.createModalElement();
        // --- MODIFICATION MAJEURE ICI ---
        // Au lieu d'essayer de récupérer le contrôleur, nous allons "cacher" les données
        // de l'événement directement sur l'élément qui portera le contrôleur.
        // C'est une astuce simple et efficace pour passer des données à un contrôleur
        // qui n'existe pas encore.
        const instanceElement = modalElement.querySelector('[data-controller="dialog-instance"]');
        instanceElement.dialogDetail = event.detail; // On attache les données ici

        // On ajoute l'élément au body. Stimulus va maintenant le détecter et connecter le contrôleur.
        document.body.appendChild(modalElement);
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