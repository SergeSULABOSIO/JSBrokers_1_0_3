import { Controller } from '@hotwired/stimulus';

/**
 * @class CrmHeartbeatController
 * @description Maintient le CRM « vivant » sans cron : pendant qu'un agent a la
 * console ouverte, envoie un ping discret à l'endpoint heartbeat à intervalle
 * régulier. Le serveur, lui, n'exécute la routine quotidienne qu'une fois par
 * fenêtre (throttle atomique) et après la réponse (kernel.terminate). Le ping ne
 * fait donc qu'« offrir des occasions » de déclenchement ; il n'impose rien.
 *
 * Markup : <div data-controller="crm-heartbeat" data-crm-heartbeat-url-value="…">.
 */
export default class extends Controller {
    static values = {
        url: String,
        intervalMs: { type: Number, default: 900000 }, // 15 min
    };

    connect() {
        // Premier ping peu après le chargement, puis à intervalle régulier.
        this.timer = window.setInterval(() => this.ping(), this.intervalMsValue);
        this.kickoff = window.setTimeout(() => this.ping(), 5000);
    }

    disconnect() {
        window.clearInterval(this.timer);
        window.clearTimeout(this.kickoff);
    }

    ping() {
        if (document.hidden) {
            return; // onglet en arrière-plan : inutile de solliciter le serveur
        }
        fetch(this.urlValue, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, keepalive: true })
            .catch(() => { /* silencieux : le ping est best-effort */ });
    }
}
