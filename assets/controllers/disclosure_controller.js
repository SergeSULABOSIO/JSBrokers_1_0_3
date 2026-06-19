import { Controller } from '@hotwired/stimulus';

/**
 * @class DisclosureController
 * @extends Controller
 * @description Révèle / masque une zone de formulaire pilotée par une case à cocher.
 *
 * Ergonomie : information progressive (Bastien & Scapin — Charge de travail) ;
 * le champ facultatif n'apparaît que si l'utilisateur le demande explicitement.
 * Accessibilité : la case expose son état via `aria-expanded` et pointe la zone
 * via `aria-controls` (WCAG 4.1.2) ; la zone masquée sort du flux et du parcours
 * clavier grâce à l'attribut natif `[hidden]`.
 *
 * Markup attendu :
 *   <div data-controller="disclosure">
 *     <input type="checkbox"
 *            data-disclosure-target="trigger"
 *            data-action="disclosure#toggle"
 *            aria-controls="region-id">
 *     <div id="region-id" data-disclosure-target="region" hidden>
 *       <input data-disclosure-target="field">
 *     </div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['trigger', 'region', 'field'];

    connect() {
        // Aligne l'affichage sur l'état initial de la case (ex. retour serveur
        // après erreur de validation : la case reste cochée, le champ visible).
        this.sync();
    }

    toggle() {
        this.sync();
        // À l'ouverture, on place le focus sur le champ révélé (continuité de saisie).
        if (this.isOpen && this.hasFieldTarget) {
            this.fieldTarget.focus();
        }
    }

    sync() {
        const open = this.isOpen;

        if (this.hasRegionTarget) {
            this.regionTarget.hidden = !open;
        }
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        // Champ masqué : on le vide pour ne rien envoyer d'involontaire au serveur.
        if (!open && this.hasFieldTarget) {
            this.fieldTarget.value = '';
        }
    }

    get isOpen() {
        return this.hasTriggerTarget && this.triggerTarget.checked;
    }
}
