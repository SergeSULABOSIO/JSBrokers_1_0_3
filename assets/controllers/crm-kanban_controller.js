import { Controller } from '@hotwired/stimulus';

/**
 * @class CrmKanbanController
 * @description Tableau Kanban du pipeline commercial. Le glisser-déposer d'une
 * carte (client) vers une colonne force manuellement l'étape correspondante via
 * un POST vers l'endpoint `move`. En cas de succès, la carte est déplacée dans le
 * DOM et les compteurs de colonnes sont mis à jour ; sinon, un toast d'erreur est
 * émis et le tableau reste inchangé (cohérence visuelle).
 *
 * Markup : conteneur avec data-crm-kanban-url-value (URL contenant le jeton
 * « __ID__ » à substituer par l'id du client) et data-crm-kanban-token-value
 * (jeton CSRF). Colonnes = [data-crm-kanban-target=col][data-stage]. Cartes =
 * [data-crm-kanban-target=card][data-user-id][draggable=true].
 */
export default class extends Controller {
    static targets = ['col', 'card'];
    static values = { base: String, token: String };

    start(event) {
        this.draggedId = event.currentTarget.dataset.userId;
        this.draggedEl = event.currentTarget;
        event.dataTransfer.effectAllowed = 'move';
        // Évite que le clic-navigation se déclenche après un drag.
        event.dataTransfer.setData('text/plain', this.draggedId);
    }

    end() {
        this.draggedEl = null;
        this.draggedId = null;
        this.colTargets.forEach((c) => c.classList.remove('is-over'));
    }

    over(event) {
        event.preventDefault();
        event.currentTarget.classList.add('is-over');
        event.dataTransfer.dropEffect = 'move';
    }

    leave(event) {
        event.currentTarget.classList.remove('is-over');
    }

    async drop(event) {
        event.preventDefault();
        const col = event.currentTarget;
        col.classList.remove('is-over');

        const stage = col.dataset.stage;
        if (!this.draggedId || !stage || !this.draggedEl) {
            return;
        }

        const body = new URLSearchParams();
        body.set('_token', this.tokenValue);
        body.set('etape', stage);

        try {
            const response = await fetch(`${this.baseValue}/${this.draggedId}/move`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const data = await response.json();

            if (response.ok && data.ok) {
                const sourceCol = this.draggedEl.closest('[data-crm-kanban-target="col"]');
                col.appendChild(this.draggedEl);
                this.refreshCount(sourceCol);
                this.refreshCount(col);
                this.notify(`Étape mise à jour : ${data.label}`, 'success');
            } else {
                this.notify('Déplacement refusé.', 'error');
            }
        } catch (e) {
            this.notify('Erreur réseau lors du déplacement.', 'error');
        }
    }

    refreshCount(col) {
        if (!col) {
            return;
        }
        const counter = col.querySelector('.crm-col__count');
        if (counter) {
            counter.textContent = col.querySelectorAll('[data-crm-kanban-target="card"]').length;
        }
    }

    notify(text, type) {
        document.dispatchEvent(new CustomEvent('app:notification.show', { detail: { text, type } }));
    }
}
