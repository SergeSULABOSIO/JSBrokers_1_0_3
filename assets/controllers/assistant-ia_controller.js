import { Controller } from '@hotwired/stimulus';

/**
 * @class AssistantIaController
 * @description Rubrique « Assistant » (col-3) : gère la liste des conversations
 * de l'invité — création, ouverture du chat dans la COLONNE 4 (via l'événement
 * `app:workspace.open-html-in-visualization` du workspace-manager) et
 * suppression (confirmation par la modale générique du Cerveau, précédent
 * workspace-manager#requestLogout). Après création/suppression, le composant
 * entier est rechargé en AJAX (l'état de référence est côté serveur).
 */
export default class extends Controller {
    static targets = ['createButton'];

    static values = {
        componentUrl: String,
        createUrl: String,
        assistantNom: String,
    };

    connect() {
        this.boundHandleCerveauEvent = this.handleCerveauEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundHandleCerveauEvent);
    }

    disconnect() {
        document.removeEventListener('cerveau:event', this.boundHandleCerveauEvent);
    }

    /**
     * Crée une conversation puis ouvre directement son chat en col-4.
     * Double feedback pendant l'opération : barre de progression globale du
     * workspace (app:loading.start/stop) + état occupé du bouton lui-même
     * (spinner, libellé, aria-busy, anti double-clic).
     */
    async create() {
        if (this.creating) return;
        this.creating = true;
        this._setCreateBusy(true);
        document.dispatchEvent(new CustomEvent('app:loading.start'));

        try {
            const response = await fetch(this.createUrlValue, { method: 'POST' });
            if (!response.ok) {
                throw new Error(`Création impossible (HTTP ${response.status}).`);
            }
            const data = await response.json();
            await this.openChat(data.chatUrl, data.titre, data.id);
            await this.refreshComponent(); // remplace l'élément → bouton re-rendu à l'état repos
        } catch (error) {
            console.error('AssistantIa - création de conversation échouée :', error);
            this._setCreateBusy(false);
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
            this.creating = false;
        }
    }

    /** État occupé du bouton « Nouvelle conversation » (feedback local). */
    _setCreateBusy(busy) {
        if (!this.hasCreateButtonTarget) return;
        const button = this.createButtonTarget;
        button.disabled = busy;
        button.setAttribute('aria-busy', busy ? 'true' : 'false');
        button.querySelector('[data-role="spinner"]').style.display = busy ? 'inline-block' : 'none';
        button.querySelector('[data-role="icon"]').style.display = busy ? 'none' : 'inline-flex';
        button.querySelector('[data-role="label"]').textContent = busy ? 'Création…' : 'Nouvelle conversation';
    }

    /** Ouvre une conversation existante dans la colonne de visualisation. */
    async open(event) {
        const { chatUrl, convTitre, convId } = event.currentTarget.dataset;
        await this.openChat(chatUrl, convTitre, convId);
    }

    /** Demande confirmation avant suppression (modale générique du Cerveau). */
    requestDelete(event) {
        const { deleteUrl, convId, convTitre } = event.currentTarget.dataset;
        const safeTitre = (convTitre || 'cette conversation')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: 'Supprimer la conversation',
                body: `<p>Vous êtes sur le point de supprimer la conversation <strong>${safeTitre}</strong> et tous ses messages.</p><p>Cette action est irréversible. Voulez-vous continuer ?</p>`,
                showIrreversible: false,
                onConfirm: {
                    type: 'ia:conversation.delete-execute',
                    payload: { deleteUrl, convId },
                },
            },
        }));
    }

    /** Exécute la suppression une fois la confirmation donnée par l'utilisateur. */
    handleCerveauEvent(event) {
        if (event.detail?.type !== 'ia:conversation.delete-execute') return;
        const { deleteUrl, convId } = event.detail.payload || {};
        if (deleteUrl) {
            this.executeDelete(deleteUrl, convId);
        }
    }

    async executeDelete(deleteUrl, convId) {
        try {
            const response = await fetch(deleteUrl, { method: 'DELETE' });
            // 404 = conversation déjà supprimée (double clic, autre onglet) : même issue.
            if (!response.ok && response.status !== 404) {
                throw new Error(`Suppression impossible (HTTP ${response.status}).`);
            }

            // PROTOCOLE de la modale générique : c'est l'exécuteur qui la referme
            // (ui:confirmation.close) — sinon elle reste figée sur « En cours… ».
            document.dispatchEvent(new CustomEvent('ui:confirmation.close'));

            // Si le chat de cette conversation est ouvert en col-4, on ferme son
            // onglet (sinon il deviendrait orphelin : tout envoi ferait 404).
            const staleTab = document.querySelector(`[data-entity-id='ia-conv-${convId}'][data-entity-type='html'] .tab-item-close`);
            if (staleTab) {
                staleTab.click();
            }

            // Mise à jour OPTIMISTE : on retire la ligne localement (zéro
            // aller-retour serveur) ; on ne re-rend le composant que si la liste
            // devient vide (pour afficher l'état d'accueil).
            const item = this.element.querySelector(`.ai-conv-delete[data-conv-id='${convId}']`)?.closest('.ai-conv-item');
            if (item) {
                item.remove();
            }
            if (!this.element.querySelector('.ai-conv-item')) {
                await this.refreshComponent();
            }
        } catch (error) {
            console.error('AssistantIa - suppression de conversation échouée :', error);
            document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                detail: { error: 'La suppression de la conversation a échoué. Réessayez.' },
            }));
            await this.refreshComponent();
        }
    }

    /** Récupère le partial du chat puis demande son ouverture en colonne 4. */
    async openChat(chatUrl, titre, convId) {
        if (!chatUrl) return;
        document.dispatchEvent(new CustomEvent('app:loading.start'));
        let response;
        try {
            response = await fetch(chatUrl);
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop'));
        }
        if (!response.ok) {
            console.error(`AssistantIa - chargement du chat impossible (HTTP ${response.status}).`);
            return;
        }
        const html = await response.text();
        document.dispatchEvent(new CustomEvent('app:workspace.open-html-in-visualization', {
            detail: {
                html,
                title: titre || this.assistantNomValue || 'Assistant IA',
                iconAlias: 'assistant-ia',
                tabKey: `ia-conv-${convId}`,
            },
        }));
    }

    /** Recharge le composant entier (liste des conversations) en AJAX. */
    async refreshComponent() {
        const response = await fetch(this.componentUrlValue);
        if (!response.ok) return;
        const html = await response.text();
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        const fresh = template.content.firstElementChild;
        if (fresh) {
            this.element.replaceWith(fresh); // Stimulus reconnecte automatiquement.
        }
    }
}
