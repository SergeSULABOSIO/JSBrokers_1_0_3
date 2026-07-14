import { Controller } from '@hotwired/stimulus';

/**
 * @class AssistantChatController
 * @description Chat de l'assistant IA (panneau de la colonne 4). Envoi des
 * messages en JSON, bulle utilisateur optimiste, indicateur contextuel
 * (« {nom} réfléchit… » pendant l'attente serveur puis « {nom} écrit… »
 * pendant le déploiement mot à mot de la réponse), gestion du 402 (solde de
 * tokens épuisé) et des erreurs réseau. Tout contenu est injecté via
 * textContent (échappement systématique).
 */
export default class extends Controller {
    static targets = ['messages', 'input', 'send', 'typing', 'typingLabel', 'count', 'contextBar'];

    /** Seuil d'affichage du compteur de caractères restants (proche de maxlength). */
    static COUNT_THRESHOLD = 400;

    /** Bornes du rythme de déploiement mot à mot (ms par mot). */
    static TYPE_DELAY_MAX = 45;
    static TYPE_DELAY_MIN = 12;
    /** Durée totale visée pour le déploiement d'une réponse (ms). */
    static TYPE_TOTAL_TARGET = 6000;

    static values = {
        sendUrl: String,
        dialogContextUrl: String,
        visualContextUrl: String,
        contexteUrl: String,
        idEntreprise: Number,
        idInvite: Number,
        idConversation: Number,
        assistantNom: String,
    };

    connect() {
        this.sending = false;
        this.scrollToBottom();
        this.onInput();
        if (this.hasInputTarget) {
            this.inputTarget.focus();
        }
        // Annonce silencieuse de l'état du contexte (badges « déjà en
        // contexte » des listes) — les puces initiales sont rendues serveur.
        this.emitContexteOperation({ phase: 'announce', objets: this.contexteObjets() });
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
        this.setTypingLabel('réfléchit…');
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
                // La réponse se déploie mot après mot (façon ChatGPT/Claude) ;
                // l'indicateur bascule de « réfléchit… » à « écrit… ».
                this.setTypingLabel('écrit…');
                await this.typeMessage(data.assistant.contenu, data.assistant.refus === true);
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
                case 'open-url':
                    this.openUrlAction(action);
                    break;
                case 'open-soa-envoi':
                    this.openSoaEnvoiAction(action);
                    break;
            }
        }
    }

    /**
     * Ouvre une URL d'export (Excel comptable, note/bordereau PDF) dans un
     * nouvel onglet. Garde-fou : uniquement un chemin relatif de l'application
     * (/admin/…) — la route cible porte sa propre sécurité (périmètre, métrage).
     */
    openUrlAction(action) {
        const url = String(action.url || '');
        if (!url.startsWith('/admin/') || url.startsWith('//') || url.includes(':')) return;
        window.open(url, '_blank', 'noopener');
    }

    /**
     * Prépare l'envoi du SOA d'un client : même événement que l'action « Envoyer
     * le SOA par e-mail » du menu contextuel — le cerveau ouvre le picker de
     * destinataires (re-validation serveur), l'utilisateur confirme lui-même.
     */
    openSoaEnvoiAction(action) {
        const id = parseInt(action.clientId, 10);
        if (!Number.isInteger(id) || id <= 0) return;
        document.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type:      'ui:soa.send-request',
                source:    'assistant-chat',
                payload:   { url: `/admin/soa/client/${id}/envoi-picker` },
                timestamp: Date.now(),
            },
        }));
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
            // Pré-remplissage proposé par l'assistant : transmis au serveur qui
            // le WHITELISTE (champs scalaires mappés uniquement) — seule sa
            // réponse (result.prefill) sera posée dans le formulaire.
            if (action.valeurs && typeof action.valeurs === 'object') {
                url.searchParams.set('valeurs', JSON.stringify(action.valeurs));
            }

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
                    prefill:       result.prefill || null,
                },
            }));
        } catch (error) {
            console.error('AssistantChat - ouverture de dialogue échouée :', error);
            this.appendNotice('error', error.message || "L'ouverture du formulaire a échoué.");
        }
    }

    // ── Objets attachés au contexte de la conversation ──────────────────────

    /**
     * Attache la sélection transmise par le cerveau (action « Ajouter au chat
     * avec l'assistant IA » de la toolbar / du menu contextuel) : l'événement
     * DOM `assistant:contexte.attach-request` est dispatché sur CE panneau.
     */
    async attachFromEvent(event) {
        const objets = Array.isArray(event.detail?.objets) ? event.detail.objets : [];
        if (objets.length === 0 || !this.hasContexteUrlValue) return;

        await this.contexteOperation(
            () => fetch(this.contexteUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ objets }),
            }),
            (data) => {
                // Libellé du toast : le nom de l'objet quand il est seul, le
                // décompte au-delà (les doublons idempotents comptent « déjà là »).
                const attaches = (data.contextes || []).filter((c) => objets.some(
                    (o) => o.type === c.entityType && Number(o.id) === Number(c.entityId)
                ));
                if (data.ignores > 0) {
                    this.appendNotice('warning', `${data.ignores} objet${data.ignores > 1 ? 's' : ''} hors périmètre ou introuvable${data.ignores > 1 ? 's' : ''} ignoré${data.ignores > 1 ? 's' : ''}.`);
                }
                if (attaches.length === 0) {
                    return { message: 'Aucun objet ajouté au contexte.', level: 'warning' };
                }
                return {
                    message: attaches.length === 1
                        ? `« ${attaches[0].label} » attaché au contexte du chat actif.`
                        : `${attaches.length} objets attachés au contexte du chat actif.`,
                    level: 'success',
                };
            },
        );
    }

    /** Retire UN objet du contexte (bouton × d'une puce). */
    async removeContexte(event) {
        const idContexte = parseInt(event.currentTarget.dataset.contexteId, 10);
        if (!Number.isInteger(idContexte) || !this.hasContexteUrlValue) return;
        const label = event.currentTarget.closest('.aic-chip')?.querySelector('.aic-chip-label')?.textContent?.trim();

        await this.contexteOperation(
            () => fetch(`${this.contexteUrlValue}/${idContexte}`, { method: 'DELETE' }),
            () => ({
                message: label ? `« ${label} » retiré du contexte de la conversation.` : 'Objet retiré du contexte.',
                level: 'success',
            }),
        );
    }

    /** Vide le contexte (bouton « Tout retirer »). */
    async clearContextes() {
        if (!this.hasContexteUrlValue) return;

        await this.contexteOperation(
            () => fetch(this.contexteUrlValue, { method: 'DELETE' }),
            () => ({ message: 'Contexte de la conversation vidé.', level: 'success' }),
        );
    }

    /**
     * Déroulé commun d'une opération sur le contexte : cycle de feedback
     * `ui:assistant.contexte-operation` start/end (barre de progression + toast
     * + synchro des badges de listes, routé par le cerveau), re-rendu des puces
     * depuis le fragment HTML serveur (chemin de rendu unique), gestion du 402
     * (premium / solde) identique à send().
     */
    async contexteOperation(doFetch, buildSuccess) {
        this.emitContexteOperation({ phase: 'start' });
        let message = "L'opération sur le contexte a échoué. Veuillez réessayer.";
        let level = 'error';

        try {
            const response = await doFetch();
            const data = await response.json().catch(() => ({}));

            if (response.status === 402) {
                message = data.premium ? (data.message || 'Fonctionnalité premium.') : this.tokensMessage(data);
                this.appendNotice('warning', message);
                level = 'warning';
            } else if (!response.ok) {
                message = data.message || message;
                this.appendNotice('error', message);
            } else {
                this.renderContextes(data.html || '');
                ({ message, level } = buildSuccess(data));
            }
        } catch (error) {
            console.error('AssistantChat - opération contexte échouée :', error);
            this.appendNotice('error', message);
        } finally {
            this.emitContexteOperation({ phase: 'end', message, level, objets: this.contexteObjets() });
        }
    }

    /** Remplace les puces par le fragment rendu côté serveur (Twig échappe tout). */
    renderContextes(html) {
        if (this.hasContextBarTarget) {
            this.contextBarTarget.innerHTML = html;
        }
    }

    /** État courant du contexte, lu depuis les puces rendues serveur. */
    contexteObjets() {
        if (!this.hasContextBarTarget) return [];
        return [...this.contextBarTarget.querySelectorAll('.aic-chip')].map((chip) => ({
            type: chip.dataset.entityType,
            id: parseInt(chip.dataset.entityId, 10),
        })).filter((o) => o.type && Number.isInteger(o.id));
    }

    /** Émet le cycle de feedback contexte vers le cerveau (médiateur). */
    emitContexteOperation(payload) {
        document.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type:      'ui:assistant.contexte-operation',
                source:    'assistant-chat',
                payload:   { idConversation: this.idConversationValue, ...payload },
                timestamp: Date.now(),
            },
        }));
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

    /** Libellé contextuel de l'indicateur (« {nom} réfléchit… » / « {nom} écrit… »). */
    setTypingLabel(verbe) {
        if (!this.hasTypingLabelTarget) return;
        this.typingLabelTarget.textContent = `${this.assistantNomValue || 'Assistant'} ${verbe}`;
    }

    /**
     * Déploie la réponse de l'assistant mot après mot dans une bulle (effet
     * machine à écrire). Le rythme s'adapte à la longueur pour que les
     * réponses longues ne s'éternisent pas ; si le panneau est fermé en cours
     * de route, le texte complet est posé d'un coup et la boucle s'arrête.
     */
    async typeMessage(texte, refus = false) {
        const bubble = this.appendMessage('assistant', '', refus);
        const content = bubble.querySelector('.aic-msg-text');
        // Le fil est une zone aria-live : on masque la bulle pendant le
        // déploiement pour éviter une annonce du lecteur d'écran à chaque mot,
        // puis on la révèle entière (une seule annonce).
        bubble.setAttribute('aria-hidden', 'true');
        const mots = texte.match(/\S+\s*/g) || [texte];
        const delai = Math.max(
            this.constructor.TYPE_DELAY_MIN,
            Math.min(this.constructor.TYPE_DELAY_MAX, Math.round(this.constructor.TYPE_TOTAL_TARGET / mots.length))
        );
        for (const mot of mots) {
            if (!this.element.isConnected) {
                break;
            }
            content.textContent += mot;
            this.scrollToBottom();
            await new Promise((resolve) => setTimeout(resolve, delai));
        }
        content.textContent = texte; // garantit le texte intégral quoi qu'il arrive
        bubble.removeAttribute('aria-hidden');
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
