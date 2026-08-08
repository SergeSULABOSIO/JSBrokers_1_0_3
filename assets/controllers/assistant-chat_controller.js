import { Controller } from '@hotwired/stimulus';
import { renderAssistantMarkdown } from './assistant-markdown-render.js';
import { monterGraphiquesAssistant, rethemeGraphiquesAssistant } from './assistant-chart-render.js';
import { masquerBlocsChart } from './assistant-chart-spec.js';
import {
    THEME_CLAIR,
    THEME_SOMBRE,
    EVENEMENT_THEME,
    normaliserTheme,
    resoudreTheme,
    themeOppose,
} from './assistant-theme.js';
import { positionnerMenu, indexApresTouche } from './menu-flottant.js';
import {
    FORMAT_IMAGE,
    CLE_EXPORT,
    urlExportMessage,
    urlDestinatairesMessage,
    nomFichierImage,
} from './assistant-message-menu.js';
import { formatInstant } from '../datetime-format.js';
import { formatNombre } from '../number-format.js';
import { documentLocale } from '../locale.js';

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
    static targets = [
        'messages', 'input', 'send', 'typing', 'typingLabel', 'count', 'contextBar', 'mic',
        'fichierBar', 'fichierInput',
        // Actions de bulle : menu unique ancré, gabarits clonés, bandeau de citation.
        'menuBulle', 'tplKebab', 'tplCitation', 'citationBar', 'citationQui', 'citationExtrait',
    ];

    /** Seuil d'affichage du compteur de caractères restants (proche de maxlength). */
    static COUNT_THRESHOLD = 400;

    /**
     * Lignes de fiche affichées dans l'infobulle d'une puce de contexte. Borne
     * d'AFFICHAGE seulement : la fiche transmise à l'assistante n'est jamais
     * tronquée. L'infobulle flotte au <body>, ne défile pas et suit le curseur —
     * au-delà d'une vingtaine de lignes elle déborderait du viewport.
     */
    static CTX_TIP_MAX_LIGNES = 20;

    /** Message autoritaire affiché quand le modèle décrit un plan sans l'avoir préparé (aucun bouton ne viendra). */
    static MUTATION_ABSENT_MESSAGE = "Aucun plan n'est réellement en attente de validation : l'opération n'a pas été préparée, donc aucun bouton « Valider et exécuter » n'apparaîtra. Redemandez l'action (par ex. « supprime le revenu de cette cotation ») pour que le plan et son bouton s'affichent.";

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
        fichierUrl: String,
        fichierLimits: Object,
        themeUrl: String,
        idEntreprise: Number,
        idInvite: Number,
        idConversation: Number,
        assistantNom: String,
    };

    connect() {
        // EN PREMIER, avant tout rendu : renderHistoricalMarkdown() monte les
        // graphiques de l'historique, et Chart.js fige ses couleurs à la
        // construction. Résoudre le thème après, ce serait peindre l'historique
        // en clair. Stimulus connecte via MutationObserver (microtâche), donc
        // cette écriture précède le premier paint : pas de flash clair.
        this.setupTheme();

        this.sending = false;
        this.renderHistoricalMarkdown();
        // Équipe l'historique du bouton ⋮ (les bulles ajoutées en direct le
        // reçoivent dans appendMessage) et arme le clic droit sur le fil.
        this.equiperBulles();
        this.setupMenuBulle();
        // Reconstruit la barre de décision des plans EN ATTENTE après un rechargement
        // (F5) : le live la crée via executeActions, l'historique la restaure ici.
        this.restoreMutationReviews();
        // Rapports de programme portant des écarts : le bouton de correction est
        // la seule issue proposée, il doit survivre au rechargement.
        this.restoreProgrammeCorrections();
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
        // Puces fichiers : même infobulle sombre, déléguée à la barre de fichiers
        // (survit aux re-rendus innerHTML des endpoints d'attache/retrait).
        if (this.hasFichierBarTarget) {
            this.fichierBarTarget.addEventListener('mouseover', this._onCtxTipOver);
            this.fichierBarTarget.addEventListener('mouseout', this._onCtxTipOut);
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

        // Dictée vocale (parler plutôt qu'écrire) : reconnaissance vocale native
        // du navigateur, transcrite dans la zone de saisie puis envoyée par le
        // circuit send() habituel (aucun token supplémentaire, aucun backend).
        this.setupDictation();

        // Infobulle sombre du bouton micro (pattern JS Brokers .jsb-ctx-tip) :
        // ANCRÉE au-dessus du bouton (le composer est au coin inférieur droit de
        // l'écran) plutôt que suiveuse de curseur — réutilise l'infobulle du chat.
        if (this.hasMicTarget) {
            this._onMicTipOver = this._micTipShow.bind(this);
            this._onMicTipOut = () => this._ctxTipHide();
            this.micTarget.addEventListener('mouseenter', this._onMicTipOver);
            this.micTarget.addEventListener('mouseleave', this._onMicTipOut);
            this.micTarget.addEventListener('focus', this._onMicTipOver);
            this.micTarget.addEventListener('blur', this._onMicTipOut);
        }
    }

    /**
     * Prépare la reconnaissance vocale native (API Web Speech, Chrome/Edge). La
     * langue suit l'interface (source unique : documentLocale()). Si le
     * navigateur ne la supporte pas, le bouton micro est masqué (amélioration
     * progressive : l'UI reste identique à l'existant).
     */
    setupDictation() {
        this.listening = false;
        this._dictationBase = '';
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) {
            if (this.hasMicTarget) this.micTarget.hidden = true;
            return;
        }

        const recognition = new SpeechRecognition();
        recognition.lang = documentLocale() === 'en' ? 'en-US' : 'fr-FR';
        recognition.interimResults = true;
        recognition.continuous = true;
        recognition.maxAlternatives = 1;

        recognition.onstart = () => {
            this.listening = true;
            if (this.hasMicTarget) {
                this.micTarget.classList.add('aic-mic--recording');
                this.micTarget.setAttribute('aria-pressed', 'true');
            }
        };
        recognition.onresult = (event) => {
            let transcript = '';
            for (let i = 0; i < event.results.length; i++) {
                transcript += event.results[i][0].transcript;
            }
            transcript = transcript.trim();
            const base = this._dictationBase;
            const max = Number(this.inputTarget.getAttribute('maxlength')) || 4000;
            const separateur = base !== '' && transcript !== '' ? ' ' : '';
            this.inputTarget.value = (base + separateur + transcript).slice(0, max);
            this.onInput(); // réutilise autoGrow + état du bouton + compteur
        };
        recognition.onerror = (event) => {
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                this.appendNotice('warning', "Micro indisponible : autorisez l'accès au microphone pour dicter votre message.");
            }
            this.stopDictationUi();
        };
        recognition.onend = () => this.stopDictationUi();

        this.recognition = recognition;
    }

    /** Réinitialise l'état visuel du micro (fin d'écoute ou erreur). */
    stopDictationUi() {
        this.listening = false;
        if (this.hasMicTarget) {
            this.micTarget.classList.remove('aic-mic--recording');
            this.micTarget.setAttribute('aria-pressed', 'false');
        }
        if (this.hasInputTarget) this.inputTarget.focus();
    }

    /**
     * Bascule l'écoute : démarre la dictée (en mémorisant le texte déjà saisi
     * comme socle) ou l'arrête. Le transcript remplit la zone de saisie ;
     * l'utilisateur relit puis envoie — le circuit send() reste inchangé.
     */
    toggleDictation() {
        if (!this.recognition) return;
        if (this.listening) {
            this.recognition.stop();
            return;
        }
        this._dictationBase = this.inputTarget.value.trim();
        try {
            this.recognition.start();
        } catch (error) {
            // start() lève si une reconnaissance est déjà en cours : on ignore.
            console.warn('AssistantChat - dictée déjà active :', error);
        }
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
        // Coupe une éventuelle dictée en cours quand le panneau col-4 est re-rendu.
        if (this.recognition) {
            try { this.recognition.stop(); } catch (error) { /* déjà arrêtée */ }
            this.recognition = null;
        }
        if (this.hasMicTarget && this._onMicTipOver) {
            this.micTarget.removeEventListener('mouseenter', this._onMicTipOver);
            this.micTarget.removeEventListener('mouseleave', this._onMicTipOut);
            this.micTarget.removeEventListener('focus', this._onMicTipOver);
            this.micTarget.removeEventListener('blur', this._onMicTipOut);
        }
        if (this._onThemeChanged) {
            document.removeEventListener(EVENEMENT_THEME, this._onThemeChanged);
        }
        if (this._mqSombre && this._onOsTheme) {
            this._mqSombre.removeEventListener('change', this._onOsTheme);
        }
        this.teardownMenuBulle();
    }

    // ═══ Thème du chat (confort visuel) ═══════════════════════════════════════
    // Le chat est la seule interface de l'application à proposer un mode sombre.
    // La règle de résolution vit dans le module PUR `assistant-theme.js` (testé
    // sous node --test) ; ce contrôleur n'est qu'une coquille : il lit les
    // entrées (attribut serveur, préférence système), applique, diffuse, persiste.

    /**
     * Résout et applique le thème, puis met en place les deux écoutes :
     *  - la bascule diffusée par N'IMPORTE quelle instance de chat ouverte ;
     *  - le changement de thème du système, UNIQUEMENT tant que l'utilisateur
     *    n'a pas tranché (sinon son choix explicite serait écrasé).
     */
    setupTheme() {
        // L'attribut vaut 'light' / 'dark' si l'utilisateur a tranché, 'auto'
        // sinon — normaliserTheme() ramène 'auto' (et tout inconnu) à null.
        const stocke = normaliserTheme(this.element.dataset.aicTheme);
        // Un choix explicite (rendu par le serveur, ou fait dans cette session)
        // gèle le suivi du système : on ne lui reprend pas la main.
        this._choixExplicite = stocke !== null;
        this._theme = resoudreTheme({ stocke, prefereSombre: this._osPrefereSombre() });
        this._appliquerTheme(this._theme, { rethemer: false });

        this._onThemeChanged = (e) => {
            const theme = normaliserTheme(e && e.detail ? e.detail.theme : null);
            if (theme !== null) {
                this._appliquerTheme(theme);
            }
        };
        document.addEventListener(EVENEMENT_THEME, this._onThemeChanged);

        this._mqSombre = typeof window.matchMedia === 'function'
            ? window.matchMedia('(prefers-color-scheme: dark)')
            : null;
        if (this._mqSombre) {
            this._onOsTheme = () => {
                if (!this._choixExplicite) {
                    this._appliquerTheme(this._osPrefereSombre() ? THEME_SOMBRE : THEME_CLAIR);
                }
            };
            this._mqSombre.addEventListener('change', this._onOsTheme);
        }
    }

    /**
     * Bascule. N'applique RIEN elle-même : elle diffuse, et l'écouteur applique
     * — y compris dans cette instance. Un seul chemin de code, donc plusieurs
     * conversations ouvertes restent cohérentes sans traitement particulier.
     */
    toggleTheme() {
        const suivant = themeOppose(this._theme);
        this._choixExplicite = true;
        document.dispatchEvent(new CustomEvent(EVENEMENT_THEME, { detail: { theme: suivant } }));
        this._persisterTheme(suivant);
    }

    /**
     * Pose l'état sur la RACINE et nulle part ailleurs : c'est ce qui fait que
     * les puces de contexte et de fichiers re-rendues par le serveur (remplacement
     * d'innerHTML) restent thémées par simple cascade, sans code dédié.
     * @param {'light'|'dark'} theme
     * @param {{rethemer?: boolean}} options
     */
    _appliquerTheme(theme, { rethemer = true } = {}) {
        this._theme = theme;
        this.element.setAttribute('data-aic-theme', theme);
        this.element.querySelectorAll('.aic-theme').forEach((btn) => {
            btn.setAttribute('aria-pressed', theme === THEME_SOMBRE ? 'true' : 'false');
        });
        // Chart.js ne lit pas les variables CSS : ses couleurs sont figées dans la
        // configuration, il faut donc repeindre les graphiques déjà montés.
        if (rethemer && this.hasMessagesTarget) {
            rethemeGraphiquesAssistant(this.messagesTarget, theme);
        }
    }

    /** Préférence système du poste (false si le navigateur ne la connaît pas). */
    _osPrefereSombre() {
        return typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    /**
     * Mémorise le choix côté serveur (il suit l'utilisateur sur tous ses
     * appareils). Optimiste : l'interface a déjà basculé. Un échec réseau ne
     * mérite pas d'alerte pour un réglage d'affichage — le thème reste appliqué
     * pour la session et sera simplement re-résolu au prochain chargement.
     * @param {'light'|'dark'} theme
     */
    _persisterTheme(theme) {
        if (!this.hasThemeUrlValue) {
            return;
        }
        fetch(this.themeUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ theme }),
        }).catch((error) => {
            console.warn('AssistantChat - préférence de thème non enregistrée :', error);
        });
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

    /** Entrée = envoyer, Maj+Entrée = retour à la ligne, Échap = annuler la citation. */
    keydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.send();
            return;
        }
        // Seule sortie clavier immédiate du mode « réponse à un message ».
        if (event.key === 'Escape' && this._citation) {
            event.preventDefault();
            this.annulerCitation();
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
        const citation = this._citation;
        const userBubble = this.appendMessage(
            'user', contenu, false, this.contexteInstantane(), this.fichiersInstantane(), { citation }
        );
        this.inputTarget.value = '';
        this.onInput();
        this.setTypingLabel('réfléchit…');
        this.typingTarget.hidden = false;
        this.scrollToBottom();

        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ contenu, replyToId: citation ? citation.id : null }),
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
                // La bulle optimiste reçoit enfin son identité : ses actions
                // (répondre, exporter, envoyer) n'existaient pas avant, puisque
                // le message n'était pas encore persisté.
                this.identifierBulle(userBubble, data.user?.id);
                // Envoi accepté : le brouillon de citation a joué son rôle. En
                // 402 ou en erreur, il est au contraire CONSERVÉ, comme le texte
                // restauré dans la zone de saisie.
                this.annulerCitation();
                // La réponse se déploie mot après mot (façon ChatGPT/Claude) ;
                // l'indicateur bascule de « réfléchit… » à « écrit… ».
                this.setTypingLabel('écrit…');
                await this.typeMessage(data.assistant.contenu, data.assistant.refus === true, data.assistant.id);
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
                case 'assistant:message.envoyer-direct':
                    await this.envoyerMessageDirect(action);
                    break;
                case 'signaler-paiement-prime':
                    this.openSignalerPaiementPrimeAction(action);
                    break;
                case 'files-download':
                    this.renderFilesDownload(action);
                    break;
                case 'ket-mutation.review':
                    this.renderMutationReview(action);
                    break;
                case 'ket-mutation.absent':
                    this.renderMutationAbsent();
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
    /**
     * Reconstruit, au chargement, les barres de décision des plans NON exécutés
     * portés par l'historique (attribut data-mutation-review posé côté serveur).
     * Indispensable après un F5 : sans cela, seul le texte du plan resterait.
     */
    restoreMutationReviews() {
        if (!this.hasMessagesTarget) return;
        this.messagesTarget.querySelectorAll('.aic-msg[data-mutation-review]').forEach((el) => {
            let action;
            try {
                action = JSON.parse(el.dataset.mutationReview);
            } catch (e) {
                return;
            }
            el.removeAttribute('data-mutation-review'); // évite tout doublon
            if (action && action.idMessage) {
                this.renderMutationReview(action, el);
            }
        });
    }

    renderMutationReview(action, anchor = null) {
        if (!action || !action.idMessage) return;
        const budget = action.budget || {};
        const cout = budget.coutEstime || 0;
        const solde = budget.soldeDisponible || 0;
        const suffisant = budget.suffisant !== false;

        const bar = document.createElement('div');
        bar.className = 'aic-mutation-actions';
        bar.setAttribute('role', 'group');
        bar.setAttribute('aria-label', 'Décision sur le plan préparé par l’assistant');

        // Budget TOUJOURS affiché (garantie serveur, indépendante de la prose du
        // modèle), en texte simple sur une ligne : coût · solde · reste.
        const budgetLine = document.createElement('p');
        budgetLine.className = 'aic-mutation-budget';
        const majBudget = (coutRetenu) => {
            budgetLine.textContent = `Budget : ${formatNombre(coutRetenu)} tokens · solde ${formatNombre(solde)} · reste ${formatNombre(Math.max(0, solde - coutRetenu))}`;
        };
        // PROGRAMME : où en est-on dans la série ? Posé en tête de barre, avant
        // même l'aperçu — une validation isolée et la 2e de 3 ne se décident pas
        // dans le même état d'esprit.
        const bandeau = this._renderProgrammeBandeau(action.programme);
        if (bandeau) bar.appendChild(bandeau);

        // Ce que le plan fera VRAIMENT, et ce qu'il ne couvre pas — rendu à partir
        // des données du serveur, jamais de la prose du modèle. C'est ce que
        // l'utilisateur valide ; si le texte de Ket annonce autre chose, l'écart
        // saute aux yeux AVANT l'exécution.
        const apercu = this._renderMutationApercu(action);
        if (apercu) bar.appendChild(apercu);

        // ÉTENDUE : l'utilisateur décoche les étapes qu'il ne veut pas enregistrer
        // maintenant. Le budget se réajuste ; les clés retenues partent avec
        // l'exécution et c'est le SERVEUR qui filtre le plan (jamais le client).
        const etapes = this._renderMutationSteps(action, majBudget, cout);
        if (etapes) bar.appendChild(etapes.el);
        const clesRetenues = () => (etapes ? etapes.retenues() : null);

        majBudget(cout);
        bar.appendChild(budgetLine);

        if (!suffisant) {
            const notice = document.createElement('p');
            notice.className = 'aic-notice aic-notice--warning';
            notice.setAttribute('role', 'status');
            notice.textContent = 'Solde de tokens insuffisant pour exécuter cette mission.';
            bar.appendChild(notice);

            bar.appendChild(this._mutBtn('primary', this.constructor.ICON_WALLET, 'Acheter des tokens', null, '/admin/tokens/buy'));
            bar.appendChild(this._mutBtn('ghost', this.constructor.ICON_X, 'Abandonner', () => this.cancelMutationPlan(action.idMessage, bar)));
        } else {
            const exec = this._mutBtn('primary', this.constructor.ICON_CHECK, 'Valider et exécuter');
            const cancel = this._mutBtn('ghost', this.constructor.ICON_X, 'Annuler', () => this.cancelMutationPlan(action.idMessage, bar));

            exec.addEventListener('click', async () => {
                if (action.requiresPassword === true) {
                    // Suppression : confirmation renforcée par mot de passe (modale).
                    // La barre est NEUTRALISÉE, pas retirée : la retirer ici la faisait
                    // disparaître pour de bon dès que l'utilisateur fermait la modale
                    // (Échap / Annuler) — le plan restait en attente côté serveur, sans
                    // plus aucune commande dans le fil. Renoncer à une suppression ne
                    // doit jamais coûter le plan.
                    exec.disabled = true;
                    cancel.disabled = true;
                    const rearmer = () => {
                        exec.disabled = false;
                        cancel.disabled = false;
                    };
                    document.addEventListener('ui:confirmation.close', rearmer, { once: true });
                    this.openMutationConfirm({ ...action, etapesRetenues: clesRetenues(), bar });
                    return;
                }
                // Écriture pure : exécution IMMÉDIATE, sans boîte de dialogue.
                // État « en cours » + récupération si échec (on ne perd pas le bouton).
                const label = exec.querySelector('.aic-mut-label');
                const prev = label ? label.textContent : '';
                exec.disabled = true;
                cancel.disabled = true;
                if (label) label.textContent = 'Exécution…';
                const status = await this.executeMutationPlan(action.idMessage, null, false, clesRetenues());
                if (status === 'success') {
                    // Décision mémorisée : la barre laisse place à un feedback permanent.
                    this._replaceBar(bar, this._planStatusNote('done', 'Plan exécuté — les données ont été enregistrées.'));
                } else if (status === 'missing') {
                    // Ket a demandé les informations manquantes : on retire la barre
                    // périmée (l'utilisateur va préciser, un nouveau plan suivra).
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

        // « Annuler » ne refuse QUE cette étape — la série continue et l'omission
        // sera dite dans le rapport. Arrêter toute la mission est une autre
        // décision : elle a donc son propre bouton, jamais un effet de bord du
        // premier.
        if (action.programme && action.programme.idProgramme) {
            bar.appendChild(this._mutBtn('ghost', this.constructor.ICON_X, 'Interrompre le programme', () => {
                this.interrompreProgramme(action.programme.idProgramme, bar);
            }));
        }

        // Live : la barre suit le dernier message (append). Restauration après F5 :
        // on l'insère juste après le message qui porte le plan.
        if (anchor && anchor.parentNode) {
            anchor.parentNode.insertBefore(bar, anchor.nextSibling);
        } else {
            this.messagesTarget.appendChild(bar);
        }
        this.scrollToBottom();
    }

    /**
     * APERÇU AUTORITAIRE du plan : ce qui sera réellement écrit, et ce que le plan
     * ne couvre pas. Construit à partir des données du serveur (dérivées des
     * opérations elles-mêmes), jamais du texte rédigé par le modèle — les deux
     * peuvent diverger, et c'est précisément l'écart qu'il faut rendre visible
     * AVANT la validation. Retourne null si le serveur n'a pas fourni d'aperçu
     * (plans antérieurs restaurés après un F5).
     */
    _renderMutationApercu(action) {
        const apercu = Array.isArray(action.apercu) ? action.apercu : [];
        const omissions = Array.isArray(action.omissions) ? action.omissions : [];
        if (apercu.length === 0) return null;

        const verbe = { create: 'Création', edit: 'Modification', delete: 'Suppression' };
        const bloc = document.createElement('div');
        bloc.className = 'aic-mut-apercu';

        const titre = document.createElement('p');
        titre.className = 'aic-mut-apercu-titre';
        titre.textContent = 'Ce plan va enregistrer :';
        bloc.appendChild(titre);

        const liste = document.createElement('ul');
        liste.className = 'aic-mut-apercu-liste';
        apercu.forEach((op) => {
            const item = document.createElement('li');
            const cible = op.cible ? ` « ${op.cible} »` : '';
            // UNE SUPPRESSION NE DOIT PAS SE LIRE COMME UNE CRÉATION. Elle portait le
            // même gris que les autres lignes : la seule différence était le mot
            // « Suppression » au fil du texte, et l'utilisateur validait sans l'avoir vu.
            if (op.op === 'delete') item.className = 'aic-mut-apercu-suppression';
            item.textContent = `${verbe[op.op] || 'Opération'} — ${op.libelle || ''}${cible}`;

            const details = Array.isArray(op.details) ? op.details : [];
            if (details.length > 0) {
                const sous = document.createElement('ul');
                details.forEach((detail) => {
                    const parts = [];
                    if (detail.creations) parts.push(`${detail.creations} à créer`);
                    if (detail.modifications) parts.push(`${detail.modifications} à modifier`);
                    if (detail.suppressions) parts.push(`${detail.suppressions} à supprimer`);
                    if (parts.length === 0) return;
                    const ligne = document.createElement('li');
                    ligne.textContent = `${detail.libelle} : ${parts.join(', ')}`;
                    sous.appendChild(ligne);
                });
                if (sous.children.length > 0) item.appendChild(sous);
            }
            liste.appendChild(item);
        });
        bloc.appendChild(liste);

        // Ce que le plan ne fera PAS : la garantie qu'une étape attendue mais
        // absente des opérations ne passe pas inaperçue.
        omissions.forEach((omission) => {
            const elements = Array.isArray(omission.elements) ? omission.elements : [];
            if (elements.length === 0) return;
            const note = document.createElement('p');
            note.className = 'aic-mut-apercu-omission';
            note.textContent = `Rien ne sera enregistré pour : ${elements.join(', ')}. Dites-le à l’assistant si vous vouliez les inclure.`;
            bloc.appendChild(note);
        });

        const alerte = this._renderSuppressionAlerte(action, apercu);
        if (alerte) bloc.appendChild(alerte);

        return bloc;
    }

    /**
     * AVERTISSEMENT DE SUPPRESSION, AVANT TOUT CLIC.
     *
     * L'utilisateur n'était prévenu qu'APRÈS avoir cliqué « Valider et exécuter » :
     * la portée réelle (ce qui disparaît en cascade), le caractère irréversible et
     * l'exigence du mot de passe n'apparaissaient que dans la modale. Il validait donc
     * sans savoir — et un plan supprimant une opportunité de renouvellement efface
     * aussi ses propositions, leurs échéanciers et leurs paiements.
     *
     * Cet encart énonce les trois faits AVANT la décision, à partir des données du
     * SERVEUR (op.op === 'delete' et action.impacts), jamais de la prose du modèle.
     * La modale reste en second rideau : on informe deux fois plutôt qu'une.
     */
    _renderSuppressionAlerte(action, apercu) {
        const suppressions = apercu.filter((op) => op.op === 'delete');
        const impacts = Array.isArray(action.impacts) ? action.impacts.filter(Boolean) : [];
        if (suppressions.length === 0 && action.requiresPassword !== true) return null;

        const encart = document.createElement('div');
        encart.className = 'aic-mut-suppression-alerte';
        encart.setAttribute('role', 'alert');

        const titre = document.createElement('p');
        titre.className = 'aic-mut-suppression-titre';
        const cibles = suppressions
            .map((op) => (op.cible ? `${op.libelle || ''} « ${op.cible} »` : op.libelle || ''))
            .filter(Boolean);
        titre.textContent = cibles.length > 0
            ? `Attention — ce plan SUPPRIME définitivement : ${cibles.join(', ')}.`
            : 'Attention — ce plan comporte une suppression définitive.';
        encart.appendChild(titre);

        // La portée RÉELLE : ce que la cascade emporte avec la cible.
        if (impacts.length > 0) {
            const intro = document.createElement('p');
            intro.className = 'aic-mut-suppression-portee';
            intro.textContent = 'Seront effacés avec :';
            encart.appendChild(intro);

            const liste = document.createElement('ul');
            liste.className = 'aic-mut-suppression-liste';
            impacts.forEach((impact) => {
                const li = document.createElement('li');
                li.textContent = String(impact);
                liste.appendChild(li);
            });
            encart.appendChild(liste);
        }

        const pied = document.createElement('p');
        pied.className = 'aic-mut-suppression-pied';
        pied.textContent = action.requiresPassword === true
            ? 'Cette action est IRRÉVERSIBLE. Votre mot de passe vous sera demandé pour confirmer.'
            : 'Cette action est IRRÉVERSIBLE.';
        encart.appendChild(pied);

        return encart;
    }

    /**
     * Sélecteur d'ÉTENDUE : une case à cocher par étape du parcours, alimentée par
     * la ventilation du budget renvoyée par le serveur (budget.parEtape). L'étape
     * socle est cochée et verrouillée — sans elle, le reste n'aurait plus d'objet.
     * Retourne { el, retenues() } ou null si le plan n'a pas d'étape facultative.
     */
    _renderMutationSteps(action, majBudget, coutTotal) {
        const etapes = (action.budget && Array.isArray(action.budget.parEtape)) ? action.budget.parEtape : [];
        if (etapes.length < 2 || !etapes.some((e) => e.obligatoire === false)) return null;

        const fieldset = document.createElement('fieldset');
        fieldset.className = 'aic-mut-steps';

        const legend = document.createElement('legend');
        legend.className = 'aic-mut-steps-legend';
        legend.textContent = 'Étendue — décochez ce que vous préférez ne pas enregistrer maintenant';
        fieldset.appendChild(legend);

        const cases = [];
        etapes.forEach((etape) => {
            const label = document.createElement('label');
            label.className = 'aic-mut-step';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.checked = true;
            input.value = etape.cle || '';
            input.disabled = etape.obligatoire !== false;

            const titre = document.createElement('span');
            titre.className = 'aic-mut-step-label';
            titre.textContent = etape.libelle || etape.cle || '';

            const meta = document.createElement('span');
            meta.className = 'aic-mut-step-meta';
            const nb = etape.enregistrements || 0;
            meta.textContent = `${nb} enr. · ${formatNombre(etape.cout || 0)} tokens${etape.obligatoire !== false ? ' · requise' : ''}`;

            label.append(input, titre, meta);
            fieldset.appendChild(label);
            cases.push({ input, etape });
        });

        const retenues = () => cases.filter((c) => c.input.checked).map((c) => c.input.value);
        cases.forEach(({ input }) => input.addEventListener('change', () => {
            // Aucune étape facultative cochée : le coût retombe sur les seules requises.
            const total = cases.reduce((somme, c) => (c.input.checked ? somme + (c.etape.cout || 0) : somme), 0);
            majBudget(total || coutTotal);
        }));

        return { el: fieldset, retenues };
    }

    /**
     * Annule un plan (décision explicite) : remplace la barre par un feedback
     * PERMANENT et mémorise la décision côté serveur (survit au rechargement).
     */
    async cancelMutationPlan(idMessage, barEl) {
        const note = this._planStatusNote('cancelled', 'Plan annulé — aucune donnée n’a été modifiée.');
        this._replaceBar(barEl, note);

        const id = parseInt(idMessage, 10);
        if (!Number.isInteger(id) || id <= 0) return;
        try {
            const response = await fetch(`/admin/assistant-ia/api/mutation/${this.idEntrepriseValue}/${this.idConversationValue}/${id}/cancel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            const data = await response.json().catch(() => ({}));
            // Refuser une étape ne rompt pas la série : le serveur a déjà préparé
            // la suivante (ou clos la mission par son rapport).
            if (response.ok) await this.enchainerProgramme(data.programme);
        } catch (error) {
            console.error('Ket - annulation non mémorisée :', error);
        }
    }

    /**
     * Bandeau d'avancement d'un programme, au-dessus de la barre de décision :
     * référence de la mission, position dans la série, jauge. Rendu à partir des
     * données SERVEUR (le fil n'a aucun compteur à tenir), et null pour un plan
     * isolé — la très grande majorité des cas.
     */
    _renderProgrammeBandeau(programme) {
        if (!programme || !programme.reference) return null;
        const total = parseInt(programme.total, 10) || 0;
        const position = parseInt(programme.position, 10) || 0;

        const bloc = document.createElement('div');
        bloc.className = 'aic-prog-bandeau';

        const ligne = document.createElement('p');
        ligne.className = 'aic-prog-ligne';
        const ref = document.createElement('span');
        ref.className = 'aic-prog-ref';
        ref.textContent = `Programme ${programme.reference}`;
        ligne.appendChild(ref);
        const suite = document.createElement('span');
        suite.textContent = total > 0 ? ` · étape ${position} sur ${total}` : '';
        ligne.appendChild(suite);
        bloc.appendChild(ligne);

        if (total > 0) {
            const jauge = document.createElement('div');
            jauge.className = 'aic-prog-jauge';
            jauge.setAttribute('role', 'img');
            jauge.setAttribute('aria-label', `Étape ${position} sur ${total}`);
            const barre = document.createElement('span');
            barre.style.width = `${Math.max(0, Math.min(100, Math.round((position / total) * 100)))}%`;
            jauge.appendChild(barre);
            bloc.appendChild(jauge);
        }

        return bloc;
    }

    /**
     * Sert la suite d'un programme telle que le serveur l'a préparée : soit la
     * bulle de l'étape suivante avec SA barre de décision, soit le rapport final.
     *
     * Les deux bulles sont écrites par le SERVEUR et déjà persistées : on ne fait
     * que les afficher. C'est délibéré — la prose d'un enchaînement et celle d'un
     * compte rendu ne doivent dépendre d'aucun modèle, sous peine de retrouver les
     * affirmations de complaisance qu'on corrige ici.
     */
    async enchainerProgramme(programme) {
        if (!programme) return;

        if (programme.suivant && programme.suivant.action) {
            const suivant = programme.suivant;
            this._appendBulleMarkdown(suivant.contenu, suivant.idMessage);
            this.renderMutationReview(suivant.action);
            return;
        }

        if (programme.rapport) {
            const rapport = programme.rapport;
            this._appendBulleMarkdown(rapport.contenu, rapport.idMessage);
            if ((parseInt(rapport.corrections, 10) || 0) > 0) {
                this.messagesTarget.appendChild(
                    this._renderProgrammeCorrection(programme.idProgramme, rapport.corrections),
                );
                this.scrollToBottom();
            }
        }
    }

    /** Bulle assistant dont le contenu est du markdown déjà rendu côté serveur. */
    _appendBulleMarkdown(contenu, idMessage) {
        const bubble = this.appendMessage('assistant', '');
        const texte = bubble.querySelector('.aic-msg-text');
        if (texte) texte.innerHTML = renderAssistantMarkdown(contenu || '');
        if (idMessage) this.identifierBulle(bubble, idMessage);
        this.scrollToBottom();

        return bubble;
    }

    /**
     * Proposition de CORRECTION après un rapport portant des écarts. Le contenu
     * des étapes n'est jamais transmis par le client : il est relu du rapport
     * stocké côté serveur — le bouton ne fait que demander.
     */
    _renderProgrammeCorrection(idProgramme, nombre) {
        const bloc = document.createElement('div');
        bloc.className = 'aic-prog-correction';

        const texte = document.createElement('p');
        const n = parseInt(nombre, 10) || 0;
        texte.textContent = `${n} correction${n > 1 ? 's' : ''} peu${n > 1 ? 'vent' : 't'} être préparée${n > 1 ? 's' : ''} — vous les validerez une par une, comme les étapes précédentes.`;
        bloc.appendChild(texte);

        const bouton = this._mutBtn('primary', this.constructor.ICON_CHECK, 'Préparer la correction', async () => {
            bouton.disabled = true;
            await this.lancerCorrection(idProgramme, bloc);
        });
        bloc.appendChild(bouton);

        return bloc;
    }

    /** Reconstruit après un F5 les propositions de correction posées par Twig. */
    restoreProgrammeCorrections() {
        if (!this.hasMessagesTarget) return;
        this.messagesTarget.querySelectorAll('[data-programme-correction]').forEach((el) => {
            let donnees;
            try {
                donnees = JSON.parse(el.dataset.programmeCorrection);
            } catch (e) {
                return;
            }
            el.removeAttribute('data-programme-correction');
            if (!donnees || !donnees.idProgramme) return;
            el.replaceWith(this._renderProgrammeCorrection(donnees.idProgramme, donnees.corrections));
        });
    }

    /** Lance le programme de correction proposé par un rapport. */
    async lancerCorrection(idProgramme, blocEl) {
        const id = parseInt(idProgramme, 10);
        if (!Number.isInteger(id) || id <= 0) return;
        document.dispatchEvent(new CustomEvent('app:loading.start', { bubbles: true }));
        try {
            const response = await fetch(`/admin/assistant-ia/api/programme/${this.idEntrepriseValue}/${this.idConversationValue}/${id}/corriger`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success) {
                this.appendNotice('warning', data.message || "La correction n'a pas pu être préparée.");
                return;
            }
            if (blocEl) blocEl.remove();
            await this.enchainerProgramme(data.programme);
        } catch (error) {
            console.error('Ket - correction non préparée :', error);
            this.appendNotice('error', "La correction n'a pas pu être préparée. Vérifiez votre connexion puis réessayez.");
        } finally {
            document.dispatchEvent(new CustomEvent('app:loading.stop', { bubbles: true }));
        }
    }

    /**
     * Arrête toute la mission. Les étapes non faites sont marquées comme telles et
     * le rapport final est rendu immédiatement : s'arrêter en chemin ne dispense
     * pas de savoir où l'on s'est arrêté.
     */
    async interrompreProgramme(idProgramme, barEl) {
        const id = parseInt(idProgramme, 10);
        if (!Number.isInteger(id) || id <= 0) return;
        this._replaceBar(barEl, this._planStatusNote('cancelled', 'Programme interrompu — les étapes restantes n’ont pas été exécutées.'));
        try {
            const response = await fetch(`/admin/assistant-ia/api/programme/${this.idEntrepriseValue}/${this.idConversationValue}/${id}/interrompre`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            });
            const data = await response.json().catch(() => ({}));
            if (response.ok) await this.enchainerProgramme(data.programme);
        } catch (error) {
            console.error('Ket - interruption non mémorisée :', error);
        }
    }

    /** Remplace la barre de décision par un élément (ou l'ajoute si la barre a disparu). */
    _replaceBar(barEl, replacement) {
        if (barEl && barEl.parentNode) {
            barEl.parentNode.replaceChild(replacement, barEl);
        } else if (this.hasMessagesTarget) {
            this.messagesTarget.appendChild(replacement);
        }
        this.scrollToBottom();
    }

    /**
     * Avertissement AUTORITAIRE (émis par le serveur) : le message décrit un plan
     * ou un « bouton de validation », mais AUCUN plan n'a réellement été préparé
     * (le modèle a présenté un plan sans appeler l'outil). On dit la vérité à
     * l'utilisateur — pas de bouton fantôme, pas d'attente d'une décision qui ne
     * viendra pas. Rendu identique au chargement (Twig) et en direct.
     */
    renderMutationAbsent() {
        this.appendNotice('warning', this.constructor.MUTATION_ABSENT_MESSAGE);
    }

    /** Note de statut PERMANENTE d'un plan (validé / annulé) — même rendu que le serveur. */
    _planStatusNote(kind, text) {
        const p = document.createElement('p');
        p.className = `aic-plan-status aic-plan-status--${kind}`;
        p.setAttribute('role', 'status');
        p.innerHTML = kind === 'done' ? this.constructor.ICON_CHECK : this.constructor.ICON_X;
        const span = document.createElement('span');
        span.textContent = text;
        p.appendChild(span);
        return p;
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
        // Barre à remplacer par le feedback permanent SI la confirmation aboutit
        // (l'exécution part de la modale, qui ne connaît pas le fil).
        this._barreEnAttente = action.bar || null;
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
                    // L'étendue choisie avant l'ouverture de la modale suit la confirmation.
                    payload: { idMessage: action.idMessage, etapes: action.etapesRetenues || null },
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
        if (!detail) return;
        const payload = detail.payload || {};
        if (detail.type === 'ket:mutation.execute') {
            // Déclenché par la modale de confirmation (suppression + mot de passe).
            const bar = this._barreEnAttente;
            this._barreEnAttente = null;
            this.executeMutationPlan(payload.idMessage, payload.password, true, payload.etapes || null)
                .then((status) => {
                    if (status !== 'success' || !bar) return;
                    this._replaceBar(bar, this._planStatusNote('done', 'Plan exécuté — les données ont été enregistrées.'));
                });
            return;
        }
        // Retour du picker de destinataires : confirmation dans le fil, à
        // l'endroit même où l'utilisateur a lancé l'envoi.
        if (detail.type === 'assistant:message.envoye') {
            this.appendNotice('status', payload.message || 'Message envoyé.');
        }
    }

    /**
     * Appelle l'endpoint d'exécution, gère 402/403/422 et rejoue le journal.
     * `viaModal` = true quand l'appel vient de la modale de confirmation
     * (suppression) : les erreurs y sont affichées ; false pour une écriture
     * pure exécutée directement (erreurs affichées en bulle du chat).
     */
    async executeMutationPlan(idMessage, password, viaModal = true, etapes = null) {
        const id = parseInt(idMessage, 10);
        if (!Number.isInteger(id) || id <= 0) return 'error';
        const url = `/admin/assistant-ia/api/mutation/${this.idEntrepriseValue}/${this.idConversationValue}/${id}/execute`;

        // Feedback « coulisses » : barre de progression globale pendant l'exécution.
        document.dispatchEvent(new CustomEvent('app:loading.start', { bubbles: true }));
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // `etapes` = clés des étapes RETENUES. Le serveur filtre le plan qu'il
                // a stocké et re-chiffre : rien de la sélection n'est pris pour argent
                // comptant côté client.
                body: JSON.stringify({ password: password || '', etapes: Array.isArray(etapes) ? etapes : [] }),
            });
            const data = await response.json().catch(() => ({}));

            if (response.ok && data.success) {
                if (viaModal) document.dispatchEvent(new CustomEvent('ui:confirmation.close', { bubbles: true }));
                await this.renderMutationJournal(data.journal || []);
                // Ket a modifié des données : demande au Cerveau de rafraîchir la
                // liste de la rubrique affichée (refresh inconditionnel — une
                // édition d'entité liée peut changer des colonnes calculées de la
                // liste sans figurer sous son short name ; cf. cerveau_controller).
                document.dispatchEvent(new CustomEvent('cerveau:event', {
                    bubbles: true,
                    detail: {
                        type:      'ket:workspace.data-changed',
                        source:    'assistant-chat',
                        payload:   {},
                        timestamp: Date.now(),
                    },
                }));
                // ENCHAÎNEMENT : le serveur a déjà préparé l'étape suivante de la
                // série (ou le rapport final). C'est ici que la boucle se referme —
                // auparavant, une série de plans s'arrêtait net après le premier et
                // il fallait relancer Ket à la main pour chacun des suivants.
                await this.enchainerProgramme(data.programme);
                return 'success';
            }

            // Informations obligatoires manquantes (422) : Ket DEMANDE plutôt que
            // d'afficher une erreur — la question reste dans le fil.
            const champs = data && typeof data.erreurs === 'object' && data.erreurs ? Object.keys(data.erreurs) : [];
            if (response.status === 422 && champs.length > 0) {
                if (viaModal) document.dispatchEvent(new CustomEvent('ui:confirmation.close', { bubbles: true }));
                this.appendMessage(
                    'assistant',
                    `Je ne peux pas exécuter ce plan tel quel : il me manque des informations obligatoires (${champs.join(', ')}). Indiquez-les-moi et je vous représente le plan à jour.`,
                );
                return 'missing';
            }

            // Autres échecs (mot de passe, solde, technique) : message dans la modale
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
            return 'error';
        } catch (error) {
            console.error('Ket - exécution du plan échouée :', error);
            const msg = "L'exécution a échoué. Vérifiez votre connexion puis réessayez.";
            if (viaModal) {
                document.dispatchEvent(new CustomEvent('ui:confirmation.error', { bubbles: true, detail: { error: msg } }));
            } else {
                this.appendNotice('error', msg);
            }
            return 'error';
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
            // Indentation selon le niveau dans l'arbre (élément de collection, etc.).
            const indent = '  '.repeat(Math.max(0, parseInt(step.niveau, 10) || 0));
            if (step.statut === 'echec') {
                lignes.push(`${indent}- [Échec](#danger) ${step.message || 'une étape a échoué — rien n’a été conservé.'}`);
            } else {
                const label = verbe[step.op] || 'Opération';
                const cible = step.cible ? ` « ${step.cible} »` : '';
                lignes.push(`${indent}- [Fait](#success) ${label} — ${step.libelle || step.entite}${cible}`);
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
    /**
     * Ligne du PROGRAMME DU JOUR (bulle d'ouverture rendue par le serveur) :
     * ouvre la fiche concernée dans la colonne de visualisation. Même chemin que
     * l'outil visualiser_fiche — l'endpoint re-valide les droits, fail-closed.
     */
    ouvrirFichePlan(event) {
        const ligne = event.currentTarget;

        this.openVisualizationAction({
            entite: ligne.dataset.planEntite,
            id: ligne.dataset.planId,
        });
    }

    async openVisualizationAction(action) {
        if (!this.hasVisualContextUrlValue) return;
        // Barre de progression du workspace pendant la récupération de la fiche :
        // sans elle, un clic sur une ligne du programme du jour ne produit RIEN à
        // l'écran tant que le serveur répond — l'utilisateur reclique. Le workspace
        // l'arrête de lui-même quand l'onglet s'ouvre (app:tab.opened) ; on ne la
        // coupe donc ici que sur les chemins qui n'ouvrent aucun onglet (erreur).
        document.dispatchEvent(new CustomEvent('app:loading.start', { bubbles: true }));
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
            document.dispatchEvent(new CustomEvent('app:loading.stop', { bubbles: true }));
            console.error('AssistantChat - visualisation échouée :', error);
            // `fetch` lève un TypeError « Failed to fetch » quand la requête n'a
            // même pas atteint le serveur (arrêté, réseau coupé, page laissée
            // ouverte après un redémarrage). Le message brut, en anglais, laisse
            // croire à un bug de la fiche : on nomme la vraie cause et l'action.
            const injoignable = error instanceof TypeError;
            this.appendNotice(
                'error',
                injoignable
                    ? "Le serveur est injoignable : la fiche n'a pas pu être ouverte. Vérifiez votre connexion, puis rechargez la page."
                    : (error.message || "L'ouverture de la fiche a échoué."),
            );
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
    async contexteOperation(doFetch, buildSuccess, render = (html) => this.renderContextes(html)) {
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
                render(data.html || '');
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

    /**
     * Instantané (id + nom) des pièces jointes courantes, lu depuis les puces —
     * pour poser l'agrafe fichiers sur la bulle optimiste (même cliché que celui
     * persisté côté serveur, cf. instantaneFichiers du contrôleur).
     */
    fichiersInstantane() {
        if (!this.hasFichierBarTarget) return [];
        return [...this.fichierBarTarget.querySelectorAll('.aic-fichier-chip')].map((chip) => ({
            id: parseInt(chip.dataset.fichierId, 10),
            nom: chip.dataset.ficNom || chip.querySelector('.aic-chip-label')?.textContent?.trim() || '',
        })).filter((f) => Number.isInteger(f.id));
    }

    // ── Pièces jointes (fichiers attachés à la conversation) ────────────────

    /** Ouvre le sélecteur de fichiers natif (bouton trombone). */
    ouvrirSelecteurFichier() {
        if (this.hasFichierInputTarget) {
            this.fichierInputTarget.click();
        }
    }

    /** Sélection de fichiers → validation JS puis upload (change du champ caché). */
    onFichiersChoisis(event) {
        const input = event.currentTarget;
        const fichiers = [...(input.files || [])];
        input.value = ''; // ré-autorise la re-sélection d'un même fichier
        if (fichiers.length > 0) {
            this.uploadFichiers(fichiers);
        }
    }

    /** Limites d'attache (miroir serveur FichierAttachePolicy), avec repli sûr. */
    get fichierLimits() {
        const l = this.hasFichierLimitsValue ? this.fichierLimitsValue : {};
        return {
            maxFiles: l.maxFiles || 5,
            maxSize: l.maxSize || 10 * 1024 * 1024,
            extensions: Array.isArray(l.extensions) ? l.extensions : [],
        };
    }

    /** Nombre de fichiers déjà attachés (lu depuis les puces rendues serveur). */
    nbFichiersAttaches() {
        if (!this.hasFichierBarTarget) return 0;
        return this.fichierBarTarget.querySelectorAll('.aic-fichier-chip:not(.is-loading)').length;
    }

    /**
     * Valide (taille, format, nombre cumulé) puis téléverse les fichiers choisis.
     * Feedback visuel : une puce « chargement » (spinner) par fichier pendant
     * l'upload, remplacée par le rendu serveur au succès. Gère le 402 (solde)
     * comme l'attache d'objets. La barrière JS DOUBLE la contrainte serveur.
     */
    async uploadFichiers(fichiers) {
        if (!this.hasFichierUrlValue) return;
        const limits = this.fichierLimits;
        const valides = [];
        const erreurs = [];
        let restants = limits.maxFiles - this.nbFichiersAttaches();

        for (const f of fichiers) {
            const ext = (f.name.split('.').pop() || '').toLowerCase();
            if (restants <= 0) {
                erreurs.push(`Limite de ${limits.maxFiles} fichiers par conversation atteinte.`);
                break;
            }
            if (limits.extensions.length > 0 && !limits.extensions.includes(ext)) {
                erreurs.push(`« ${f.name} » : format non autorisé (accepté : ${limits.extensions.join(', ')}).`);
                continue;
            }
            if (f.size > limits.maxSize) {
                erreurs.push(`« ${f.name} » dépasse la taille maximale (${Math.round(limits.maxSize / (1024 * 1024))} Mo).`);
                continue;
            }
            valides.push(f);
            restants -= 1;
        }

        erreurs.forEach((m) => this.appendNotice('warning', m));
        if (valides.length === 0) return;

        const chipsChargement = this.ajouterChipsChargement(valides);
        this.emitContexteOperation({ phase: 'start' });
        let message = "L'ajout du fichier a échoué. Veuillez réessayer.";
        let level = 'error';

        try {
            const formData = new FormData();
            valides.forEach((f) => formData.append('fichiers[]', f, f.name));
            const response = await fetch(this.fichierUrlValue, { method: 'POST', body: formData });
            const data = await response.json().catch(() => ({}));

            if (response.status === 402) {
                chipsChargement.remove();
                message = data.premium ? (data.message || 'Fonctionnalité premium.') : this.tokensMessage(data);
                this.appendNotice('warning', message);
                level = 'warning';
            } else if (!response.ok) {
                chipsChargement.remove();
                message = data.message || message;
                this.appendNotice('error', message);
            } else {
                this.renderFichiers(data.html || '');
                (data.erreurs || []).forEach((m) => this.appendNotice('warning', m));
                const nb = (data.fichiers || []).length;
                message = valides.length === 1
                    ? `« ${valides[0].name} » joint à la conversation.`
                    : `${nb} fichier${nb > 1 ? 's' : ''} joint${nb > 1 ? 's' : ''} à la conversation.`;
                level = 'success';
            }
        } catch (error) {
            console.error('AssistantChat - upload fichier échoué :', error);
            chipsChargement.remove();
            this.appendNotice('error', message);
        } finally {
            this.emitContexteOperation({ phase: 'end', message, level, objets: this.contexteObjets() });
        }
    }

    /**
     * Insère une liste de puces « en cours de chargement » (spinner) dans la
     * barre de fichiers pour un retour visuel immédiat pendant l'upload.
     * Renvoie l'élément conteneur, à retirer en cas d'échec.
     */
    ajouterChipsChargement(fichiers) {
        const conteneur = document.createElement('ul');
        conteneur.className = 'aic-context-chips';
        conteneur.setAttribute('aria-label', 'Fichiers en cours de chargement');
        for (const f of fichiers) {
            const li = document.createElement('li');
            li.className = 'aic-chip aic-fichier-chip is-loading';
            li.setAttribute('aria-busy', 'true');
            const spin = document.createElement('span');
            spin.className = 'aic-chip-spinner';
            spin.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'aic-chip-label';
            label.textContent = f.name; // textContent : échappement systématique
            li.append(spin, label);
            conteneur.append(li);
        }
        if (this.hasFichierBarTarget) {
            this.fichierBarTarget.append(conteneur);
        }
        return conteneur;
    }

    /** Remplace les puces fichiers par le fragment rendu côté serveur. */
    renderFichiers(html) {
        if (this.hasFichierBarTarget) {
            this.fichierBarTarget.innerHTML = html;
        }
        // Une puce survolée peut disparaître du DOM sans mouseout : masquage explicite.
        this._ctxTipHide();
    }

    /** Retire UN fichier de la conversation (bouton × d'une puce fichier). */
    async removeFichier(event) {
        const idFichier = parseInt(event.currentTarget.dataset.fichierId, 10);
        if (!Number.isInteger(idFichier) || !this.hasFichierUrlValue) return;
        const nom = event.currentTarget.closest('.aic-fichier-chip')?.querySelector('.aic-chip-label')?.textContent?.trim();

        await this.contexteOperation(
            () => fetch(`${this.fichierUrlValue}/${idFichier}`, { method: 'DELETE' }),
            () => ({
                message: nom ? `« ${nom} » retiré de la conversation.` : 'Fichier retiré de la conversation.',
                level: 'success',
            }),
            (html) => this.renderFichiers(html),
        );
    }

    /** Vide les pièces jointes (bouton « Tout retirer » de la barre fichiers). */
    async clearFichiers() {
        if (!this.hasFichierUrlValue) return;

        await this.contexteOperation(
            () => fetch(this.fichierUrlValue, { method: 'DELETE' }),
            () => ({ message: 'Pièces jointes retirées de la conversation.', level: 'success' }),
            (html) => this.renderFichiers(html),
        );
    }

    /**
     * Rend, sous la réponse de l'assistant, un panneau de boutons de
     * TÉLÉCHARGEMENT des pièces jointes (directive uiAction 'files-download').
     * Chaque bouton est un lien vers la route de téléchargement FAIL-CLOSED :
     * l'accès est re-vérifié côté serveur au clic. Les libellés viennent du
     * serveur (échappés via textContent) — jamais de HTML brut du modèle.
     */
    renderFilesDownload(action) {
        const fichiers = Array.isArray(action?.fichiers) ? action.fichiers : [];
        if (fichiers.length === 0 || !this.hasMessagesTarget) return;

        const panel = document.createElement('div');
        panel.className = 'aic-files-dl';
        panel.setAttribute('role', 'group');
        panel.setAttribute('aria-label', 'Téléchargement des pièces jointes');

        const titre = document.createElement('p');
        titre.className = 'aic-files-dl-title';
        titre.textContent = fichiers.length === 1 ? 'Télécharger la pièce jointe' : 'Télécharger les pièces jointes';
        panel.appendChild(titre);

        for (const f of fichiers) {
            if (!f || typeof f.url !== 'string') continue;
            const lien = document.createElement('a');
            lien.className = 'aic-file-dl-btn';
            lien.href = f.url;
            lien.setAttribute('download', '');
            lien.setAttribute('rel', 'noopener');
            lien.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>';
            const label = document.createElement('span');
            label.className = 'aic-file-dl-label';
            label.textContent = f.taille ? `${f.nom} (${this.formatTaille(f.taille)})` : String(f.nom || 'fichier');
            lien.appendChild(label);
            panel.appendChild(lien);
        }

        this.messagesTarget.appendChild(panel);
        this.scrollToBottom();
    }

    /** Taille de fichier lisible (o / Ko / Mo). */
    formatTaille(octets) {
        if (octets < 1024) return `${octets} o`;
        if (octets < 1024 * 1024) return `${(octets / 1024).toFixed(1)} Ko`;
        return `${(octets / (1024 * 1024)).toFixed(1)} Mo`;
    }

    // ── Infobulle sombre des puces (pattern data-piste-tip du tableau de bord) ──

    _ctxTipOver(event) {
        if (this._ctxTipPinned) return; // infobulle épinglée par clic : ne pas écraser
        const cible = event.target.closest ? event.target.closest('[data-ctx-tip], [data-fic-tip], [data-msg-contextes]') : null;
        if (!cible || event.target.closest('.aic-chip-remove')) return;
        const tip = this._ctxTipCreate();
        if (cible.dataset.ficTip !== undefined) {
            this._ctxTipBuildFichier(tip, cible);
        } else if (cible.dataset.msgContextes !== undefined) {
            this._ctxTipBuildMessage(tip, cible);
        } else {
            this._ctxTipBuild(tip, cible);
        }
        tip.style.display = 'block';
        this._ctxTipActive = true;
    }

    _ctxTipOut(event) {
        if (this._ctxTipPinned) return;
        const cible = event.target.closest ? event.target.closest('[data-ctx-tip], [data-fic-tip], [data-msg-contextes]') : null;
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
        tip.className = this._ctxTipClasse();
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
        this._ctxTip = tip;
        return tip;
    }

    /**
     * Classes de l'infobulle partagée (.jsb-ctx-tip, stylée dans app.css et
     * mutualisée avec le bloc Pistes du tableau de bord — donc déjà sombre, on
     * n'y touche pas). Elle est attachée au <body>, HORS du sous-arbre du chat :
     * la cascade du thème ne l'atteint pas, d'où ce marqueur explicite. Utile
     * uniquement pour son contour : son ombre portée noire disparaît sur un chat
     * sombre, et l'infobulle perdrait ses limites.
     *
     * Source unique des classes : les 5 endroits qui (ré)affectent className
     * passent par ici.
     * @param {string} variante Variante de style (ex. 'commit-tip').
     */
    _ctxTipClasse(variante = '') {
        const base = variante ? `jsb-ctx-tip ${variante}` : 'jsb-ctx-tip';

        return this._theme === THEME_SOMBRE ? `${base} is-sur-sombre` : base;
    }

    /**
     * Contenu : la fiche EXACTE capturée par l'assistant (posée en data-ctx-fiche
     * par le partial serveur), rendue en tableau sombre — construction DOM via
     * textContent (échappement garanti). Sans fiche : objet supprimé/hors périmètre.
     */
    /**
     * Infobulle ANCRÉE du bouton micro : titre blanc (commit-tip-lead) + texte
     * explicatif gris (commit-tip-para), copie lue sur les data-* du bouton.
     * Position figée au-dessus du micro et alignée à droite (le composer est au
     * coin inférieur droit) — épinglée pour que le suivi du curseur l'ignore.
     */
    _micTipShow() {
        if (!this.hasMicTarget) return;
        const tip = this._ctxTipCreate();
        tip.className = this._ctxTipClasse('commit-tip');
        tip.textContent = '';

        const table = document.createElement('table');
        const addRow = (text, className) => {
            const tr = document.createElement('tr');
            const td = document.createElement('td');
            td.setAttribute('colspan', '2');
            td.className = className;
            td.textContent = text;
            tr.appendChild(td);
            table.appendChild(tr);
        };
        addRow(this.micTarget.dataset.micTipTitle || 'Dicter votre message', 'commit-tip-lead');
        (this.micTarget.dataset.micTipBody || '').split('\n').forEach((para) => {
            if (para.trim() !== '') addRow(para.trim(), 'commit-tip-para');
        });
        tip.appendChild(table);

        // Affiché puis positionné (mesures valides une fois display:block).
        tip.style.display = 'block';
        const rect = this.micTarget.getBoundingClientRect();
        let left = rect.right - tip.offsetWidth;           // aligné au bord droit du micro
        if (left < 8) left = 8;
        let top = rect.top - tip.offsetHeight - 8;          // au-dessus du bouton
        if (top < 8) top = rect.bottom + 8;                 // repli en dessous si pas de place
        tip.style.left = `${left}px`;
        tip.style.top = `${top}px`;

        this._ctxTipActive = true;
        this._ctxTipPinned = true; // figée : _ctxTipMove ne la déplacera pas
    }

    _ctxTipBuild(tip, chip) {
        tip.className = this._ctxTipClasse(); // repli du style partagé (annule un éventuel commit-tip du micro)
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
            // La fiche transmise à l'assistante porte TOUS les indicateurs calculés
            // de l'objet (plusieurs dizaines). Cette infobulle flotte au <body> en
            // pointer-events: none et suit le curseur : elle ne peut pas défiler, et
            // tout afficher la ferait déborder du viewport — donc rogner en silence.
            // On borne donc L'AFFICHAGE, jamais la donnée, et on annonce le reste :
            // l'utilisateur sait ainsi ce que l'assistante a réellement reçu.
            const lignes = Object.entries(fiche);
            for (const [cle, valeur] of lignes.slice(0, this.constructor.CTX_TIP_MAX_LIGNES)) {
                addRow([{ text: cle }, { text: this._ctxTipFormat(valeur) }]);
            }
            const restants = lignes.length - this.constructor.CTX_TIP_MAX_LIGNES;
            if (restants > 0) {
                addRow([{
                    text: `… et ${restants} autre${restants > 1 ? 's' : ''} indicateur${restants > 1 ? 's' : ''} : la fiche COMPLÈTE est transmise à l'assistant.`,
                    colspan: 2,
                    className: 'tip-libelle',
                }]);
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
        tip.className = this._ctxTipClasse(); // repli du style partagé (annule un éventuel commit-tip du micro)
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

    /**
     * Contenu de l'infobulle d'une puce FICHIER : caractéristiques du fichier
     * survolé (posées en data-fic-* par le partial serveur), rendues en tableau
     * sombre — construction DOM via textContent (échappement garanti).
     */
    _ctxTipBuildFichier(tip, chip) {
        tip.className = this._ctxTipClasse(); // repli du style partagé (annule un éventuel commit-tip du micro)
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

        addRow([{ text: 'Pièce jointe', colspan: 2, className: 'tip-section' }]);
        addRow([{ text: 'Nom' }, { text: chip.dataset.ficNom || '—' }]);
        addRow([{ text: 'Type' }, { text: chip.dataset.ficType || 'inconnu' }]);
        addRow([{ text: 'Taille' }, { text: chip.dataset.ficTaille || '—' }]);
        addRow([
            { text: 'Contenu lisible' },
            { text: chip.dataset.ficLisible === '1' ? 'Oui (l’assistant peut le lire)' : 'Non (format non extrait)' },
        ]);
        if (chip.dataset.ficDate) {
            addRow([{ text: 'Ajouté le' }, { text: chip.dataset.ficDate }]);
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
    async typeMessage(texte, refus = false, idMessage = null) {
        const bubble = this.appendMessage('assistant', '', refus, null, null, { idMessage });
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
            // Les blocs ```chart sont masqués pendant la frappe (pas de JSON
            // déroulé mot à mot) ; le graphique n'apparaît qu'au rendu final.
            content.innerHTML = renderAssistantMarkdown(masquerBlocsChart(accumule));
            this.scrollToBottom();
            await new Promise((resolve) => setTimeout(resolve, delai));
        }
        this.rendreAssistant(content, texte); // garantit le texte intégral + graphiques
        bubble.removeAttribute('aria-hidden');

        return bubble;
    }

    /**
     * Point d'entrée unique du rendu d'une bulle assistant : Markdown restreint
     * sanitisé PUIS montage des éventuels graphiques ```chart. Toute écriture de
     * réponse assistant passe par ici (DRY).
     */
    rendreAssistant(el, source) {
        el.innerHTML = renderAssistantMarkdown(source);
        // Le thème est résolu en tête de connect() : un graphique naît donc
        // toujours déjà habillé, jamais repeint après coup.
        monterGraphiquesAssistant(el, this._theme);
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
            this.rendreAssistant(el, el.dataset.mdSource);
        });
    }

    // ═══ Actions d'une bulle (répondre, exporter, envoyer) ════════════════════
    // Un seul menu, deux ouvertures équivalentes : le bouton ⋮ et le CLIC DROIT.
    // La géométrie est partagée parce que `positionnerMenu` raisonne sur un
    // rectangle d'ancre — un curseur n'est qu'un rectangle de 0×0.
    //
    // Aucun markup n'est fabriqué ici : le bouton et la citation sont clonés
    // depuis les <template> rendus par Twig, ce qui garde les libellés et les
    // icônes (resolve_icon_name) côté serveur.

    /** Arme le clic droit sur le fil et les fermetures transitoires. */
    setupMenuBulle() {
        this._menuOuvertSur = null;
        this._bulleActive = null;
        this._onClicDroit = this.ouvrirMenuAuCurseur.bind(this);
        this._onFermerMenu = () => this.fermerMenuBulle();
        this._onPointerHorsMenu = (event) => {
            if (!this._menuOuvertSur) return;
            if (this.hasMenuBulleTarget && this.menuBulleTarget.contains(event.target)) return;
            if (this._menuOuvertSur.contains(event.target)) return;
            this.fermerMenuBulle();
        };
        if (this.hasMessagesTarget) {
            this.messagesTarget.addEventListener('contextmenu', this._onClicDroit);
        }
    }

    teardownMenuBulle() {
        if (this.hasMessagesTarget && this._onClicDroit) {
            this.messagesTarget.removeEventListener('contextmenu', this._onClicDroit);
        }
        this._retirerEcouteursMenu();
        clearTimeout(this._timerCible);
    }

    /** Équipe du bouton ⋮ toutes les bulles de l'historique (idempotent). */
    equiperBulles() {
        if (!this.hasMessagesTarget) return;
        this.messagesTarget.querySelectorAll('.aic-msg[data-message-id]').forEach((bulle) => this.equiperBulle(bulle));
    }

    /**
     * Pose le bouton ⋮ sur une bulle. Rien à faire sans identité : une bulle
     * d'accueil, d'erreur ou encore optimiste n'a rien à exporter ni à citer.
     * Le bouton est un FRÈRE de .aic-msg-text — jamais dedans : `rendreAssistant`
     * réécrit cet innerHTML à chaque mot, et DOMPurify n'y laisserait passer que
     * l'attribut `class`.
     */
    equiperBulle(bulle) {
        if (!bulle?.dataset.messageId || bulle.querySelector('.aic-msg-menu-btn')) return;
        if (!this.hasTplKebabTarget) return;
        const corps = bulle.querySelector('.aic-msg-body');
        if (!corps) return;
        corps.appendChild(this.tplKebabTarget.content.firstElementChild.cloneNode(true));
    }

    /** Ouverture par le bouton ⋮ : l'ancre est le bouton lui-même. */
    ouvrirMenuBulle(event) {
        const bouton = event.currentTarget;
        this._ouvrirMenu(bouton.closest('.aic-msg'), bouton.getBoundingClientRect(), bouton);
    }

    /**
     * Ouverture par CLIC DROIT sur une bulle. Hors bulle, on ne préempte pas :
     * le menu natif du navigateur reste disponible (copier, inspecter…).
     * Bonus : la touche « Menu » du clavier émet le même événement, donc le menu
     * est atteignable sans passer par le bouton.
     */
    ouvrirMenuAuCurseur(event) {
        const bulle = event.target.closest?.('.aic-msg[data-message-id]');
        if (!bulle || !this.hasMenuBulleTarget) return;
        event.preventDefault();
        const curseur = {
            left: event.clientX, right: event.clientX,
            top: event.clientY, bottom: event.clientY,
        };
        this._ouvrirMenu(bulle, curseur, bulle.querySelector('.aic-msg-menu-btn'));
    }

    /** Chemin d'ouverture UNIQUE des deux gestes. */
    _ouvrirMenu(bulle, ancre, boutonAncre) {
        if (!bulle || !this.hasMenuBulleTarget) return;
        // Second geste sur la même bulle = bascule.
        if (this._menuOuvertSur && this._bulleActive === bulle) {
            this.fermerMenuBulle();
            return;
        }
        this.fermerMenuBulle();
        this._ctxTipHide(); // ne pas superposer deux surfaces flottantes

        this._bulleActive = bulle;
        this._menuOuvertSur = boutonAncre ?? bulle;
        this._filtrerItemsMenu(bulle);

        const menu = this.menuBulleTarget;
        menu.hidden = false;
        menu.style.visibility = 'hidden'; // mesurable sans être visible
        const { left, top } = positionnerMenu({
            ancre,
            menu: { largeur: menu.offsetWidth, hauteur: menu.offsetHeight },
            viewport: { largeur: window.innerWidth, hauteur: window.innerHeight },
        });
        menu.style.left = `${left}px`;
        menu.style.top = `${top}px`;
        menu.style.visibility = 'visible';

        boutonAncre?.setAttribute('aria-expanded', 'true');

        document.addEventListener('pointerdown', this._onPointerHorsMenu, true);
        window.addEventListener('resize', this._onFermerMenu);
        if (this.hasMessagesTarget) {
            // Le fil défile (y compris via scrollToBottom) : l'ancre bouge, on ferme.
            this.messagesTarget.addEventListener('scroll', this._onFermerMenu, { passive: true });
        }
    }

    fermerMenuBulle() {
        if (this.hasMenuBulleTarget) {
            this.menuBulleTarget.hidden = true;
        }
        this._menuOuvertSur?.setAttribute?.('aria-expanded', 'false');
        this._menuOuvertSur = null;
        this._retirerEcouteursMenu();
    }

    _retirerEcouteursMenu() {
        if (!this._onPointerHorsMenu) return;
        document.removeEventListener('pointerdown', this._onPointerHorsMenu, true);
        window.removeEventListener('resize', this._onFermerMenu);
        if (this.hasMessagesTarget) {
            this.messagesTarget.removeEventListener('scroll', this._onFermerMenu);
        }
    }

    /** Un item porteur de data-menu-roles ne s'affiche que pour ces rôles. */
    _filtrerItemsMenu(bulle) {
        const role = bulle.dataset.messageRole || '';
        this.menuBulleTarget.querySelectorAll('[role="menuitem"]').forEach((item) => {
            const roles = item.dataset.menuRoles;
            item.hidden = roles !== undefined && !roles.split(' ').includes(role);
        });
    }

    /** Items navigables : ceux que le filtrage laisse visibles. */
    _itemsMenu() {
        if (!this.hasMenuBulleTarget) return [];
        return Array.from(this.menuBulleTarget.querySelectorAll('[role="menuitem"]:not([hidden])'));
    }

    /** Flèches / Home / End dans le menu ; Échap ferme et rend le focus. */
    naviguerMenu(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            const bouton = this._menuOuvertSur;
            this.fermerMenuBulle();
            bouton?.focus?.();
            return;
        }
        const items = this._itemsMenu();
        const suivant = indexApresTouche(event.key, items.indexOf(document.activeElement), items.length);
        if (suivant === null) return;
        event.preventDefault();
        items[suivant].focus();
    }

    /** Sur le bouton ⋮ : ↓ ouvre et entre dans le menu, Échap referme. */
    toucheKebab(event) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.ouvrirMenuBulle(event);
            this._itemsMenu()[0]?.focus();
            return;
        }
        if (event.key === 'Escape' && this._menuOuvertSur) {
            event.preventDefault();
            this.fermerMenuBulle();
        }
    }

    /** Contrat UNIQUE offert aux actions du menu. */
    messageActif() {
        const bulle = this._bulleActive;
        if (!bulle?.dataset.messageId) return null;

        return { id: Number(bulle.dataset.messageId), role: bulle.dataset.messageRole || '', bulle };
    }

    // ── Répondre ──────────────────────────────────────────────────────────────

    /** Prépare la citation dans le composer (brouillon purement client). */
    repondreAuMessage() {
        const actif = this.messageActif();
        if (!actif || !this.hasCitationBarTarget) return;

        const qui = actif.role === 'assistant' ? (this.assistantNomValue || 'Assistant') : 'Vous';
        const extrait = (actif.bulle.querySelector('.aic-msg-text')?.textContent || '').trim();

        this._citation = { id: actif.id, qui, extrait };
        this.citationQuiTarget.textContent = qui;
        this.citationExtraitTarget.textContent = extrait;
        this.citationBarTarget.hidden = false;
        this.inputTarget?.focus();
    }

    annulerCitation() {
        this._citation = null;
        if (this.hasCitationBarTarget) {
            this.citationBarTarget.hidden = true;
            this.citationQuiTarget.textContent = '';
            this.citationExtraitTarget.textContent = '';
        }
    }

    /** Clic sur une citation : rejoindre le message cité et le signaler. */
    allerAuMessage(event) {
        const id = event.currentTarget.dataset.quoteId;
        const cible = this.hasMessagesTarget
            ? this.messagesTarget.querySelector(`.aic-msg[data-message-id="${id}"]`)
            : null;
        if (!cible) return; // défensif : le fil est complet en pratique
        cible.scrollIntoView({ block: 'center', behavior: 'smooth' });
        cible.classList.add('aic-msg--cible');
        clearTimeout(this._timerCible);
        this._timerCible = setTimeout(() => cible.classList.remove('aic-msg--cible'), 1600);
    }

    /** Citation d'une bulle, clonée depuis le gabarit Twig (markup non dupliqué). */
    _monterCitation(corps, citation) {
        if (!citation || !this.hasTplCitationTarget) return;
        const noeud = this.tplCitationTarget.content.firstElementChild.cloneNode(true);
        noeud.dataset.quoteId = String(citation.id);
        noeud.querySelector('.aic-msg-quote-qui').textContent = citation.qui;
        noeud.querySelector('.aic-msg-quote-extrait').textContent = citation.extrait;
        corps.appendChild(noeud);
    }

    // ── Exporter ──────────────────────────────────────────────────────────────

    /**
     * Téléchargement d'un format servi par le serveur. Navigation directe plutôt
     * que fetch + blob : progression native du navigateur, rien à révoquer.
     */
    exporterMessage(event) {
        const actif = this.messageActif();
        if (!actif) return;
        const format = Object.keys(CLE_EXPORT).find((f) => CLE_EXPORT[f] === event.currentTarget.dataset.menuKey);
        if (!format || format === FORMAT_IMAGE) return;
        window.location.assign(urlExportMessage(this.sendUrlValue, actif.id, format));
    }

    /**
     * Capture PNG de la bulle RÉELLE. C'est le seul export fidèle quand la
     * réponse porte un graphique : Chart.js peint dans un <canvas>, qu'aucun
     * rendu serveur ne peut reproduire. Le fichier ne transite jamais par le
     * serveur — donc aucune route, aucun binaire client accepté en retour.
     */
    async exporterImage() {
        const actif = this.messageActif();
        if (!actif) return;
        try {
            const { capturerBulle } = await import('./assistant-message-image.js');
            const blob = await capturerBulle(actif.bulle, { theme: this._theme });
            if (!blob) throw new Error('capture vide');
            const url = URL.createObjectURL(blob);
            const lien = document.createElement('a');
            lien.href = url;
            lien.download = nomFichierImage(actif.id, new Date());
            document.body.appendChild(lien);
            lien.click();
            lien.remove();
            URL.revokeObjectURL(url);
        } catch (error) {
            console.error('AssistantChat - capture image échouée :', error);
            this.appendNotice('error', "L'image n'a pas pu être générée. Réessayez ou exportez en PDF.");
        }
    }

    /**
     * Copie la bulle dans le presse-papiers, EN IMAGE : elle se colle ensuite
     * telle quelle (Ctrl+V) dans un document Word ou un corps d'e-mail —
     * graphiques compris, ce qu'aucune copie de texte ne permet.
     */
    async copierMessage() {
        const actif = this.messageActif();
        if (!actif) return;
        try {
            const { copierBulleDansPressePapier } = await import('./assistant-message-image.js');
            await copierBulleDansPressePapier(actif.bulle, { theme: this._theme });
            this.appendNotice('status', 'Message copié comme image. Collez-le avec Ctrl+V dans Word, un e-mail…');
        } catch (error) {
            console.error('AssistantChat - copie image échouée :', error);
            this.appendNotice(
                'error',
                error?.code === 'non-supporte'
                    ? "Ce navigateur ne sait pas copier une image. Utilisez « Exporter en image », puis insérez le fichier."
                    : "La copie a échoué. Utilisez « Exporter en image », puis insérez le fichier."
            );
        }
    }

    // ── Envoyer par e-mail ────────────────────────────────────────────────────

    /**
     * Envoi demandé par Ket lui-même (« envoie ce message à x@y.z ») : raccourci
     * du picker, quand l'utilisateur donne l'adresse dans la conversation.
     *
     * Le format par défaut est l'IMAGE, pour que le destinataire reçoive le
     * message tel qu'il s'affiche — graphiques compris. Cette capture ne peut
     * être faite QUE par le navigateur : c'est pourquoi l'outil serveur délègue
     * ici plutôt que d'envoyer lui-même. Le POST retombe ensuite sur la route
     * d'envoi standard, qui re-valide tout (adresses, plafond, traçabilité).
     *
     * Si la capture échoue, on bascule sur le PDF plutôt que d'abandonner :
     * la mise en forme est préservée, seule la fidélité du graphique se perd.
     */
    async envoyerMessageDirect(action) {
        const idMessage = Number(action?.idMessage);
        const destinataires = Array.isArray(action?.destinataires) ? action.destinataires : [];
        if (!Number.isInteger(idMessage) || idMessage <= 0 || destinataires.length === 0) return;

        let format = action.format || 'image';
        let image = null;

        if (format === 'image') {
            const bulle = this.hasMessagesTarget
                ? this.messagesTarget.querySelector(`.aic-msg[data-message-id="${idMessage}"]`)
                : null;
            try {
                if (!bulle) throw new Error('bulle introuvable');
                const { capturerBulle } = await import('./assistant-message-image.js');
                const blob = await capturerBulle(bulle, { theme: this._theme });
                if (!blob) throw new Error('capture vide');
                image = await this.blobEnBase64(blob);
            } catch (error) {
                console.error('AssistantChat - capture pour envoi échouée :', error);
                format = 'pdf';
            }
        }

        try {
            const response = await fetch(`${this.sendUrlValue.replace(/\/+$/, '')}/${idMessage}/envoyer`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    emails: destinataires,
                    format,
                    image,
                    message: action.message || '',
                }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.success === false) {
                throw new Error(data.message || `Erreur serveur ${response.status}`);
            }
            this.appendNotice('status', data.message || 'Message envoyé.');
        } catch (error) {
            console.error('AssistantChat - envoi direct échoué :', error);
            this.appendNotice('error', error.message || "L'envoi a échoué. Réessayez ou passez par « Envoyer par e-mail ».");
        }
    }

    /** Blob → base64 nu (sans préfixe data:), forme attendue par le serveur. */
    blobEnBase64(blob) {
        return new Promise((resolve, reject) => {
            const lecteur = new FileReader();
            lecteur.onerror = () => reject(lecteur.error);
            lecteur.onload = () => {
                const resultat = String(lecteur.result || '');
                const virgule = resultat.indexOf(',');
                resolve(virgule === -1 ? resultat : resultat.slice(virgule + 1));
            };
            lecteur.readAsDataURL(blob);
        });
    }

    async envoyerMessageParEmail() {
        const actif = this.messageActif();
        if (!actif) return;
        const { ouvrirPickerAutonome } = await import('./picker-open.js');
        await ouvrirPickerAutonome(urlDestinatairesMessage(this.sendUrlValue, actif.id), {
            controllerName: 'assistant-message-picker',
            errorLabel: "Le choix du destinataire n'a pas pu être ouvert.",
            onErreur: (message) => this.appendNotice('error', message),
        });
    }

    /**
     * Ajoute une bulle de message au fil (structure identique à celle rendue
     * côté serveur dans _assistant_ia_chat.html.twig).
     */
    appendMessage(role, texte, refus = false, contexteObjets = null, fichiersJoints = null, options = {}) {
        const bubble = document.createElement('div');
        bubble.className = `aic-msg aic-msg--${role}${refus ? ' aic-msg--refus' : ''}`;
        bubble.dataset.messageRole = role;
        if (options.idMessage) {
            bubble.dataset.messageId = String(options.idMessage);
        }

        if (role === 'assistant') {
            const avatar = document.createElement('span');
            avatar.className = 'aic-msg-avatar';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.textContent = (this.assistantNomValue || 'A').charAt(0).toUpperCase();
            bubble.appendChild(avatar);
        }

        const body = document.createElement('div');
        body.className = 'aic-msg-body';

        // Citation en tête de bulle (miroir du rendu serveur), clonée du gabarit.
        this._monterCitation(body, options.citation);

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

        // Agrafe des PIÈCES JOINTES à l'envoi (bulle utilisateur) : miroir du rendu
        // serveur (aic-msg-attach--file), infobulle native listant les noms.
        if (role === 'user' && Array.isArray(fichiersJoints) && fichiersJoints.length > 0) {
            const attacheF = document.createElement('span');
            attacheF.className = 'aic-msg-attach aic-msg-attach--file';
            attacheF.title = fichiersJoints.map((f) => f.nom).filter(Boolean).join(', ');
            attacheF.setAttribute('aria-label', `${fichiersJoints.length} fichier${fichiersJoints.length > 1 ? 's' : ''} joint${fichiersJoints.length > 1 ? 's' : ''} à l'envoi de ce message`);
            attacheF.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v5h5"/></svg>';
            const compteurF = document.createElement('span');
            compteurF.textContent = String(fichiersJoints.length);
            attacheF.appendChild(compteurF);
            body.appendChild(attacheF);
        }

        const time = document.createElement('span');
        time.className = 'aic-msg-time';
        time.textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        body.appendChild(time);

        bubble.appendChild(body);
        // Le bouton ⋮ est posé AVANT l'insertion : la zone aria-live n'annonce
        // ainsi qu'une seule fois la bulle complète.
        this.equiperBulle(bubble);
        this.messagesTarget.appendChild(bubble);
        this.scrollToBottom();
        return bubble;
    }

    /**
     * Attache après coup son identité à une bulle optimiste (l'id n'existe qu'au
     * retour du serveur) et l'équipe de ses actions.
     */
    identifierBulle(bubble, idMessage) {
        if (!bubble || !idMessage) return;
        bubble.dataset.messageId = String(idMessage);
        this.equiperBulle(bubble);
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
