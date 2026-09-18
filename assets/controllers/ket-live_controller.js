import { Controller } from '@hotwired/stimulus';
import { creerDetecteur, energie } from './ket-live-parole.js';
import { assembler, duree, encoderWav, reechantillonner, TAUX_OREILLE } from './ket-live-wav.js';
import { ETATS, libelleEtatLive, sessionInitiale, transition } from './ket-live-etat.js';
import { choisir, programmeDesIntermedes } from './ket-live-intermedes.js';
import { jalon, nouveauTour, resumeDuTour } from './ket-live-chrono.js';
import { fusionnerTranscripts } from './dictee-transcript.js';
import { documentLocale } from '../locale.js';

/**
 * @class KetLiveController
 * @description LE MODE LIVE : une conversation orale continue avec Ket.
 *
 * L'utilisateur parle, Ket répond à voix haute, puis réécoute — sans un clic entre les
 * tours. C'est une COUCHE, posée à côté du chat : elle ne sait ni envoyer un message, ni
 * lire une réponse. Elle ENTEND, puis demande au chat (par événements) de faire ce qu'il
 * fait déjà. Le moteur de Ket et sa base de connaissance ne changent pas d'une ligne.
 *
 * Ce que ce contrôleur orchestre, dans l'ordre :
 *  1. le micro et la détection de fin de phrase (ket-live-parole) ;
 *  2. la transcription par les oreilles du serveur, ou par le navigateur en secours ;
 *  3. l'envoi de la question au chat, puis les intermèdes pendant la réflexion ;
 *  4. la lecture de la réponse, puis le retour à l'écoute.
 *
 * Tout ce qui se raisonne vit dans les cœurs PURS (états, parole, WAV, intermèdes) :
 * ici, il ne reste que le branchement aux API du navigateur.
 */
export default class extends Controller {
    static targets = ['panneau', 'etat', 'parole', 'barres', 'astuce'];

    static values = {
        transcrireUrl: String,
        intermedeUrl: String,
        capteurUrl: String,
        intermedes: Object,
    };

    /** Étapes chronométrées, par état quitté : c'est ce découpage qu'on lit dans la console. */
    static ETAPES = {
        [ETATS.TRANSCRIPTION]: 'transcription',
        [ETATS.REFLEXION]: 'reflexion',
        [ETATS.PAROLE]: 'parole',
    };

    /** Taille des trames analysées : ~85 ms à 48 kHz, assez fin pour la détection. */
    static TAILLE_TRAME = 4096;

    connect() {
        this.session = sessionInitiale(this._oreilleParDefaut());
        this._audios = new Map(); // clé d'intermède → Audio préchargé
        this._ditsPendantLAttente = [];
        this._minuteurs = [];

        this._onDemarrer = () => this.demarrer();
        this._onReponse = (event) => this._evenement('reponse-affichee', { bulle: event.detail?.bulle });
        this._onLectureTerminee = () => this._evenement('lecture-terminee');
        this._onTouche = (event) => {
            if (event.key === 'Escape' && this.session.etat !== ETATS.ARRET) {
                event.preventDefault();
                this.arreter();
            }
        };

        this.element.addEventListener('ket-live:demarrer', this._onDemarrer);
        this.element.addEventListener('assistant-chat:reponse-affichee', this._onReponse);
        this.element.addEventListener('assistant-chat:lecture-terminee', this._onLectureTerminee);
        document.addEventListener('keydown', this._onTouche);
    }

    disconnect() {
        this.arreter();
        this.element.removeEventListener('ket-live:demarrer', this._onDemarrer);
        this.element.removeEventListener('assistant-chat:reponse-affichee', this._onReponse);
        this.element.removeEventListener('assistant-chat:lecture-terminee', this._onLectureTerminee);
        document.removeEventListener('keydown', this._onTouche);
    }

    // ── Cycle de vie de la session ───────────────────────────────────────────

    /** Démarre le mode Live. Appelé par le bouton principal du chat (champ vide). */
    demarrer() {
        if (this.session.etat !== ETATS.ARRET) return;
        this._evenement('demarrer');
    }

    /** Termine la session : bouton « Terminer », touche Échap, ou panneau re-rendu. */
    arreter() {
        if (this.session.etat === ETATS.ARRET) return;
        this._evenement('arreter');
    }

    /**
     * LE POINT DE PASSAGE UNIQUE. La machine d'états (pure) décide de l'état suivant et
     * des ordres ; ici, on ne fait que les exécuter. Aucune décision n'est prise ailleurs.
     */
    _evenement(nom, charge = {}) {
        const avant = this.session;
        this.session = transition(avant, nom, charge);
        this._rendre();
        this._chronometrer(avant, this.session);

        // Le chat n'apprend l'ouverture et la fermeture d'une session QUE par là : c'est
        // ce qui lui fait afficher la réponse d'un coup et demander la voix rapide.
        if ((avant.etat === ETATS.ARRET) !== (this.session.etat === ETATS.ARRET)) {
            this._emettre('ket-live:session', { actif: this.session.etat !== ETATS.ARRET });
        }

        for (const action of this.session.actions ?? []) {
            switch (action) {
                case 'ouvrir-micro': this._ouvrirMicro(); break;
                case 'fermer-micro': this._fermerMicro(); break;
                case 'precharger-intermedes': this._prechargerIntermedes(); break;
                case 'transcrire': this._transcrire(); break;
                case 'oreille-navigateur': this._ecouterAvecLeNavigateur(); break;
                case 'garder-ecran-allume': this._garderEcranAllume(); break;
                case 'liberer-ecran': this._libererEcran(); break;
                case 'envoyer-question':
                    this._emettre('ket-live:question', { texte: this.session.derniereParole });
                    break;
                case 'programmer-intermedes': this._programmerIntermedes(); break;
                case 'couper-intermedes': this._couperIntermedes(); break;
                case 'lire-reponse':
                    if (charge.bulle) this._emettre('ket-live:lire', { bulle: charge.bulle });
                    else this._evenement('lecture-terminee');
                    break;
                case 'couper-voix':
                    this._couperIntermedes();
                    this._emettre('ket-live:interrompre');
                    break;
                default: break;
            }
        }
    }

    /**
     * OÙ PART LE TEMPS D'UN TOUR. Le tour commence quand l'utilisateur se tait et se
     * termine quand Ket commence à parler — c'est exactement l'attente qu'il vit. Sans
     * cette mesure côté navigateur, on optimiserait d'après les journaux du serveur,
     * qui ignorent le réseau, l'affichage et le premier son.
     */
    _chronometrer(avant, apres) {
        if (avant.etat === apres.etat) return;
        const instant = performance.now();

        if (avant.etat === ETATS.ECOUTE) {
            this._tour = nouveauTour(instant);
            return;
        }
        const etape = this.constructor.ETAPES[avant.etat];
        if (!this._tour || !etape) return;

        this._tour = jalon(this._tour, etape, instant);
        if (apres.etat === ETATS.PAROLE) {
            const texte = resumeDuTour(this._tour);
            console.debug(`Mode Live — attente avant la voix : ${texte}`);
            this._emettre('ket-live:mesure', { etapes: this._tour.etapes, resume: texte });
        }
    }

    _emettre(nom, detail = {}) {
        this.element.dispatchEvent(new CustomEvent(nom, { bubbles: true, detail }));
    }

    /** L'interface : panneau visible, état écrit (et annoncé), dernière phrase entendue. */
    _rendre() {
        const actif = this.session.etat !== ETATS.ARRET;
        if (this.hasPanneauTarget) {
            this.panneauTarget.hidden = !actif;
            this.panneauTarget.classList.toggle('aic-live--reflexion', this.session.etat === ETATS.REFLEXION);
            this.panneauTarget.classList.toggle('aic-live--parole', this.session.etat === ETATS.PAROLE);
        }
        // La barre de saisie s'efface pendant la session : une seule surface à la fois.
        const saisie = this.element.querySelector('.aic-inputbox');
        if (saisie) saisie.hidden = actif;
        if (this.hasEtatTarget) this.etatTarget.textContent = libelleEtatLive(this.session);
        if (this.hasParoleTarget) this.paroleTarget.textContent = this.session.derniereParole;
        if (this.hasAstuceTarget) this.astuceTarget.hidden = !(actif && this._sansVerrou === true);
    }

    // ── L'écran allumé ───────────────────────────────────────────────────────

    /**
     * UNE CONVERSATION N'EST PAS UNE INACTIVITÉ. Sur un téléphone, parler sans toucher
     * l'écran le fait verrouiller au bout d'une trentaine de secondes : le micro se
     * coupe et la session meurt au milieu d'une phrase. Le verrou d'écran est aussi
     * relâché par le système dès que l'onglet passe en arrière-plan — d'où la reprise
     * au retour.
     */
    async _garderEcranAllume() {
        if (!this._onVisibilite) {
            this._onVisibilite = () => {
                if (document.visibilityState === 'visible' && this.session.etat !== ETATS.ARRET) {
                    this._garderEcranAllume();
                }
            };
            document.addEventListener('visibilitychange', this._onVisibilite);
        }
        if (!navigator.wakeLock?.request) {
            // Firefox, iOS ancien : rien ne casse, mais on le dit plutôt que de laisser
            // l'utilisateur croire à une panne quand l'écran s'éteint.
            this._sansVerrou = true;
            this._rendre();
            return;
        }
        if (this._verrouEcran && this._verrouEcran.released === false) return;

        try {
            this._verrouEcran = await navigator.wakeLock.request('screen');
        } catch (error) {
            console.warn('Mode Live : écran non maintenu allumé.', error);
            this._sansVerrou = true;
            this._rendre();
            return;
        }
        // La session a pu se terminer pendant l'attente : on ne garde pas un verrou orphelin.
        if (this.session.etat === ETATS.ARRET) this._libererEcran();
    }

    _libererEcran() {
        if (this._onVisibilite) {
            document.removeEventListener('visibilitychange', this._onVisibilite);
            this._onVisibilite = null;
        }
        if (this._verrouEcran) {
            try { this._verrouEcran.release(); } catch (e) { /* déjà relâché */ }
            this._verrouEcran = null;
        }
    }

    // ── Le micro et la détection de fin de phrase ────────────────────────────

    async _ouvrirMicro() {
        if (this._flux) return;
        try {
            // L'annulation d'écho évite que Ket s'entende parler et se coupe elle-même.
            this._flux = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });
        } catch (error) {
            console.warn('Mode Live : micro refusé.', error);
            this._evenement('arreter');
            return;
        }
        if (this.session.etat === ETATS.ARRET) {
            this._fermerMicro();
            return;
        }

        const Contexte = window.AudioContext || window.webkitAudioContext;
        this._contexte = new Contexte();
        this._source = this._contexte.createMediaStreamSource(this._flux);
        this._detecteur = creerDetecteur();
        this._trames = [];
        this._analyse = await this._brancherCapteur();
        if (this.session.etat === ETATS.ARRET) {
            this._fermerMicro();
            return;
        }

        this._source.connect(this._analyse);
        // Sortie muette : sans destination, certains navigateurs ne font rien tourner.
        const muet = this._contexte.createGain();
        muet.gain.value = 0;
        this._analyse.connect(muet);
        muet.connect(this._contexte.destination);
    }

    /**
     * LE CAPTEUR DE TRAMES. AudioWorklet d'abord : il tourne sur le thread audio, alors
     * que ScriptProcessorNode — déprécié — partage le fil principal avec le rendu du
     * chat et les requêtes, ce qui livre les trames en retard sur téléphone et fait
     * manquer des débuts de phrase. L'ancien nœud reste le repli.
     */
    async _brancherCapteur() {
        const url = this.hasCapteurUrlValue ? this.capteurUrlValue : '';
        if (url !== '' && this._contexte.audioWorklet && typeof window.AudioWorkletNode === 'function') {
            try {
                await this._contexte.audioWorklet.addModule(url);
                const noeud = new window.AudioWorkletNode(this._contexte, 'ket-live-capteur');
                noeud.port.onmessage = (event) => this._trame(event.data);

                return noeud;
            } catch (error) {
                console.warn('Mode Live : capture moderne indisponible, repli classique.', error);
            }
        }

        const noeud = this._contexte.createScriptProcessor(this.constructor.TAILLE_TRAME, 1, 1);
        noeud.onaudioprocess = (event) => this._trame(event.inputBuffer.getChannelData(0));

        return noeud;
    }

    /** Une trame de micro : on mesure, on accumule, et on écoute la fin de phrase. */
    _trame(donnees) {
        if (this.session.etat === ETATS.ARRET) return;
        const instant = performance.now();
        const ketParle = this.session.etat === ETATS.PAROLE;
        const evenement = this._detecteur.pousser(energie(donnees), instant, ketParle);

        // QUAND LE NAVIGATEUR ÉCOUTE, ce micro ne sert plus qu'à entendre l'utilisateur
        // COUPER Ket : le son n'est ni gardé ni envoyé, la reconnaissance a déjà le texte.
        if (this.session.oreille === 'navigateur') {
            if (evenement === 'debut' && ketParle) this._evenement('voix-detectee');

            return;
        }

        if (evenement === 'debut') {
            this._trames = [];
            this._evenement('voix-detectee');
        }
        // On garde le son dès que la phrase a commencé (une copie : le tampon est réutilisé).
        if (this._trames.length > 0 || evenement === 'debut') {
            this._trames.push(Float32Array.from(donnees));
        }
        if (evenement === 'fin' || evenement === 'trop-long') {
            this._phrase = reechantillonner(assembler(this._trames), this._contexte.sampleRate, TAUX_OREILLE);
            this._trames = [];
            this._evenement('phrase-terminee');
        }
    }

    _fermerMicro() {
        if (this._analyse) {
            this._analyse.onaudioprocess = null;
            if (this._analyse.port) this._analyse.port.onmessage = null;
            try { this._analyse.disconnect(); } catch (e) { /* déjà déconnecté */ }
            this._analyse = null;
        }
        if (this._source) {
            try { this._source.disconnect(); } catch (e) { /* déjà déconnecté */ }
            this._source = null;
        }
        if (this._contexte) {
            try { this._contexte.close(); } catch (e) { /* déjà fermé */ }
            this._contexte = null;
        }
        if (this._flux) {
            this._flux.getTracks().forEach((piste) => piste.stop());
            this._flux = null;
        }
        this._arreterReconnaissance();
    }

    // ── Les oreilles ─────────────────────────────────────────────────────────

    /** La phrase part au serveur ; s'il n'a pas d'oreilles, le navigateur prend le relais. */
    async _transcrire() {
        const phrase = this._phrase ?? new Float32Array(0);
        this._phrase = null;
        if (duree(phrase) < 0.3) {
            this._evenement('silence');
            return;
        }

        try {
            const reponse = await fetch(this.transcrireUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'audio/wav' },
                body: encoderWav(phrase),
            });
            if (reponse.status === 503 || reponse.status === 402) {
                this._evenement('oreille-indisponible');
                return;
            }
            if (!reponse.ok) {
                this._evenement('erreur');
                return;
            }
            const data = await reponse.json();
            this._evenement('texte-entendu', { texte: data.texte ?? '' });
        } catch (error) {
            console.warn('Mode Live : transcription impossible.', error);
            this._evenement('oreille-indisponible');
        }
    }

    /** La reconnaissance du navigateur existe-t-elle ici ? */
    _oreilleParDefaut() {
        return (window.SpeechRecognition || window.webkitSpeechRecognition) ? 'navigateur' : 'serveur';
    }

    /**
     * L'ÉCOUTE PAR LE NAVIGATEUR : gratuite, déjà éprouvée par la dictée, et surtout
     * faite PENDANT qu'on parle — le texte est prêt à la seconde où l'on se tait, là où
     * les oreilles du serveur demandent encore la durée de la phrase.
     *
     * Le micro analysé RESTE OUVERT, pour une seule raison : entendre l'utilisateur
     * couper Ket pendant qu'elle parle. Il n'enregistre plus rien.
     */
    _ecouterAvecLeNavigateur() {
        const Reconnaissance = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Reconnaissance) {
            // Rien à entendre ici : les oreilles du serveur reprennent la main.
            this._evenement('oreille-serveur');
            return;
        }
        if (this._reconnaissance) return;
        const reconnaissance = new Reconnaissance();
        reconnaissance.lang = documentLocale() === 'en' ? 'en-US' : 'fr-FR';
        reconnaissance.continuous = true;
        reconnaissance.interimResults = true;

        reconnaissance.onresult = (event) => {
            const complets = Array.from(event.results).filter((r) => r.isFinal);
            if (complets.length === 0 || this.session.etat === ETATS.REFLEXION) return;
            const texte = fusionnerTranscripts(complets.map((r) => r[0].transcript));
            if (texte.trim() === '') return;

            // PARLER PENDANT QUE KET PARLE, C'EST L'INTERROMPRE — et la phrase ne doit pas
            // se perdre pour autant. Le micro analysé le voit d'ordinaire le premier, mais
            // il peut manquer une voix douce ; ici, la reconnaissance a compris une phrase
            // ENTIÈRE : le doute n'est plus permis, on coupe et on enchaîne.
            if (this.session.etat === ETATS.PAROLE) this._evenement('voix-detectee');

            this._evenement('texte-entendu', { texte });
        };
        reconnaissance.onend = () => {
            // Le navigateur clôt sa session au silence : tant que le Live dure, on relance.
            if (this.session.etat !== ETATS.ARRET) {
                try { reconnaissance.start(); } catch (e) { /* déjà démarrée */ }
            }
        };
        this._reconnaissance = reconnaissance;
        try { reconnaissance.start(); } catch (e) { /* déjà démarrée */ }
    }

    _arreterReconnaissance() {
        if (!this._reconnaissance) return;
        this._reconnaissance.onend = null;
        try { this._reconnaissance.stop(); } catch (e) { /* déjà arrêtée */ }
        this._reconnaissance = null;
    }

    // ── Les intermèdes ───────────────────────────────────────────────────────

    /** Préchargés au démarrage : un intermède qui se ferait attendre ne servirait à rien. */
    _prechargerIntermedes() {
        if (!this.hasIntermedeUrlValue || this._audios.size > 0) return;
        for (const cles of Object.values(this.intermedesValue ?? {})) {
            for (const cle of cles) {
                // L'URL est fabriquée par Twig avec une clé factice, qui respecte la
                // contrainte de la route : on ne la remplace que par une vraie clé.
                const audio = new Audio(this.intermedeUrlValue.replace('remplacer-0', cle));
                audio.preload = 'auto';
                this._audios.set(cle, audio);
            }
        }
    }

    /** Le rythme vient du cœur pur ; ici, on ne fait que poser les minuteurs. */
    _programmerIntermedes() {
        this._couperIntermedes();
        this._ditsPendantLAttente = [];
        for (const etape of programmeDesIntermedes()) {
            this._minuteurs.push(setTimeout(() => this._direIntermede(etape.moment), etape.delaiMs));
        }
    }

    _direIntermede(moment) {
        if (this.session.etat !== ETATS.REFLEXION) return;
        const cle = choisir(this.intermedesValue ?? {}, moment, this._ditsPendantLAttente);
        const audio = cle ? this._audios.get(cle) : null;
        if (!audio) return;
        this._ditsPendantLAttente.push(cle);
        this._enCours = audio;
        audio.currentTime = 0;
        audio.play().catch(() => { /* son refusé par le navigateur : le texte reste affiché */ });
    }

    _couperIntermedes() {
        this._minuteurs.forEach((minuteur) => clearTimeout(minuteur));
        this._minuteurs = [];
        if (this._enCours) {
            try {
                this._enCours.pause();
                this._enCours.currentTime = 0;
            } catch (e) { /* déjà arrêté */ }
            this._enCours = null;
        }
    }
}
