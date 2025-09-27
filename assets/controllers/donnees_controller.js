// assets/controllers/sales-list_controller.js
import { Controller } from '@hotwired/stimulus';
import { buildCustomEventForElement } from './base_controller.js';

export default class extends Controller {

    static values = {
        status: Object,
        page: Number,
        limit: Number,
        totalitems: Number,
    }

    connect() {
        this.nomControleur = "DONNEES";
        console.log(this.nomControleur + " - Chargé.", this.statusValue);

        // Vérifier si l'objet statusValue existe bien
        if (this.hasStatusValue) {
            // Accéder à chaque propriété avec la notation pointée
            const statusCode = this.statusValue.code;
            const statusMessage = this.statusValue.message;
            const statusDetails = this.statusValue.error;

            // buildCustomEventForElement(document, EVEN_LISTE_PRINCIPALE_NOTIFY, true, true, {titre:"Réponse du serveur", message:statusCode + " - " + statusMessage + ". Total: " + this.totalitemsValue});
            buildCustomEventForElement(document, EVEN_SHOW_TOAST, true, true, { text: statusMessage, type: 'info' });
            buildCustomEventForElement(document, EVEN_DATA_BASE_DONNEES_LOADED, true, true, {
                status: this.statusValue,
                page: this.pageValue,
                limit: this.limitValue,
                totalitems: this.totalitemsValue,
            });
        }
    }

    disconnect() {
        // document.removeEventListener(EVEN_MOTEUR_RECHERCHE_CRITERES_REQUEST, this.provideCriteria.bind(this));
    }
}