import { Controller } from '@hotwired/stimulus';
import { CHAT, FEUILLE, surfaceSuivante, indexDuProchainFocus } from './ket-mobile-feuille.js';

/**
 * @class KetMobileController
 * @description Coquille de l'espace de travail en MODE KET (téléphone, tablette).
 *
 * Elle tient le rôle que `workspace-manager` tient sur ordinateur, mais pour
 * trois gestes seulement :
 *  1. POSER LA CONVERSATION dans la surface de travail — en écoutant
 *     `app:workspace.open-html-in-visualization`, l'événement que le composant
 *     « Assistant » émet DÉJÀ. C'est ce qui permet à `assistant-ia_controller`
 *     de fonctionner ici sans une ligne de changement : création, ouverture et
 *     renommage d'une conversation passent par le même chemin que sur un grand
 *     écran, et n'ont pas à savoir quelle surface les recevra.
 *  2. OUVRIR ET REFERMER LA FEUILLE — le seul recouvrement de cette interface
 *     (liste des conversations + sorties de l'espace de travail).
 *  3. QUITTER L'ESPACE — même boîte de confirmation et même destination que le
 *     bouton « Fermer » du workspace de bureau, y compris lorsque la demande
 *     vient de Ket (`app:workspace.request-logout`).
 *
 * ── CE QU'ELLE NE FAIT PAS ────────────────────────────────────────────────
 * Elle n'ouvre ni rubrique, ni fiche, ni onglet : il n'y a pas de colonne où
 * les poser. Les actions correspondantes de Ket sont écartées en amont (les
 * outils ne lui sont pas déclarés) et, si l'une arrivait tout de même, le chat
 * la refuse par un message explicite plutôt qu'en silence.
 *
 * Tout le reste — dialogues de saisie, boîte de confirmation, toasts, pickers —
 * vit sur le `<body>` (`cerveau`, `dialog-manager`, `notification-manager`) et
 * fonctionne donc ici sans aménagement.
 *
 * La décision « quelle surface est visible » et le cycle de focus de la feuille
 * sont dans `ket-mobile-feuille.js`, testé sous Node.
 */
export default class extends Controller {
    static targets = ['surface', 'feuille', 'feuilleCorps', 'declencheur', 'progressBar'];

    static values = {
        /** Partial du chat de la conversation la plus récente. Vide si aucune. */
        chatUrl: String,
        /** Composant « Assistant » (liste des conversations), chargé dans la feuille. */
        conversationsUrl: String,
        /** Où l'on retourne en fermant l'espace de travail (page personnelle). */
        sortieUrl: String,
        entrepriseNom: String,
    };

    connect() {
        this.surface = CHAT;

        // Le composant « Assistant » émet cet événement à chaque ouverture ou
        // création de conversation. C'est notre unique point d'entrée du chat.
        this.boundPoserChat = this.poserChat.bind(this);
        document.addEventListener('app:workspace.open-html-in-visualization', this.boundPoserChat);

        // Demande de fermeture émise par Ket (outil quitter_workspace).
        this.boundDemanderSortie = this.demanderSortie.bind(this);
        document.addEventListener('app:workspace.request-logout', this.boundDemanderSortie);

        // Exécuteurs confirmés par la modale générique (protocole du Cerveau).
        this.boundCerveau = this.handleCerveauEvent.bind(this);
        document.addEventListener('cerveau:event', this.boundCerveau);

        // Une conversation supprimée pendant que son chat est affiché laisserait
        // un fil orphelin : tout envoi répondrait 404.
        this.boundConversationSupprimee = this.conversationSupprimee.bind(this);
        document.addEventListener('app:assistant.conversation-supprimee', this.boundConversationSupprimee);

        // Barre de progression globale. Sur ordinateur c'est `workspace-manager`
        // qui écoute ces deux événements ; ici, sans cet abonnement, l'attente
        // de tout composant qui les émet (création de conversation, ouverture
        // d'une fiche) n'aurait AUCUN retour visible.
        this.boundLoadingStart = () => this._afficherProgression(true);
        this.boundLoadingStop = () => this._afficherProgression(false);
        document.addEventListener('app:loading.start', this.boundLoadingStart);
        document.addEventListener('app:loading.stop', this.boundLoadingStop);

        // Piégeage du focus de la feuille : posé une fois, actif seulement
        // lorsqu'elle est ouverte (cf. `garderLeFocus`).
        this.boundGarderLeFocus = this.garderLeFocus.bind(this);
        document.addEventListener('keydown', this.boundGarderLeFocus, true);

        this.chargerLeChat();
    }

    disconnect() {
        document.removeEventListener('app:workspace.open-html-in-visualization', this.boundPoserChat);
        document.removeEventListener('app:workspace.request-logout', this.boundDemanderSortie);
        document.removeEventListener('cerveau:event', this.boundCerveau);
        document.removeEventListener('app:assistant.conversation-supprimee', this.boundConversationSupprimee);
        document.removeEventListener('keydown', this.boundGarderLeFocus, true);
        document.removeEventListener('app:loading.start', this.boundLoadingStart);
        document.removeEventListener('app:loading.stop', this.boundLoadingStop);
    }

    // ── La conversation ─────────────────────────────────────────────────────

    /**
     * Va chercher le chat de la dernière conversation et le pose dans la
     * surface. Le serveur ne l'a pas rendu en ligne à dessein : son partial a
     * besoin du programme du jour, des fiches de contexte et du thème, tous
     * déjà calculés par la route du chat — la recopier ici en ferait une
     * seconde version à tenir à jour.
     *
     * C'est le même chemin que la restauration du panneau de la colonne 4 sur
     * ordinateur (`workspace-manager._restoreHtmlVisualizationTab`).
     */
    async chargerLeChat() {
        if (!this.hasChatUrlValue || this.chatUrlValue === '') {
            // Aucune conversation : le gabarit affiche déjà l'invitation à en
            // ouvrir une. On n'en crée PAS d'office — ce serait une écriture que
            // l'utilisateur n'a pas demandée.
            return;
        }

        this._loading(true);
        try {
            const response = await fetch(this.chatUrlValue);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            this._injecterChat(await response.text());
        } catch (error) {
            console.error('KetMobile - chargement de la conversation impossible :', error);
            this._afficherEchecDuChat();
        } finally {
            this._loading(false);
        }
    }

    /**
     * Réception de `app:workspace.open-html-in-visualization`. Le même
     * événement sert au chat et, sur ordinateur, à n'importe quel panneau HTML :
     * on ne pose que ce qui porte du HTML, et on referme la feuille — elle
     * masquerait ce qu'elle vient d'ouvrir.
     */
    poserChat(event) {
        const html = event.detail?.html;
        if (typeof html !== 'string' || html === '') return;

        this._injecterChat(html);
        // Mémorise la source pour un rechargement éventuel (même rôle que le
        // `sourceUrl` persisté par le workspace de bureau).
        if (event.detail.sourceUrl) {
            this.chatUrlValue = event.detail.sourceUrl;
        }
        this._appliquer('chat-pose');
    }

    _injecterChat(html) {
        if (!this.hasSurfaceTarget) return;
        this.surfaceTarget.innerHTML = html; // Stimulus connecte `assistant-chat`.
    }

    /**
     * Échec du chargement : on NOMME la cause et l'action possible. Un squelette
     * qui tourne indéfiniment laisserait croire à une lenteur du réseau.
     */
    _afficherEchecDuChat() {
        if (!this.hasSurfaceTarget) return;
        const bloc = document.createElement('div');
        bloc.className = 'km-vide';
        bloc.setAttribute('role', 'alert');
        const titre = document.createElement('h1');
        titre.textContent = 'La conversation n\'a pas pu être ouverte';
        const texte = document.createElement('p');
        texte.textContent = 'Vérifiez votre connexion, puis rechargez la page. '
            + 'Vous pouvez aussi ouvrir une autre conversation depuis « Mon espace ».';
        const bouton = document.createElement('button');
        bouton.type = 'button';
        bouton.className = 'km-cta';
        bouton.textContent = 'Recharger';
        bouton.addEventListener('click', () => window.location.reload());
        bloc.append(titre, texte, bouton);
        this.surfaceTarget.replaceChildren(bloc);
    }

    /**
     * La conversation affichée vient d'être supprimée : on vide la surface. La
     * laisser en place donnerait un fil dans lequel on peut écrire mais dont
     * tout envoi répond 404.
     */
    conversationSupprimee(event) {
        const id = event.detail?.convId;
        if (id === undefined || id === null) return;
        if (!this.hasSurfaceTarget) return;

        const chat = this.surfaceTarget.querySelector('[data-assistant-chat-id-conversation-value]');
        if (!chat) return;
        if (String(chat.dataset.assistantChatIdConversationValue) !== String(id)) return;

        this.chatUrlValue = '';
        const bloc = document.createElement('div');
        bloc.className = 'km-vide';
        const titre = document.createElement('h1');
        titre.textContent = 'Conversation supprimée';
        const texte = document.createElement('p');
        texte.textContent = 'Ouvrez-en une autre, ou démarrez-en une nouvelle depuis « Mon espace ».';
        bloc.append(titre, texte);
        this.surfaceTarget.replaceChildren(bloc);
    }

    // ── La feuille ──────────────────────────────────────────────────────────

    async ouvrirFeuille(event) {
        // L'élément à qui rendre le focus à la fermeture (contrôle explicite :
        // on revient d'où l'on vient). Le déclencheur peut être le bouton de la
        // barre comme le CTA de l'état vide.
        this.retourDuFocus = event?.currentTarget instanceof HTMLElement
            ? event.currentTarget
            : (this.hasDeclencheurTarget ? this.declencheurTarget : null);

        this._appliquer('ouvrir-feuille');
        await this.chargerLesConversations();
    }

    fermerFeuille() {
        if (this.surface !== FEUILLE) return;
        this._appliquer('fermer-feuille');
    }

    /**
     * Recharge la liste des conversations à CHAQUE ouverture. Elle change dans
     * le dos de la feuille : un envoi remonte sa conversation en tête, Ket peut
     * en renommer une. Une liste mise en cache montrerait un ordre périmé, et le
     * composant est bon marché.
     */
    async chargerLesConversations() {
        if (!this.hasFeuilleCorpsTarget || !this.hasConversationsUrlValue) return;

        this._loading(true);
        try {
            const response = await fetch(this.conversationsUrlValue);
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            // Stimulus connecte `assistant-ia` sur le composant injecté : c'est
            // lui qui porte création, renommage et suppression, inchangé.
            this.feuilleCorpsTarget.innerHTML = await response.text();
        } catch (error) {
            console.error('KetMobile - chargement des conversations impossible :', error);
            const alerte = document.createElement('p');
            alerte.setAttribute('role', 'alert');
            alerte.className = 'km-vide';
            alerte.textContent = 'La liste des conversations n\'a pas pu être chargée. '
                + 'Vérifiez votre connexion, puis réessayez.';
            this.feuilleCorpsTarget.replaceChildren(alerte);
        } finally {
            this._loading(false);
        }
    }

    /**
     * Applique une transition de surface. Passer par la machine à états, plutôt
     * que de basculer l'attribut à la main dans chaque gestionnaire, est ce qui
     * garantit qu'un geste ne laisse jamais deux surfaces visibles.
     */
    _appliquer(evenement) {
        const avant = this.surface;
        this.surface = surfaceSuivante(this.surface, evenement);
        if (this.surface === avant) return;

        const ouverte = this.surface === FEUILLE;

        if (this.hasFeuilleTarget) {
            // `hidden` et non `style.display` : l'attribut sort l'élément de la
            // tabulation ET de l'arbre d'accessibilité, sans `aria-hidden` à tenir.
            this.feuilleTarget.hidden = !ouverte;
        }
        if (this.hasDeclencheurTarget) {
            this.declencheurTarget.setAttribute('aria-expanded', ouverte ? 'true' : 'false');
        }

        if (ouverte) {
            this._focaliserDansLaFeuille();
            return;
        }

        // Retour du focus à son déclencheur : sans cela, le focus retombe sur le
        // `<body>` et la navigation au clavier repart du haut de la page.
        this.retourDuFocus?.focus?.();
    }

    // ── Piégeage du focus (WCAG 2.4.3) ──────────────────────────────────────

    /**
     * Tabulation piégée dans la feuille tant qu'elle est ouverte. Sans ce
     * piège, la tabulation atteindrait la conversation qui est visuellement
     * recouverte : l'utilisateur au clavier écrirait dans un champ qu'il ne voit
     * pas.
     *
     * ⚠ Le calcul est délégué à `indexDuProchainFocus` : il se trompe aux deux
     * bords du cycle et sur l'élément actif introuvable, et il est testé.
     */
    garderLeFocus(event) {
        if (this.surface !== FEUILLE || event.key !== 'Tab') return;
        if (!this.hasFeuilleTarget) return;

        const focusables = this._focusablesDeLaFeuille();
        const index = indexDuProchainFocus({
            nombre: focusables.length,
            indexActif: focusables.indexOf(document.activeElement),
            versArriere: event.shiftKey,
        });

        // Rien à focaliser : on laisse le navigateur faire. Empêcher une
        // tabulation sans rien proposer piégerait l'utilisateur pour de bon.
        if (index === null) return;

        event.preventDefault();
        focusables[index].focus();
    }

    _focaliserDansLaFeuille() {
        const focusables = this._focusablesDeLaFeuille();
        // Le premier élément est la croix de fermeture : le focus y arrive donc
        // sur la sortie, ce qui est le repère attendu dans un panneau modal.
        (focusables[0] ?? this.feuilleTarget).focus?.();
    }

    /**
     * Les éléments réellement focalisables de la feuille, dans l'ordre du
     * document. `offsetParent` écarte ce qui est masqué : la feuille contient un
     * champ de renommage inline qui n'existe que pendant l'édition.
     */
    _focusablesDeLaFeuille() {
        const selecteur = 'a[href], button:not([disabled]), input:not([disabled]),'
            + ' select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

        return Array.from(this.feuilleTarget.querySelectorAll(selecteur))
            .filter((el) => el.offsetParent !== null || el === document.activeElement);
    }

    // ── Sortie de l'espace de travail ───────────────────────────────────────

    /**
     * Demande de fermeture — par le bouton de la feuille ou par Ket
     * (`app:workspace.request-logout`). RIEN ne s'exécute sans validation
     * manuelle : on soumet la boîte de confirmation générique, exactement comme
     * `workspace-manager#requestLogoutViaBus` sur ordinateur, avec le même type
     * d'exécuteur — de sorte que les deux surfaces racontent la même histoire.
     */
    demanderSortie(event) {
        event?.preventDefault?.();
        if (!this.hasSortieUrlValue || this.sortieUrlValue === '') return;

        const nom = this.entrepriseNomValue || 'cette entreprise';
        const safeNom = nom.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        document.dispatchEvent(new CustomEvent('ui:confirmation.request', {
            detail: {
                title: "Fermer l'espace de travail",
                body: `<p>Vous êtes sur le point de fermer l'espace de travail de <strong>${safeNom}</strong> et de revenir à votre page personnelle.</p><p>Voulez-vous continuer ?</p>`,
                showIrreversible: false,
                onConfirm: {
                    type: 'app:workspace.logout-execute',
                    payload: { url: this.sortieUrlValue },
                },
            },
        }));
    }

    handleCerveauEvent(event) {
        if (event.detail?.type !== 'app:workspace.logout-execute') return;
        const url = event.detail.payload?.url;
        if (url) {
            window.location.href = url;
        }
    }

    /**
     * Réclame la barre de progression par le BUS et non en la touchant : c'est
     * le même événement que le reste de l'application émet, donc un seul
     * chemin, et nos propres chargements s'affichent comme les autres.
     */
    _loading(actif) {
        document.dispatchEvent(new CustomEvent(actif ? 'app:loading.start' : 'app:loading.stop', {
            bubbles: true,
        }));
    }

    /**
     * Compteur et non booléen : deux chargements concurrents (le chat et la
     * liste des conversations, au premier geste) se terminent l'un après
     * l'autre, et le premier arrivé masquerait la barre alors que le second
     * travaille encore.
     */
    _afficherProgression(actif) {
        this.chargementsEnCours = Math.max(0, (this.chargementsEnCours || 0) + (actif ? 1 : -1));
        if (!this.hasProgressBarTarget) return;
        this.progressBarTarget.style.display = this.chargementsEnCours > 0 ? 'block' : 'none';
    }
}
