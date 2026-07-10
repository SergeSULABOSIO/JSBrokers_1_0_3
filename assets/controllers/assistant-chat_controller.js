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
                this.appendNotice('warning', this.tokensMessage(data));
            } else if (!response.ok) {
                userBubble.remove();
                this.inputTarget.value = contenu;
                this.appendNotice('error', "L'envoi a échoué. Vérifiez votre connexion puis réessayez.");
            } else {
                const data = await response.json();
                this.appendMessage('assistant', data.assistant.contenu, data.assistant.refus === true);
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
