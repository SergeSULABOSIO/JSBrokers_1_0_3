import { Controller } from '@hotwired/stimulus';
import { EVEN_BOITE_DIALOGUE_INIT_REQUEST } from './base_controller.js';

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

    /**
     * Méthode du cycle de vie de Stimulus, exécutée lorsque le contrôleur est connecté au DOM.
     * Met en place l'écouteur d'événement global pour les demandes d'ouverture de dialogue.
     */
    connect() {
        this.nomControlleur = "Dialogue-Manager";
        this.boundOpen = this.open.bind(this);
        // Écoute l'événement générique pour ouvrir N'IMPORTE QUEL dialogue
        document.addEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundOpen);
    }

    /**
     * Méthode du cycle de vie de Stimulus, exécutée lorsque le contrôleur est déconnecté du DOM.
     * Nettoie l'écouteur d'événement pour éviter les fuites de mémoire.
     */
    disconnect() {
        document.removeEventListener(EVEN_BOITE_DIALOGUE_INIT_REQUEST, this.boundOpen);
    }

    /**
     * Gère la demande d'ouverture d'un dialogue. Crée une nouvelle modale,
     * lui attache les données de configuration, et l'ajoute au DOM.
     * @param {CustomEvent} event - L'événement personnalisé qui a déclenché l'ouverture.
     * @property {object} event.detail - Les données de configuration pour le `dialog-instance_controller`.
     *                                   Doit contenir `entityFormCanvas` et `entity`.
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
     * Crée un élément DOM à partir du template HTML de la modale.
     * @returns {HTMLElement} L'élément racine de la nouvelle modale.
     */
    createModalElement() {
        const parser = new DOMParser();
        const doc = parser.parseFromString(this.modalTemplate, 'text/html');
        return doc.body.firstChild;
    }
}