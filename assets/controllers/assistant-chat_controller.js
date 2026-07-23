import { Controller } from '@hotwired/stimulus';
import { renderAssistantMarkdown } from './assistant-markdown-render.js';
import { formatInstant } from '../datetime-format.js';
import { formatNombre } from '../number-format.js';

/**
 * @class AssistantChatController
 * @description Chat de l'assistant IA (panneau de la colonne 4). Envoi des
 * messages en JSON, bulle utilisateur optimiste, indicateur contextuel
 * (« {nom} réfléchit… » pendant l'attente serveur puis « {nom} écrit… »
 * pendant le déploiement mot à mot de la réponse), gestion du 402 (solde de
 * tokens épuisé) et des erreurs réseau. Bulle utilisateur : textContent
 * (échappement systématique). Bulle assistant : Markdown restreint rendu et
 * sanitisé via assistant-markdown-render.js (jamais de HTML brut du LLM).
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

    /** Icônes SVG (lucide, stroke currentColor) des boutons de décision — statiques, sûres. */
    static ICON_CHECK = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
    static ICON_X = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    static ICON_WALLET = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>';

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
        this.renderHistoricalMarkdown();
        this.scrollToBottom();
        this.onInput();
        if (this.hasInputTarget) {
            this.inputTarget.focus();
        }
        // Annonce silencieuse de l'état du contexte (badges « déjà en
        // contexte » des listes) — les puces initiales sont rendues serveur.
        this.emitContexteOperation({ phase: 'announce', objets: this.contexteObjets() });

        // Infobulle sombre des puces de contexte (pattern du bloc Pistes du
        // tableau de bord : élément flottant au <body>, suit le curseur).
        // Délégation sur la barre de contexte : survit aux re-rendus innerHTML.
        this._ctxTip = null;
        this._ctxTipActive = false;
        this._ctxTipPinned = false;
        this._onCtxTipOver = this._ctxTipOver.bind(this);
        this._onCtxTipOut = this._ctxTipOut.bind(this);
        this._onCtxTipMove = this._ctxTipMove.bind(this);
        if (this.hasContextBarTarget) {
            this.contextBarTarget.addEventListener('mouseover', this._onCtxTipOver);
            this.contextBarTarget.addEventListener('mouseout', this._onCtxTipOut);
        }
        // Agrafes des bulles utilisateur (instantané de contexte du message) :
        // même infobulle sombre, déléguée au fil de messages (survit aux ajouts).
        if (this.hasMessagesTarget) {
            this.messagesTarget.addEventListener('mouseover', this._onCtxTipOver);
            this.messagesTarget.addEventListener('mouseout', this._onCtxTipOut);
        }
        document.addEventListener('mousemove', this._onCtxTipMove);

        // Exécution d'un plan de mutation confirmé : la modale de confirmation
        // notifie le bus cerveau:event ; on capte notre type dédié.
        this._onMutationExecute = this.executeFromEvent.bind(this);
        document.addEventListener('cerveau:event', this._onMutationExecute);
    }

    disconnect() {
        if (this._onMutationExecute) {
            document.removeEventListener('cerveau:event', this._onMutationExecute);
        }
        if (this.hasContextBarTarget) {
            this.contextBarTarget.removeEventListener('mouseover', this._onCtxTipOver);
            this.contextBarTarget.removeEventListener('mouseout', this._onCtxTipOut);
        }
        if (this.hasMessagesTarget) {
            this.messagesTarget.removeEventListener('mouseover', this._onCtxTipOver);
            this.messagesTarget.removeEventListener('mouseout', this._onCtxTipOut);
        }
        document.removeEventListener('mousemove', this._onCtxTipMove);
        if (this._ctxTip) {
            this._ctxTip.remove();
            this._ctxTip = null;
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
        const userBubble = this.appendMessage('user', contenu, false, this.contexteInstantane());
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
                case 'signaler-paiement-prime':
                    this.openSignalerPaiementPrimeAction(action);
                    break;
                case 'ket-mutation.review':
                    this.renderMutationReview(action);
                    break;
            }
        }
    }

    /**
     * Barre d'action sous le plan d'écriture/suppression préparé par Ket. Le
     * plan lui-même (tableaux + budget) est déjà rendu dans le message. Ici on
     * ajoute la décision : « Valider et exécuter » (ouvre la confirmation) /
     * « Annuler » — ou, si le solde est insuffisant, un CTA d'achat de tokens.
     */
    renderMutationReview(action) {
        if (!action || !action.idMessage) return;
        const budget = action.budget || {};
        const cout = budget.coutEstime || 0;
        const solde = budget.soldeDisponible || 0;
        const reste = budget.resteApres ?? Math.max(0, solde - cout);
        const suffisant = budget.suffisant !== false;

        const bar = document.createElement('div');
        bar.className = 'aic-mutation-actions';
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Décision sur le plan préparé par l’assistant');

        // Budget TOUJOURS affiché (garantie serveur, indépendante de la prose du
        // modèle), en pastilles lisibles : coût · solde · reste.
        const budgetRow = document.createElement('div');
        budgetRow.className = 'aic-mut-budget';
        budgetRow.appendChild(this._budgetChip('Coût estimé', `${formatNombre(cout)} tokens`));
        budgetRow.appendChild(this._budgetChip('Solde', formatNombre(solde)));
        budgetRow.appendChild(this._budgetChip('Reste', formatNombre(reste), suffisant ? 'ok' : 'danger'));
        bar.appendChild(budgetRow);

        if (!suffisant) {
            const notice = document.createElement('p');
            notice.className = 'aic-notice aic-notice--warning';
            notice.setAttribute('role', 'status');
            notice.textContent = 'Solde de tokens insuffisant pour exécuter cette mission.';
            bar.appendChild(notice);

            bar.appendChild(this._mutBtn('primary', this.constructor.ICON_WALLET, 'Acheter des tokens', null, '/admin/tokens/buy'));
            bar.appendChild(this._mutBtn('ghost', this.constructor.ICON_X, 'Abandonner', () => bar.remove()));
        } else {
            const exec = this._mutBtn('primary', this.constructor.ICON_CHECK, 'Valider et exécuter');
            const cancel = this._mutBtn('ghost', this.constructor.ICON_X, 'Annuler', () => bar.remove());

            exec.addEventListener('click', async () => {
                if (action.requiresPassword === true) {
                    // Suppression : confirmation renforcée par mot de passe (modale).
                    bar.remove();
                    this.openMutationConfirm(action);
                    return;
                }
                // Écriture pure : exécution IMMÉDIATE, sans boîte de dialogue.
                // État « en cours » + récupération si échec (on ne perd pas le bouton).
                const label = exec.querySelector('.aic-mut-label');
                const prev = label ? label.textContent : '';
                exec.disabled = true;
                cancel.disabled = true;
                if (label) label.textContent = 'Exécution…';
                const ok = await this.executeMutationPlan(action.idMessage, null, false);
                if (ok) {
                    bar.remove();
                } else {
                    exec.disabled = false;
                    cancel.disabled = false;
                    if (label) label.textContent = prev;
                }
            });

            bar.appendChild(exec);
            bar.appendChild(cancel);
        }

        this.messagesTarget.appendChild(bar);
        this.scrollToBottom();
    }

    /** Pastille budget lisible « Libellé : valeur » (variante ok/danger sur la valeur). */
    _budgetChip(label, value, variant = '') {
        const chip = document.createElement('span');
        chip.className = 'aic-mut-chip' + (variant ? ` aic-mut-chip--${variant}` : '');
        const l = document.createElement('span');
        l.textContent = `${label} :`;
        const v = document.createElement('b');
        v.textContent = value;
        chip.append(l, v);
        return chip;
    }

    /**
     * Bouton d'action conforme à la charte (cobalt/ghost) : icône signifiante +
     * libellé explicite, zone cliquable suffisante, focus visible (CSS). `href`
     * => rendu <a> (lien d'achat), sinon <button>.
     */
    _mutBtn(variant, iconSvg, label, onClick = null, href = null) {
        const el = document.createElement(href ? 'a' : 'button');
        el.className = `aic-mut-btn aic-mut-btn--${variant}`;
        if (href) {
            el.href = href;
            el.target = '_blank';
            el.rel = 'noopener';
        } else {
            el.type = 'button';
        }
        el.innerHTML = iconSvg; // constante statique (aucun contenu utilisateur)
        const span = document.createElement('span');
        span.className = 'aic-mut-label';
        span.textContent = label;
        el.appendChild(span);
        if (onClick) el.addEventListener('click', onClick);
        return el;
    }

    /**
     * Ouvre la modale de confirmation générique pour exécuter le plan. Une
     * suppression déclenche l'alerte « irréversible » ET la confirmation
     * renforcée par mot de passe (requirePassword). Les impacts de cascade
     * sont listés. La confirmation renvoie l'événement ket:mutation.execute,
     * capté par ce même contrôleur (executeFromEvent).
     */
    openMutationConfirm(action) {
        const requirePassword = action.requiresPassword === true;
        const impacts = Array.isArray(action.impacts) ? action.impacts : [];
        const body = requirePassword
            ? 'Cette mission comporte une SUPPRESSION définitive. Vérifiez le plan ci-dessus, puis confirmez avec votre mot de passe.'
            : 'Vérifiez le plan ci-dessus, puis confirmez pour que j’exécute les opérations.';

        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            bubbles: true,
            detail: {
                title: requirePassword ? 'Confirmer la suppression' : 'Exécuter la mission',
                body,
                itemDescriptions: impacts,
                showIrreversible: requirePassword,
                requirePassword,
                headerClass: requirePassword ? 'bg-danger text-white' : 'bg-primary text-white',
                confirmClass: requirePassword ? 'btn btn-danger' : 'btn btn-primary',
                onConfirm: {
                    type: 'ket:mutation.execute',
                    payload: { idMessage: action.idMessage },
                },
            },
        }));
    }

    /**
     * Capté depuis la modale de confirmation (via le bus cerveau:event) : lance
     * l'exécution déterministe côté serveur puis rejoue le journal d'étapes.
     */
    executeFromEvent(event) {
        const detail = event.detail;
        if (!detail || detail.type !== 'ket:mutation.execute') return;
        const payload = detail.payload || {};
        // Déclenché par la modale de confirmation (suppression + mot de passe).
        this.executeMutationPlan(payload.idMessage, payload.password, true);
    }

    /**
     * Appelle l'endpoint d'exécution, gère 402/403/422 et rejoue le journal.
     * `viaModal` = true quand l'appel vient de la modale de confirmation
     * (suppression) : les erreurs y sont affichées ; false pour une écriture
     * pure exécutée directement (erreurs affichées en bulle du chat).
     */
    async executeMutationPlan(idMessage, password, viaModal = true) {
        const id = parseInt(idMessage, 10);
        if (!Number.isInteger(id) || id <= 0) return false;
        const url = `/admin/assistant-ia/api/mutation/${this.idEntrepriseValue}/${this.idConversationValue}/${id}/execute`;

        // Feedback « coulisses » : barre de progression globale pendant l'exécution.
        document.dispatchEvent(new CustomEvent('app:loading.start', { bubbles: true }));
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: password || '' }),
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success) {
                if (viaModal) document.dispatchEvent(new CustomEvent('ui:confirmation.close', { bubbles: true }));
                await this.renderMutationJournal(data.journal || []);
                // Rafraîchit les listes éventuellement affichées (données modifiées).
                document.dispatchEvent(new CustomEvent('app:workspace.data-changed', { bubbles: true }));
                return true;
            }

            // Échecs (mot de passe, solde, validation) : message dans la modale
            // si elle est ouverte, sinon en bulle système du chat.
            let message = data.message || "L'exécution a échoué.";
            if (response.status === 402) {
                message = `${data.message || 'Solde insuffisant.'} Rechargez votre solde puis réessayez.`;
            }
            if (viaModal) {
                document.dispatchEvent(new CustomEvent('ui:confirmation.error', {
                    bubbles: true,
                    detail: { error: message },
                }));
            } else {
                this.appendNotice(response.status === 402 ? 'warning' : 'error', message);
            }
            return false;
        } catch (error) {
            console.error('Ket - exécution du plan échouée :', error);
            const msg = "L'exécution a échoué. Vérifiez votre connexion puis réessayez.";
            if (viaModal) {
                document.dispatchEvent(new CustomEvent('ui:confirmation.error', { bubbles: true, detail: { error: msg } }));
            } else {
                this.appendNotice('error', msg);
            }
            return false;
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop', { bubbles: true }));
        }
    }

    /**
     * Rejoue le journal d'exécution ÉTAPE PAR ÉTAPE dans une bulle assistant :
     * rappel du plan puis, séquentiellement, chaque opération cochée (feedback
     * « coulisses » demandé). Réutilise le rendu Markdown sanitisé + pastilles.
     */
    async renderMutationJournal(journal) {
        const bubble = this.appendMessage('assistant', '');
        const content = bubble.querySelector('.aic-msg-text');
        bubble.setAttribute('aria-hidden', 'true');

        const verbe = { create: 'Création', edit: 'Modification', delete: 'Suppression' };
        let lignes = ['**Exécution de la mission**', ''];
        content.innerHTML = renderAssistantMarkdown(lignes.join('\n'));

        for (const step of journal) {
            await new Promise((resolve) => setTimeout(resolve, 420));
            if (step.statut === 'echec') {
                lignes.push(`- [Échec](#danger) ${step.message || 'une étape a échoué — rien n’a été conservé.'}`);
            } else {
                const label = verbe[step.op] || 'Opération';
                const cible = step.cible ? ` « ${step.cible} »` : '';
                lignes.push(`- [Fait](#success) ${label} — ${step.libelle || step.entite}${cible}`);
            }
            content.innerHTML = renderAssistantMarkdown(lignes.join('\n'));
            this.scrollToBottom();
        }

        const fait = journal.filter((s) => s.statut === 'ok').length;
        lignes.push('', `[Terminé](#success) ${fait} opération${fait > 1 ? 's' : ''} exécutée${fait > 1 ? 's' : ''} avec succès.`);
        content.innerHTML = renderAssistantMarkdown(lignes.join('\n'));
        bubble.removeAttribute('aria-hidden');
        this.scrollToBottom();
    }

    /**
     * Ouvre le formulaire « Signaler un paiement de prime » d'une tranche : même
     * événement que l'action du menu contextuel des tranches — le cerveau récupère
     * le contexte (endpoint qui RE-VALIDE les droits, fail-closed) et ouvre le
     * dialogue de création PaiementPrime PRÉREMPLI (solde de prime restant, date du
     * jour), rattaché à la tranche. L'utilisateur relit et enregistre lui-même.
     */
    openSignalerPaiementPrimeAction(action) {
        const id = parseInt(action.trancheId, 10);
        if (!Number.isInteger(id) || id <= 0) return;
        document.dispatchEvent(new CustomEvent('cerveau:event', {
            bubbles: true,
            detail: {
                type:      'ui:tranche.signaler-paiement-prime',
                source:    'assistant-chat',
                payload:   { url: `/admin/tranche/api/get-paiement-prime-context/${id}` },
                timestamp: Date.now(),
            },
        }));
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
        // La puce survolée peut venir d'être retirée du DOM : le navigateur
        // n'émet alors aucun mouseout — sans masquage explicite, l'infobulle
        // resterait affichée et suivrait le curseur indéfiniment.
        this._ctxTipHide();
    }

    /** État courant du contexte, lu depuis les puces rendues serveur. */
    contexteObjets() {
        if (!this.hasContextBarTarget) return [];
        return [...this.contextBarTarget.querySelectorAll('.aic-chip')].map((chip) => ({
            type: chip.dataset.entityType,
            id: parseInt(chip.dataset.entityId, 10),
        })).filter((o) => o.type && Number.isInteger(o.id));
    }

    /**
     * Instantané complet (type, id, nom, libellé de type) du contexte courant,
     * lu depuis les puces — même contenu que le cliché persisté côté serveur :
     * la bulle optimiste porte immédiatement la même agrafe que le rendu final.
     */
    contexteInstantane() {
        if (!this.hasContextBarTarget) return [];
        return [...this.contextBarTarget.querySelectorAll('.aic-chip')].map((chip) => ({
            type: chip.dataset.entityType,
            id: parseInt(chip.dataset.entityId, 10),
            nom: chip.dataset.ctxLabel || '',
            typeLabel: chip.dataset.ctxTypeLabel || chip.dataset.entityType,
        })).filter((o) => o.type && Number.isInteger(o.id));
    }

    // ── Infobulle sombre des puces (pattern data-piste-tip du tableau de bord) ──

    _ctxTipOver(event) {
        if (this._ctxTipPinned) return; // infobulle épinglée par clic : ne pas écraser
        const cible = event.target.closest ? event.target.closest('[data-ctx-tip], [data-msg-contextes]') : null;
        if (!cible || event.target.closest('.aic-chip-remove')) return;
        const tip = this._ctxTipCreate();
        if (cible.dataset.msgContextes !== undefined) {
            this._ctxTipBuildMessage(tip, cible);
        } else {
            this._ctxTipBuild(tip, cible);
        }
        tip.style.display = 'block';
        this._ctxTipActive = true;
    }

    _ctxTipOut(event) {
        if (this._ctxTipPinned) return;
        const cible = event.target.closest ? event.target.closest('[data-ctx-tip], [data-msg-contextes]') : null;
        if (cible && !cible.contains(event.relatedTarget)) {
            this._ctxTipHide();
        }
    }

    /**
     * Clic sur l'agrafe d'un message : épingle l'infobulle (elle cesse de suivre
     * le curseur — utile aussi au tactile) ; second clic = masquage.
     */
    toggleMsgContextes(event) {
        const bouton = event.currentTarget;
        if (this._ctxTipPinned) {
            this._ctxTipHide();
            return;
        }
        const tip = this._ctxTipCreate();
        this._ctxTipBuildMessage(tip, bouton);
        const rect = bouton.getBoundingClientRect();
        tip.style.display = 'block';
        tip.style.left = `${Math.max(8, rect.left - 220)}px`;
        tip.style.top = `${rect.bottom + 6}px`;
        this._ctxTipActive = true;
        this._ctxTipPinned = true;
    }

    /** Masque l'infobulle et coupe le suivi du curseur. */
    _ctxTipHide() {
        if (this._ctxTip) this._ctxTip.style.display = 'none';
        this._ctxTipActive = false;
        this._ctxTipPinned = false;
    }

    /** Suit le curseur : au-dessus à gauche, repli sous/à droite près des bords. */
    _ctxTipMove(event) {
        if (!this._ctxTipActive || !this._ctxTip || this._ctxTipPinned) return;
        const tip = this._ctxTip;
        const offset = 10;
        let left = event.clientX - tip.offsetWidth - offset;
        let top = event.clientY - tip.offsetHeight - offset;
        if (left < 8) left = event.clientX + offset;
        if (top < 8) top = event.clientY + offset;
        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;
    }

    _ctxTipCreate() {
        if (this._ctxTip) return this._ctxTip;
        const tip = document.createElement('div');
        tip.className = 'jsb-ctx-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
        this._ctxTip = tip;
        return tip;
    }

    /**
     * Contenu : la fiche EXACTE capturée par l'assistant (posée en data-ctx-fiche
     * par le partial serveur), rendue en tableau sombre — construction DOM via
     * textContent (échappement garanti). Sans fiche : objet supprimé/hors périmètre.
     */
    _ctxTipBuild(tip, chip) {
        tip.textContent = '';
        const table = document.createElement('table');

        const addRow = (cells, classes = []) => {
            const tr = document.createElement('tr');
            cells.forEach(({ text, colspan, className }) => {
                const td = document.createElement('td');
                td.textContent = text;
                if (colspan) td.setAttribute('colspan', String(colspan));
                if (className) td.className = className;
                tr.appendChild(td);
            });
            table.appendChild(tr);
        };

        addRow([{ text: chip.dataset.ctxTypeLabel || chip.dataset.entityType || 'Objet', colspan: 2, className: 'tip-section' }]);
        addRow([{ text: 'Nom' }, { text: chip.dataset.ctxLabel || '—' }]);

        let fiche = null;
        try {
            fiche = JSON.parse(chip.dataset.ctxFiche || 'null');
        } catch { /* fiche illisible : traitée comme absente */ }

        if (fiche && typeof fiche === 'object') {
            addRow([{ text: 'Fiche capturée par l\'assistant', colspan: 2, className: 'tip-section' }]);
            for (const [cle, valeur] of Object.entries(fiche)) {
                addRow([{ text: cle }, { text: this._ctxTipFormat(valeur) }]);
            }
        } else {
            addRow([{ text: 'Détails indisponibles (objet supprimé ou hors de votre périmètre).', colspan: 2, className: 'tip-libelle' }]);
        }

        tip.appendChild(table);
    }

    /**
     * Contenu de l'agrafe d'un message : l'instantané IMMUABLE des objets qui
     * étaient en contexte à l'envoi (posé en data-msg-contextes par le partial
     * serveur ou la bulle optimiste) — construction DOM via textContent.
     */
    _ctxTipBuildMessage(tip, bouton) {
        tip.textContent = '';
        const table = document.createElement('table');

        const addRow = (cells) => {
            const tr = document.createElement('tr');
            cells.forEach(({ text, colspan, className }) => {
                const td = document.createElement('td');
                td.textContent = text;
                if (colspan) td.setAttribute('colspan', String(colspan));
                if (className) td.className = className;
                tr.appendChild(td);
            });
            table.appendChild(tr);
        };

        let objets = [];
        try {
            objets = JSON.parse(bouton.dataset.msgContextes || '[]');
        } catch { /* instantané illisible : liste vide */ }

        addRow([{ text: 'Objets en contexte à l\'envoi de ce message', colspan: 2, className: 'tip-section' }]);
        if (!Array.isArray(objets) || objets.length === 0) {
            addRow([{ text: 'Aucun objet.', colspan: 2, className: 'tip-libelle' }]);
        } else {
            objets.forEach((o) => {
                addRow([
                    { text: `${o.typeLabel || o.type || 'Objet'} #${o.id ?? '?'}` },
                    { text: String(o.nom || '—') },
                ]);
            });
        }

        tip.appendChild(table);
    }

    /** Valeur de fiche lisible : booléens en clair, structures résumées, textes bornés. */
    _ctxTipFormat(valeur) {
        if (valeur === null || valeur === undefined || valeur === '') return '—';
        if (typeof valeur === 'boolean') return valeur ? 'Oui' : 'Non';
        if (Array.isArray(valeur)) return `${valeur.length} élément(s)`;
        if (typeof valeur === 'object') {
            return String(valeur.nom ?? valeur.libelle ?? valeur.titre ?? valeur.id ?? '—');
        }
        const texte = String(valeur);
        return texte.length > 120 ? `${texte.slice(0, 120)}…` : texte;
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
            // Nombres écrits comme partout ailleurs : notation de la langue active.
            message += ` (${formatNombre(data.available)}/${formatNombre(data.required)})`;
        }
        message += '. Rechargez votre solde';
        if (data.nextRenewalAt) {
            // Même horloge de référence que le widget de solde.
            const date = formatInstant(data.nextRenewalAt);
            if (date) message += ` ou attendez le renouvellement du ${date}`;
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
     * Le texte accumulé est reparsé en Markdown sanitisé à chaque mot : un
     * Markdown partiel (ex. « **gras » non fermé) reste affiché tel quel
     * jusqu'à ce que sa fermeture arrive dans un mot suivant — pas de crash.
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
        let accumule = '';
        for (const mot of mots) {
            if (!this.element.isConnected) {
                break;
            }
            accumule += mot;
            content.innerHTML = renderAssistantMarkdown(accumule);
            this.scrollToBottom();
            await new Promise((resolve) => setTimeout(resolve, delai));
        }
        content.innerHTML = renderAssistantMarkdown(texte); // garantit le texte intégral quoi qu'il arrive
        bubble.removeAttribute('aria-hidden');
    }

    /**
     * Enrichit au chargement les bulles assistant déjà rendues par Twig
     * (historique de la conversation) : le Markdown source est lu depuis
     * l'attribut `data-md-source` (jamais depuis le textContent déjà rendu,
     * pour éviter tout risque de double-échappement).
     */
    renderHistoricalMarkdown() {
        if (!this.hasMessagesTarget) return;
        this.messagesTarget.querySelectorAll('.aic-msg--assistant .aic-msg-text[data-md-source]').forEach((el) => {
            el.innerHTML = renderAssistantMarkdown(el.dataset.mdSource);
        });
    }

    /**
     * Ajoute une bulle de message au fil (structure identique à celle rendue
     * côté serveur dans _assistant_ia_chat.html.twig).
     */
    appendMessage(role, texte, refus = false, contexteObjets = null) {
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

        // Agrafe : instantané des objets du contexte à l'envoi (bulle utilisateur,
        // structure identique au rendu serveur — l'infobulle/le clic marchent d'office
        // via la délégation et l'action Stimulus).
        if (role === 'user' && Array.isArray(contexteObjets) && contexteObjets.length > 0) {
            const attache = document.createElement('button');
            attache.type = 'button';
            attache.className = 'aic-msg-attach';
            attache.dataset.msgContextes = JSON.stringify(contexteObjets);
            attache.setAttribute('data-action', 'click->assistant-chat#toggleMsgContextes');
            attache.setAttribute('aria-label', `${contexteObjets.length} objet${contexteObjets.length > 1 ? 's' : ''} en contexte à l'envoi de ce message`);
            // SVG statique (trombone lucide) : constante sûre, aucun contenu utilisateur.
            attache.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>';
            const compteur = document.createElement('span');
            compteur.textContent = String(contexteObjets.length);
            attache.appendChild(compteur);
            body.appendChild(attache);
        }

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
