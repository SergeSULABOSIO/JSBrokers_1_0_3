import { Controller } from '@hotwired/stimulus';

/**
 * @class RetroAgentRapportController
 * @description Chips de filtre du rapport de production d'un agent : Souscrites, En
 * attente, Caduques. Chaque chip recharge le rapport dans le MÊME onglet de la zone de
 * travail, sans en ouvrir un second.
 *
 * Le contrôleur ne filtre rien lui-même : la partition des statuts est une règle serveur
 * (CotationSouscriptionScope), et les montants d'une projection ne se déduisent pas de ceux
 * d'une affaire souscrite. Il redemande donc la page au serveur, qui reste la seule source.
 */
export default class extends Controller {
    static values = {
        baseUrl: String,
    };

    connect() {
        this.nomControleur = 'RETRO-AGENT-RAPPORT';
    }

    filtrer(event) {
        event.preventDefault();
        const statut = event.currentTarget?.dataset?.statut;
        if (!statut) return;

        // Même événement que l'action de la barre d'outils : le cerveau réinjecte le HTML
        // dans l'onglet existant (tabKey identique), plutôt que d'en empiler un nouveau.
        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type: 'ui:retroagent.rapport-request',
                source: this.nomControleur,
                payload: { url: `${this.baseUrlValue}?statut=${encodeURIComponent(statut)}` },
                timestamp: Date.now(),
            },
        }));
    }
}
