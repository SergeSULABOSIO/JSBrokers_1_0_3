import { Controller } from '@hotwired/stimulus';

/**
 * @class FlashToastController
 * @extends Controller
 * @description Relaie les messages flash rendus côté serveur vers le gestionnaire
 * de notifications global (toasts), au lieu de les afficher en alerte « en ligne ».
 * Le rendu visuel reste celui du système de toast existant (notification-manager),
 * pour une cohérence d'ensemble.
 *
 * Ergonomie / accessibilité :
 *  - Visibilité de l'état du système (Nielsen #1) : retour immédiat, non intrusif.
 *  - Le toast applicatif porte déjà `role="alert"` / `aria-live` (notification-manager).
 *
 * Markup attendu :
 *   <div data-controller="flash-toast"
 *        data-flash-toast-messages-value='[{"type":"success","text":"…"}]'></div>
 */
export default class extends Controller {
    static values = {
        messages: { type: Array, default: [] },
    };

    connect() {
        // Différé d'un frame : on garantit que notification-manager s'est enregistré
        // comme écouteur (il est plus bas dans le DOM) avant d'émettre les événements.
        window.requestAnimationFrame(() => {
            this.messagesValue.forEach(({ type, text }) => {
                document.dispatchEvent(new CustomEvent('app:notification.show', {
                    detail: { type: type || 'info', text },
                }));
            });
        });
    }
}
