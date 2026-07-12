import { Controller } from '@hotwired/stimulus';

/**
 * @class AssistantChatController
 * @description Chat de l'assistant IA (panneau de la colonne 4). Envoi des
 * messages en JSON, bulle utilisateur optimiste, indicateur « {nom}
 * réfléchit… », gestion du 402 (solde de tokens épuisé) et des erreurs réseau.
 * Tout contenu est injecté via textContent (échappement systématique).
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'send', 'typing', 'count'];

    /** Seuil d'affichage du compteur de caractères restants (proche de maxlength). */
    static COUNT_THRESHOLD = 400;

    static values = {
        sendUrl: String,
        dialogContextUrl: String,
        visualContextUrl: String,
        idEntreprise: Number,
        idInvite: Number,
        assistantNom: String,
    };

    connect() {
        this.sending = false;
        this.scrollToBottom();
        this.onInput();
        if (this.hasInputTarget) {
            this.inputTarget.focus();
        }
    }

    /**
     * À chaque saisie : hauteur auto, bouton d'envoi actif seulement si le
     * message est non vide (prévention des envois vides), compteur de
     * caractères restants affiché à l'approche de la limite.
     */
    onInput() {
        this.autoGrow();
        this.updateSendState();
        this.updateCount();
    }

    updateSendState() {
        if (!this.hasSendTarget || !this.hasInputTarget) return;
        this.sendTarget.disabled = this.sending || this.inputTarget.value.trim() === '';
    }

    updateCount() {
        if (!this.hasCountTarget || !this.hasInputTarget) return;
        const max = Number(this.inputTarget.getAttribute('maxlength')) || 4000;
        const restants = max - this.inputTarget.value.length;
        const proche = restants <= this.constructor.COUNT_THRESHOLD;
        this.countTarget.hidden = !proche;
        if (proche) {
            this.countTarget.textContent = `${restants} caractère${restants > 1 ? 's' : ''} restant${restants > 1 ? 's' : ''}`;
            this.countTarget.classList.toggle('aic-count--limite', restants <= 50);
        }
    }

    /** Entrée = envoyer, Maj+Entrée = retour à la ligne. */
    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send();
        }
    }

    /** Zone de saisie auto-extensible (max ~8 lignes). */
    autoGrow() {
        const input = this.inputTarget;
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 160)}px`;
    }

    async send() {
        const contenu = this.inputTarget.value.trim();
        if (contenu === '' || this.sending) return;

        this.sending = true;
        this.sendTarget.disabled = true;
        const userBubble = this.appendMessage('user', contenu);
        this.inputTarget.value = '';
        this.onInput();
        this.typingTarget.hidden = false;
        this.scrollToBottom();

        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contenu }),
            });

            if (response.status === 402) {
                const data = await response.json().catch(() => ({}));
                userBubble.remove();
                this.inputTarget.value = contenu;
                // Deux blocages distincts : premium (pas de solde payant) vs
                // solde insuffisant (message chiffré construit localement).
                this.appendNotice('warning', data.premium ? (data.message || 'Fonctionnalité premium.') : this.tokensMessage(data));
            } else if (!response.ok) {
                userBubble.remove();
                this.inputTarget.value = contenu;
                this.appendNotice('error', "L'envoi a échoué. Vérifiez votre connexion puis réessayez.");
            } else {
                const data = await response.json();
                this.appendMessage('assistant', data.assistant.contenu, data.assistant.refus === true);
                await this.executeActions(data.assistant.actions);
            }
        } catch (error) {
            console.error('AssistantChat - envoi échoué :', error);
            userBubble.remove();
            this.inputTarget.value = contenu;
            this.appendNotice('error', "L'envoi a échoué. Vérifiez votre connexion puis réessayez.");
        } finally {
            this.typingTarget.hidden = true;
            this.sending = false;
            this.onInput(); // recalcul : bouton actif seulement si le champ est non vide
            this.inputTarget.focus();
            this.scrollToBottom();
        }
    }

    /**
     * Traduit les directives d'intention de l'assistant (AiReply.actions) sur
     * le bus d'événements du workspace. L'assistant n'écrit rien : une action
     * 'open-dialog' ouvre le formulaire standard (validation serveur incluse),
     * l'utilisateur relit et enregistre lui-même.
     */
    async executeActions(actions) {
        if (!Array.isArray(actions)) return;
        for (const action of actions) {
            if (!action) continue;
            switch (action.type) {
                case 'open-dialog':
                    await this.openDialogAction(action);
                    break;
                case 'open-visualization':
                    await this.openVisualizationAction(action);
                    break;
                case 'open-rubrique':
                    // Navigation pure : le workspace-manager rejoue le clic de menu.
                    document.dispatchEvent(new CustomEvent('app:workspace.open-rubrique', {
                        detail: { entityName: action.entite },
                    }));
                    break;
                case 'close-workspace':
                    // Fermeture de l'espace de travail : le workspace-manager ouvre
                    // la boîte de confirmation — l'utilisateur valide manuellement.
                    document.dispatchEvent(new CustomEvent('app:workspace.request-logout'));
                    break;
            }
        }
    }

    /**
     * Ouvre une fiche dans la colonne de visualisation : récupère le contexte
     * (entité + canvas) auprès de l'endpoint visual-context (qui RE-VALIDE les
     * droits, fail-closed) puis rejoue le circuit standard des listes
     * (app:liste-element:openned).
     */
    async openVisualizationAction(action) {
        if (!this.hasVisualContextUrlValue) return;
        try {
            const url = new URL(this.visualContextUrlValue, window.location.origin);
            url.searchParams.set('entite', action.entite || '');
            url.searchParams.set('id', action.id || '');

            const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Erreur serveur ${response.status}`);

            document.dispatchEvent(new CustomEvent('app:liste-element:openned', {
                detail: {
                    entity:       result.entity,
                    entityType:   result.entityType,
                    entityCanvas: result.entityCanvas,
                },
            }));
        } catch (error) {
            console.error('AssistantChat - visualisation échouée :', error);
            this.appendNotice('error', error.message || "L'ouverture de la fiche a échoué.");
        }
    }

    /**
     * Ouvre le dialogue demandé : récupère entité + canevas auprès de l'endpoint
     * dialog-context (qui RE-VALIDE les droits, fail-closed) puis dispatche
     * app:boite-dialogue:init-request — même payload que cerveau.openDialogBox
     * (miroir de handleAvenantPisteDeriveeFormRequest).
     */
    async openDialogAction(action) {
        if (!this.hasDialogContextUrlValue) return;
        try {
            const url = new URL(this.dialogContextUrlValue, window.location.origin);
            url.searchParams.set('entite', action.entite || '');
            url.searchParams.set('mode', action.mode || 'creation');
            if (action.id) url.searchParams.set('id', action.id);

            const response = await fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(result.message || `Erreur serveur ${response.status}`);

            document.dispatchEvent(new CustomEvent('app:boite-dialogue:init-request', {
                detail: {
                    entity:           result.entity || {},
                    entityFormCanvas: result.formCanvas,
                    isCreationMode:   result.mode !== 'edition',
                    context: {
                        idEntreprise: this.idEntrepriseValue,
                        idInvite:     this.idInviteValue,
                    },
                    parentContext: null,
                },
            }));
        } catch (error) {
            console.error('AssistantChat - ouverture de dialogue échouée :', error);
            this.appendNotice('error', error.message || "L'ouverture du formulaire a échoué.");
        }
    }

    /** Message 402 : solde et date de renouvellement si fournis par l'API. */
    tokensMessage(data) {
        let message = 'Solde de tokens insuffisant';
        if (typeof data.available === 'number' && typeof data.required === 'number') {
            message += ` (${data.available}/${data.required})`;
        }
        message += '. Rechargez votre solde';
        if (data.nextRenewalAt) {
            const date = new Date(data.nextRenewalAt);
            message += ` ou attendez le renouvellement du ${date.toLocaleString('fr-FR')}`;
        }
        return `${message}.`;
    }

    /**
     * Ajoute une bulle de message au fil (structure identique à celle rendue
     * côté serveur dans _assistant_ia_chat.html.twig).
     */
    appendMessage(role, texte, refus = false) {
        const bubble = document.createElement('div');
        bubble.className = `aic-msg aic-msg--${role}${refus ? ' aic-msg--refus' : ''}`;

        if (role === 'assistant') {
            const avatar = document.createElement('span');
            avatar.className = 'aic-msg-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = (this.assistantNomValue || 'A').charAt(0).toUpperCase();
            bubble.appendChild(avatar);
        }

        const body = document.createElement('div');
        body.className = 'aic-msg-body';

        const content = document.createElement('p');
        content.className = 'aic-msg-text';
        content.textContent = texte; // Échappement garanti.
        body.appendChild(content);

        const time = document.createElement('span');
        time.className = 'aic-msg-time';
        time.textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        body.appendChild(time);

        bubble.appendChild(body);
        this.messagesTarget.appendChild(bubble);
        this.scrollToBottom();
        return bubble;
    }

    /** Bulle système (avertissement 402 / erreur réseau). */
    appendNotice(kind, texte) {
        const notice = document.createElement('p');
        notice.className = `aic-notice aic-notice--${kind}`;
        notice.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        notice.textContent = texte;
        this.messagesTarget.appendChild(notice);
        this.scrollToBottom();
    }

    scrollToBottom() {
        if (this.hasMessagesTarget) {
            this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        }
    }
}
