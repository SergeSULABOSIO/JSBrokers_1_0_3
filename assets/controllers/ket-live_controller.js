import { Controller } from '@hotwired/stimulus';
import { creerDetecteur, energie } from './ket-live-parole.js';
import { assembler, duree, encoderWav, reechantillonner, TAUX_OREILLE } from './ket-live-wav.js';
import { ETATS, libelleEtatLive, sessionInitiale, transition } from './ket-live-etat.js';
import { choisir, programmeDesIntermedes } from './ket-live-intermedes.js';
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
    static targets = ['panneau', 'etat', 'parole', 'barres'];

    static values = {
        transcrireUrl: String,
        intermedeUrl: String,
        intermedes: Object,
    };

    /** Taille des trames analysées : ~85 ms à 48 kHz, assez fin pour la détection. */
    static TAILLE_TRAME = 4096;

    connect() {
        this.session = sessionInitiale();
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

        for (const action of this.session.actions ?? []) {
            switch (action) {
                case 'ouvrir-micro': this._ouvrirMicro(); break;
                case 'fermer-micro': this._fermerMicro(); break;
                case 'precharger-intermedes': this._prechargerIntermedes(); break;
                case 'transcrire': this._transcrire(); break;
                case 'oreille-navigateur': this._ecouterAvecLeNavigateur(); break;
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
        this._analyse = this._contexte.createScriptProcessor(this.constructor.TAILLE_TRAME, 1, 1);
        this._detecteur = creerDetecteur();
        this._trames = [];

        this._analyse.onaudioprocess = (event) => this._trame(event.inputBuffer.getChannelData(0));
        this._source.connect(this._analyse);
        // Sortie muette : sans destination, certains navigateurs ne font rien tourner.
        const muet = this._contexte.createGain();
        muet.gain.value = 0;
        this._analyse.connect(muet);
        muet.connect(this._contexte.destination);
    }

    /** Une trame de micro : on mesure, on accumule, et on écoute la fin de phrase. */
    _trame(donnees) {
        if (this.session.etat === ETATS.ARRET || this.session.oreille === 'navigateur') return;
        const instant = performance.now();
        const ketParle = this.session.etat === ETATS.PAROLE;
        const evenement = this._detecteur.pousser(energie(donnees), instant, ketParle);

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

    /**
     * ÉCOUTE DE SECOURS, avec la reconnaissance du navigateur : gratuite, instantanée,
     * et déjà éprouvée par la dictée. Elle remplace le micro analysé pour le reste de la
     * session — les deux écouteraient la même voix deux fois.
     */
    _ecouterAvecLeNavigateur() {
        const Reconnaissance = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!Reconnaissance) {
            this._evenement('arreter');
            return;
        }
        this._fermerMicro();
        const reconnaissance = new Reconnaissance();
        reconnaissance.lang = documentLocale() === 'en' ? 'en-US' : 'fr-FR';
        reconnaissance.continuous = true;
        reconnaissance.interimResults = true;

        reconnaissance.onresult = (event) => {
            const complets = Array.from(event.results).filter((r) => r.isFinal);
            if (complets.length === 0 || this.session.etat === ETATS.REFLEXION) return;
            const texte = fusionnerTranscripts(complets.map((r) => r[0].transcript));
            if (texte.trim() === '') return;
            // Le navigateur a déjà fait le travail des oreilles : on saute la transcription.
            this.session = transition(this.session, 'phrase-terminee');
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
