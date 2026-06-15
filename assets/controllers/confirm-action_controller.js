import { Controller } from '@hotwired/stimulus';

/**
 * @class ConfirmActionController
 * @extends Controller
 * @description Intercale une boîte de dialogue de confirmation devant la soumission
 * d'un formulaire classique (non-AJAX), en réutilisant le système de confirmation
 * global de l'application (`ui:confirmation.request`).
 *
 * Flux :
 *  1. À la soumission, on annule l'envoi et on demande une confirmation.
 *  2. Si l'utilisateur confirme, le dialogue émet un `cerveau:event` du type unique
 *     attribué à ce contrôleur ; on soumet alors réellement le formulaire.
 *
 * `HTMLFormElement.submit()` ne redéclenche PAS l'événement « submit » → aucune
 * récursion, et la navigation pleine page fait disparaître le dialogue.
 *
 * Markup attendu (sur le <form>) :
 *   data-controller="confirm-action"
 *   data-action="submit->confirm-action#request"
 *   data-confirm-action-title-value="…"
 *   data-confirm-action-body-value="…"        (HTML autorisé)
 *   data-confirm-action-item-value="…"         (élément concerné, optionnel)
 *   data-confirm-action-irreversible-value="true"
 */
let instanceCounter = 0;

export default class extends Controller {
    static values = {
        title: { type: String, default: "Confirmer l'action" },
        body: { type: String, default: 'Voulez-vous vraiment continuer ?' },
        item: String,
        irreversible: Boolean,
    };

    connect() {
        instanceCounter += 1;
        this.confirmType = `confirm-action:${instanceCounter}`;
        this.boundOnCerveau = this.onCerveau.bind(this);
        document.addEventListener('cerveau:event', this.boundOnCerveau);
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundOnCerveau);
    }

    request(event) {
        event.preventDefault();
        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: this.titleValue,
                body: this.bodyValue,
                itemDescriptions: this.itemValue ? [this.itemValue] : [],
                showIrreversible: this.irreversibleValue,
                onConfirm: { type: this.confirmType, payload: {} },
            },
        }));
    }

    onCerveau(event) {
        if (event.detail?.type !== this.confirmType) {
            return;
        }
        // Confirmé : soumission réelle (navigation pleine page, le dialogue disparaît avec).
        this.element.submit();
    }
}
