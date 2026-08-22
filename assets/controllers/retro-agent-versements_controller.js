import { Controller } from '@hotwired/stimulus';

/**
 * @class RetroAgentVersementsController
 * @description Les deux gestes du volet « Versements enregistrés » : joindre un
 * justificatif à un virement, et relire ceux qu'il porte déjà.
 *
 * ── AUCUNE PLOMBERIE NEUVE ──────────────────────────────────────────────────────────
 * Ce contrôleur n'ouvre rien lui-même. Il émet les MÊMES événements que les actions
 * documentaires d'une fiche — `ui:documents.attach-request` et
 * `ui:documents.liste-request` — que le cerveau traite déjà pour toutes les rubriques.
 * La boîte qui s'ouvre est donc exactement celle que l'utilisateur connaît, avec son
 * métrage de jetons et ses refus nommés. Réécrire ici un envoi de fichiers aurait fait un
 * second chemin d'écriture à côté d'un premier, payant.
 *
 * ── ON ÉCRIT SUR LE PORTEUR, ON LIT LE LOT ──────────────────────────────────────────
 * « Attacher » vise le porteur du lot : le fichier n'est écrit qu'une fois, quel que soit
 * le nombre d'affaires réglées par le virement. « Voir » interroge une route qui rend
 * l'UNION des pièces du lot — l'assistant a pu classer la sienne sur n'importe quel
 * membre, et une lecture limitée au porteur l'aurait rendue invisible.
 */
export default class extends Controller {
    static values = { attacherUrl: String };

    connect() {
        this.nomControleur = 'RETRO-AGENT-VERSEMENTS';
    }

    /** Joindre : la boîte habituelle, sur le porteur du lot. */
    attacher(event) {
        event.preventDefault();
        const porteurId = event.currentTarget?.dataset?.porteurId;
        if (!porteurId || !this.hasAttacherUrlValue) return;

        // Le gabarit d'URL porte un 0 : le serveur ne connaît pas le porteur au rendu de
        // la page, il n'y a qu'un patron à compléter.
        this._demander('ui:documents.attach-request', this.attacherUrlValue.replace(/\/0(?=(\?|#|$))/, `/${porteurId}`));
    }

    /** Relire : les pièces du VIREMENT, d'où qu'elles viennent. */
    voir(event) {
        event.preventDefault();
        const url = event.currentTarget?.dataset?.url;
        if (!url) return;

        this._demander('ui:documents.liste-request', url);
    }

    /** @private */
    _demander(type, url) {
        this.element.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type,
                source: this.nomControleur,
                payload: { url },
                timestamp: Date.now(),
            },
        }));
    }
}
