import { Controller } from '@hotwired/stimulus';

/**
 * @class BaseController
 * @extends Controller
 * @description Un contrôleur de base qui fournit des méthodes utilitaires partagées,
 * notamment pour la communication avec le Cerveau.
 */
export default class extends Controller {
    /**
     * Notifie le Cerveau en envoyant un événement personnalisé.
     * @param {string} type - Le type d'événement pour le Cerveau (ex: 'ui:selection.updated').
     * @param {object} [payload={}] - Données additionnelles à envoyer.
     */
    notifyCerveau(type, payload = {}) {
        const event = new CustomEvent('cerveau:event', {
            bubbles: true, detail: { type, source: this.nomControleur || 'Unknown', payload, timestamp: Date.now() }
        });
        this.element.dispatchEvent(event);
    }

    /**
     * NOUVEAU : Diffuse un événement personnalisé depuis l'élément du contrôleur.
     * Permet une communication locale entre contrôleurs enfants et parents.
     * @param {string} name - Le nom de l'événement (ex: 'mon-controleur:action').
     * @param {object} [detail={}] - Les données à attacher à l'événement.
     */
    dispatch(name, detail = {}) {
        this.element.dispatchEvent(new CustomEvent(name, {
            bubbles: true,
            detail
        }));
    }
}

// LA CHAÎNE D'ICÔNES PAR COOKIE (defineIcone, setIcone, downloadIcone, updatTabIcones,
// getCookies, getIconeUrl, getIconeLocale, saveCookie) a été retirée le 2026-10-14 : plus
// aucun appelant, et `saveCookie` préfixait la valeur et posait une expiration invalide. Les
// cookies s'écrivent et se lisent désormais par controllers/cookies.js.

/**
 * 
 * @param {htmlElement} htmlElement 
 * @param {string} nomEvent 
 * @param {boolean} canBubble 
 * @param {boolean} canCompose 
 * @param {Array} detailTab 
 */
export function buildCustomEventForElement(htmlElement, nomEvent, canBubble, canCompose, detailTab) {
    const event = new CustomEvent(nomEvent, {
        bubbles: canBubble, composed: canCompose, detail: detailTab
    });
    htmlElement.dispatchEvent(event);
}
export const EVEN_CODE_ACTION_AJOUT = 0;
export const EVEN_CODE_ACTION_MODIFICATION = 1;
export const EVEN_CODE_ACTION_SUPPRESSION = 2;
export const EVEN_CODE_RESULTAT_OK = 0;
export const EVEN_CODE_RESULTAT_ECHEC = 1;
