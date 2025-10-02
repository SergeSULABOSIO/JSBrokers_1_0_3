import { Controller } from '@hotwired/stimulus';

/**
 * @class ApiResponseNotifierController
 * @extends Controller
 * @description Ce contrôleur agit comme un "messager" entre le serveur et le client.
 * Lorsqu'il est connecté au DOM (typiquement après un chargement de contenu AJAX),
 * il lit les données de statut passées par le serveur et les envoie au Cerveau
 * via un événement unique, pour que le reste de l'application puisse réagir.
 */
export default class extends Controller {
    /**
     * @property {ObjectValue} statusValue - L'objet de statut renvoyé par le serveur (ex: {code, message}).
     * @property {NumberValue} pageValue - Le numéro de la page actuelle.
     * @property {NumberValue} limitValue - Le nombre d'éléments par page.
     * @property {NumberValue} totalitemsValue - Le nombre total d'éléments.
     */
    static values = {
    }

    /**
     * Méthode du cycle de vie de Stimulus.
     * S'exécute une seule fois lorsque le contrôleur est connecté.
     */
    connect() {
        this.nomControleur = "API-RESPONSE-NOTIFIER";
    }

    /**
     * Méthode centralisée pour envoyer un événement au Cerveau.
     * @param {string} type - Le type d'événement (ex: 'api:response.received').
     * @param {object} payload - Les données à envoyer.
     * @private
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true, detail: { type, source: this.nomControleur, payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }
}