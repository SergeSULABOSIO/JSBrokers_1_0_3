import { Controller } from '@hotwired/stimulus';
import { creerDetecteur, energie } from './ket-live-parole.js';
import { assembler, duree, encoderWav, reechantillonner, TAUX_OREILLE } from './ket-live-wav.js';
import { ETATS, libelleEtatLive, sessionInitiale, transition } from './ket-live-etat.js';
import { choisir, programmeDesIntermedes } from './ket-live-intermedes.js';
import { jalon, nouveauTour, resumeDuTour } from './ket-live-chrono.js';
import { finalesNouvelles, phraseRecevable, priseRecevable, ressembleAKet, retirerLaVoixDeKet } from './ket-live-tri.js';
import { texteAPrononcer } from './assistant-lecture-vocale.js';
import { fusionnerTranscripts } from './dictee-transcript.js';
import { documentLocale } from '../locale.js';
import { renoncementAuMicro } from './ket-live-micro.js';

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
    static targets = ['panneau', 'etat', 'parole', 'barres', 'astuce', 'parler', 'mainsLibres'];

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

    /**
     * Au-delà de ce silence du MICRO (pas de la pièce : aucune trame reçue), on cesse de
     * juger de la provenance. Deux secondes : un micro vivant livre une trame toutes les
     * 85 ms, et ce délai laisse passer une hésitation du navigateur sans ouvrir la porte.
     */
    static SILENCE_MICRO_MS = 2000;

    /**
     * Pourquoi la reconnaissance du navigateur a renoncé, dit à l'utilisateur.
     *
     * « no-speech » et « aborted » n'y sont pas : ce sont le cours normal des choses.
     */
    static CAUSES_OREILLE = {
        'not-allowed': 'Le navigateur a refusé le micro. Autorisez-le pour ce site, puis relancez le mode Live.',
        'service-not-allowed': 'La reconnaissance vocale de ce navigateur est indisponible.',
        'audio-capture': 'Aucun micro utilisable ici.',
        network: 'La reconnaissance vocale passe par Internet : la connexion n’a pas répondu.',
    };

    /**
     * Combien de phrases entendues par le MICRO sans qu'un seul mot n'en sorte avant de
     * soupçonner le conflit de micro. Deux : une peut être un silence mal interprété,
     * deux d'affilée ne le sont pas.
     */
    static PHRASES_MUETTES_AVANT_SOUPCON = 2;

    /**
     * Temps laissé à une phrase pour se TERMINER avant qu'elle ne parte au moteur.
     *
     * SUR ANDROID, LA RECONNAISSANCE HACHE. Elle clôt sa session à chaque respiration et
     * rend des finales très courtes — « donne-moi », « le top 5 », « des clients ».
     * Parties séparément, elles devenaient autant de questions : d'où les réponses en
     * désordre, et l'impression que Ket ne retient « que la dernière phrase ou le dernier
     * mot ». On les recoud donc, en attendant un vrai silence.
     *
     * NEUF CENTS MILLISECONDES : un peu plus que le silence de fin de phrase du détecteur
     * (FIN_MS = 700), assez peu pour ne pas se sentir dans la conversation. Chaque
     * nouveau morceau relance l'attente — tant que vous enchaînez, rien ne part.
     */
    static REGROUPEMENT_MS = 900;

    /** Combien de phrases de Ket on garde pour reconnaître son écho. */
    static PHRASES_RETENUES = 4;

    /** Délai avant de rouvrir l'oreille : le temps que le haut-parleur se taise. */
    static REPRISE_OREILLE_MS = 300;

    /**
     * Combien de trames on garde AVANT qu'une phrase soit déclarée — six, soit un peu
     * plus d'une demi-seconde à 48 kHz. C'est ce qu'il faut pour que la première syllabe
     * survive : la prise de parole n'est reconnue qu'après 150 ms de voix, et tout ce qui
     * précède était perdu.
     */
    static TRAMES_AVANT_PHRASE = 6;

    /**
     * Combien de faux bruits, et sur quelle durée, avant que Ket cesse d'écouter en
     * continu. Trois en une minute : une fois est un accident, trois de suite est un
     * environnement — et continuer d'écouter n'y changerait rien.
     */
    static REJETS_AVANT_DEMANDE = 3;
    static FENETRE_REJETS_MS = 60000;

    connect() {
        this.session = sessionInitiale(this._oreilleParDefaut());
        this._audios = new Map(); // clé d'intermède → Audio préchargé
        this._aLire = [];         // réponses arrivées pendant qu'elle parlait encore
        this._ditsPendantLAttente = [];
        this._minuteurs = [];

        this._onDemarrer = () => this.demarrer();
        this._onReponse = (event) => this._evenement('reponse-affichee', { bulle: event.detail?.bulle });
        this._onLectureTerminee = () => this._evenement('lecture-terminee');
        // LE TOUR NE FINIT PAS QUAND LA RÉPONSE S'AFFICHE, MAIS QUAND ELLE SE FAIT
        // ENTENDRE. Le chrono s'arrêtait à l'affichage et annonçait « attente avant la
        // voix » : tout l'aller-retour de synthèse — et le repli sur la voix du
        // navigateur quand les quotas sont épuisés — se déroulait hors de toute mesure.
        // C'est exactement l'intervalle signalé le 2026-09-20 (« 8 secondes avant de
        // parler »), et on ne peut pas régler ce qu'on ne mesure pas.
        this._onPremierSon = (event) => this._noterLePremierSon(event.detail ?? {});
        this._onTouche = (event) => {
            if (event.key === 'Escape' && this.session.etat !== ETATS.ARRET) {
                event.preventDefault();
                this.arreter();
            }
        };

        this.element.addEventListener('ket-live:demarrer', this._onDemarrer);
        this.element.addEventListener('assistant-chat:reponse-affichee', this._onReponse);
        this.element.addEventListener('assistant-chat:lecture-terminee', this._onLectureTerminee);
        this.element.addEventListener('assistant-chat:voix-premiere', this._onPremierSon);
        document.addEventListener('keydown', this._onTouche);
    }

    disconnect() {
        this.arreter();
        this.element.removeEventListener('ket-live:demarrer', this._onDemarrer);
        this.element.removeEventListener('assistant-chat:reponse-affichee', this._onReponse);
        this.element.removeEventListener('assistant-chat:lecture-terminee', this._onLectureTerminee);
        this.element.removeEventListener('assistant-chat:voix-premiere', this._onPremierSon);
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

        // L'OREILLE SUIT L'ÉTAT, TOUJOURS. C'est posé ici, après chaque transition et
        // avant toute action, parce qu'une seule règle vaut : on n'écoute que lorsque
        // Ket se tait. Voir _accorderLOreille.
        this._accorderLOreille();

        for (const action of this.session.actions ?? []) {
            switch (action) {
                case 'ouvrir-micro': this._ouvrirMicro(); break;
                case 'fermer-micro':
                    // Ce qui est déjà entendu part AVANT que l'oreille ne se ferme : une
                    // phrase retenue dans le tampon serait perdue sans un mot. SAUF si la
                    // session s'arrête — envoyer une question après qu'on a quitté le mode
                    // Live serait répondre à quelqu'un qui est parti.
                    if (this.session.etat === ETATS.ARRET) this._morceaux = [];
                    else this._envoyerLesMorceaux();
                    this._fermerMicro();
                    break;
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
                    this._retenirCeQuElleDit(charge.bulle);
                    // Ket va parler : on écoute d'abord ce que SA VOIX renvoie dans le
                    // micro. Sans cela, la mesure prise pendant la réflexion (souvent le
                    // silence) laisserait la barre au plancher, et elle se couperait
                    // elle-même dès son premier mot.
                    this._detecteur?.recalibrer(performance.now());
                    if (charge.bulle) this._emettre('ket-live:lire', { bulle: charge.bulle });
                    else this._evenement('lecture-terminee');
                    break;
                case 'empiler-reponse':
                    if (charge.bulle) (this._aLire ??= []).push(charge.bulle);
                    break;
                case 'lire-la-suivante': {
                    const suivante = (this._aLire ??= []).shift();
                    if (suivante) this._evenement('reponse-suivante', { bulle: suivante });
                    break;
                }
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
    /**
     * CE QUE L'ATTENTE A VRAIMENT DURÉ, et par quelle voix elle s'est terminée.
     *
     * La source compte autant que le délai : un premier son « navigateur » signifie que
     * TOUTES les voix du serveur ont refusé (crédits ElevenLabs du mois épuisés, quota
     * journalier Gemini atteint), et que chaque lecture paie d'abord ces refus. Sans
     * cette distinction, on chercherait la lenteur dans le réseau ou dans la synthèse,
     * alors qu'elle est dans une file de fournisseurs à bout de souffle.
     */
    _noterLePremierSon({ source = 'inconnue', delaiMs = 0 } = {}) {
        const total = this._tour ? Math.round(performance.now() - this._tour.debut) : null;
        console.debug(
            `Mode Live — PREMIER SON par la voix « ${source} » : ${delaiMs} ms après la demande de lecture`
            + (total === null ? '.' : `, ${total} ms après votre phrase.`),
        );
        this._emettre('ket-live:premier-son', { source, delaiMs, depuisLaPhraseMs: total });
    }

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

    /**
     * TROIS FAUX BRUITS EN UNE MINUTE, ET KET SE TAIT D'ELLE-MÊME.
     *
     * Le filtre écarte ce qui ne lui était pas adressé, mais il ne rend pas la pièce
     * silencieuse : dans un environnement durablement bruyant, l'utilisateur ne voit
     * qu'une chose — Ket ne répond plus, sans qu'il sache pourquoi. Mieux vaut qu'elle
     * le dise et demande un appui.
     */
    _noterUnRejet() {
        const maintenant = Date.now();
        this._rejets = (this._rejets ?? []).filter((t) => maintenant - t < this.constructor.FENETRE_REJETS_MS);
        this._rejets.push(maintenant);
        if (this._rejets.length >= this.constructor.REJETS_AVANT_DEMANDE) {
            this._rejets = [];
            this._evenement('ecoute-a-la-demande');
        }
    }

    /** Le bouton « Parler » du panneau : ouvre le micro pour une phrase. */
    parler() {
        this._evenement('parler');
    }

    /** Le bouton « Mains libres » : on refait confiance à l'écoute continue. */
    mainsLibres() {
        this._rejets = [];
        this._evenement('ecoute-continue');
    }

    /**
     * GARDE EN MÉMOIRE CE QUE KET VIENT DE DIRE — sa réponse lue, ses intermèdes joués.
     *
     * Quelques phrases suffisent : passé deux ou trois tours, un écho n'a plus aucune
     * chance de revenir du haut-parleur, et garder davantage risquerait de retrancher des
     * mots qu'un utilisateur a le droit de reprendre.
     */
    _retenirCeQuElleDit(bulle = null, phrase = null) {
        const dit = phrase ?? (bulle ? texteAPrononcer(bulle.querySelector('.aic-msg-text')?.dataset.mdSource ?? '') : '');
        if (String(dit).trim() === '') return;

        this._phrasesDeKet = [...(this._phrasesDeKet ?? []), dit].slice(-this.constructor.PHRASES_RETENUES);
    }

    /** Le texte d'un intermède, tel que le serveur le déclare (source unique). */
    _phraseDeLIntermede(cle) {
        for (const parMoment of Object.values(this.intermedesValue ?? {})) {
            if (!Array.isArray(parMoment) && parMoment?.[cle]) return String(parMoment[cle]);
        }

        return null;
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
        // POURQUOI ELLE N'ENTEND PAS, écrit noir sur blanc. Une session muette qui
        // affiche « Ket vous écoute… » est la pire des réponses.
        if (this.hasAstuceTarget) {
            const raison = this._raisonSourde ?? (this._sansVerrou === true
                ? 'Gardez l’écran allumé : ce navigateur ne sait pas l’en empêcher.'
                : null);
            this.astuceTarget.hidden = !(actif && raison !== null);
            if (raison !== null) this.astuceTarget.textContent = raison;
        }

        // À LA DEMANDE : deux boutons, l'un pour parler, l'autre pour revenir aux mains
        // libres. Ils n'existent que dans ce mode — une commande inutile est un piège.
        const aLaDemande = actif && this.session.ecoute === 'demande';
        if (this.hasParlerTarget) {
            this.parlerTarget.hidden = !aLaDemande;
            this.parlerTarget.setAttribute('aria-pressed', this.session.micDemande ? 'true' : 'false');
        }
        if (this.hasMainsLibresTarget) this.mainsLibresTarget.hidden = !aLaDemande;
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
        if (this._flux || this._microRenonce) return;
        try {
            // L'annulation d'écho évite que Ket s'entende parler et se coupe elle-même.
            this._flux = await navigator.mediaDevices.getUserMedia({
                audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
            });
        } catch (error) {
            this._renoncerAuFluxDAnalyse(error);
            return;
        }
        if (this.session.etat === ETATS.ARRET) {
            this._fermerMicro();
            return;
        }

        const Contexte = window.AudioContext || window.webkitAudioContext;
        this._contexte = new Contexte();
        // SUR TÉLÉPHONE, UN CONTEXTE NAÎT SUSPENDU. Sans ce réveil, aucune trame n'arrive
        // jamais : plus de détection d'interruption, et surtout plus rien pour juger de
        // la provenance de ce qu'on entend. On est ici dans le geste de l'utilisateur
        // (le bouton Live), le seul moment où le réveil est accordé.
        // `resume()` rend une promesse sur les navigateurs récents, rien du tout sur les
        // anciens : on enveloppe, sinon un « .catch » sur undefined fait tout tomber.
        Promise.resolve(this._contexte.resume?.()).catch(() => { /* déjà éveillé, ou refusé */ });
        this._source = this._contexte.createMediaStreamSource(this._flux);
        this._detecteur = creerDetecteur();
        this._trames = [];
        this._avantPhrase = [];
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
     * LE FLUX D'ANALYSE EST REFUSÉ — ET CE N'EST PAS UNE RAISON DE TOUT ARRÊTER.
     *
     * L'ERREUR QUE CETTE MÉTHODE RÉPARE (constatée en production le 2026-09-23, et
     * invisible en développement). Le flux que nous ouvrons ici ne sert QU'À DEUX
     * CHOSES : entendre l'utilisateur couper Ket, et juger de la provenance d'un son
     * (ket-live-tri). La reconnaissance du navigateur, elle, a sa PROPRE captation
     * et n'en a aucun besoin — le projet le savait déjà, puisque
     * `_laisserLeMicroALaReconnaissance()` abandonne délibérément ce flux sur
     * téléphone pour lui rendre le micro.
     *
     * Or, jusqu'ici, un refus de `getUserMedia` arrêtait la SESSION ENTIÈRE, avec un
     * simple `console.warn` que personne ne lit. Le panneau se refermait, et avec lui
     * la seule ligne capable d'expliquer pourquoi. Résultat : on appuie sur Live, il
     * ne se passe rien, et rien ne dit quoi faire.
     *
     * POURQUOI LA PRODUCTION SEULE EN SOUFFRAIT. La permission du micro est accordée
     * PAR ORIGINE. Sur le poste de développement elle l'est depuis longtemps pour
     * `127.0.0.1` ; sur un domaine de production, c'est une première demande — et il
     * suffit de la fermer d'un clic, ou que le micro soit pris par une autre
     * application, pour retomber ici. Même code, même configuration, comportement
     * opposé.
     *
     * CE QU'ON FAIT DÉSORMAIS : on renonce au flux, on le DIT, et on laisse la
     * conversation continuer si la reconnaissance du navigateur peut entendre. On ne
     * perd que la détection d'interruption et le tri par provenance. On n'arrête que
     * lorsque plus rien ne peut entendre — et là encore, en le disant.
     */
    _renoncerAuFluxDAnalyse(error) {
        this._microRenonce = true;
        // `_microLache` interdit à `_laisserLeMicroALaReconnaissance()` de s'exécuter :
        // il n'y a plus rien à lâcher, et il relancerait la reconnaissance pour rien.
        this._microLache = true;

        const cause = error?.name ?? '';
        const { raison, continuer } = renoncementAuMicro(cause, this._oreilleParDefaut());
        this._raisonSourde = raison;
        console.warn('Mode Live : flux d’analyse indisponible —', cause, error);
        this._emettre('ket-live:micro-refuse', { cause, continuer });

        if (continuer) {
            // La reconnaissance entend toujours : la session continue, amputée de la
            // détection d'interruption. L'astuce du panneau dit ce qui manque.
            this._rendre();

            return;
        }

        // Ni flux, ni reconnaissance : plus personne ne peut entendre. On s'arrête,
        // mais la raison a été posée avant, et le panneau l'affiche jusqu'au bout.
        this._evenement('arreter');
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
        // Le micro donne signe de vie : c'est ce qui autorise à juger de la provenance.
        this._derniereTrame = instant;
        // KET EST AUDIBLE DÈS LA RÉFLEXION : ses intermèdes sortent du haut-parleur
        // pendant qu'elle cherche. Le seuil doit y être relevé comme pendant sa réponse,
        // sinon c'est sa propre voix qui ouvre une phrase.
        const ketParle = this.session.etat === ETATS.PAROLE || this.session.etat === ETATS.REFLEXION;
        const evenement = this._detecteur.pousser(energie(donnees), instant, ketParle);

        // QUAND LE NAVIGATEUR ÉCOUTE, ce micro ne sert plus qu'à entendre l'utilisateur
        // COUPER Ket : le son n'est ni gardé ni envoyé, la reconnaissance a déjà le texte.
        if (this.session.oreille === 'navigateur') {
            if (evenement === 'debut' && ketParle) this._evenement('voix-detectee');
            // LE MICRO A ENTENDU UNE PHRASE ENTIÈRE, ET LA RECONNAISSANCE N'EN A RIEN
            // TIRÉ. Deux fois de suite, ce n'est plus un silence : c'est qu'elle n'a pas
            // le micro. On le lui laisse — quitte à perdre la détection d'interruption,
            // qui ne vaut rien si l'on n'entend plus l'utilisateur.
            if (evenement === 'fin' && !ketParle) {
                this._phrasesMuettes = (this._textesRecus ?? 0) > 0 ? 0 : (this._phrasesMuettes ?? 0) + 1;
                if (this._phrasesMuettes >= this.constructor.PHRASES_MUETTES_AVANT_SOUPCON) {
                    this._laisserLeMicroALaReconnaissance();
                }
            }

            return;
        }

        // LE DÉBUT DE LA PHRASE ÉTAIT MANGÉ. Une prise de parole n'est déclarée qu'après
        // 150 ms de voix confirmée — et l'enregistrement ne commençait qu'à cet instant :
        // la première syllabe partait au serveur amputée, quand elle partait. On garde
        // donc en permanence les dernières trames, et la phrase s'ouvre avec elles.
        const copie = Float32Array.from(donnees);
        if (evenement === 'debut') {
            this._trames = this._avantPhrase.slice();
            this._evenement('voix-detectee');
        }
        if (this._trames.length > 0) {
            this._trames.push(copie);
        }
        this._avantPhrase.push(copie);
        if (this._avantPhrase.length > this.constructor.TRAMES_AVANT_PHRASE) this._avantPhrase.shift();
        if (evenement === 'fin' || evenement === 'trop-long') {
            this._phrase = reechantillonner(assembler(this._trames), this._contexte.sampleRate, TAUX_OREILLE);
            this._trames = [];
            this._avantPhrase = [];
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

    /**
     * CE QUI A ÉTÉ COMPRIS VOUS ÉTAIT-IL ADRESSÉ ? Passage obligé des DEUX oreilles.
     *
     * Le texte n'entre dans la conversation que s'il s'appuie sur une vraie prise de
     * parole, entendue de près par ce micro-ci (cf. ket-live-tri.js). Un rejet ne change
     * rien à l'état : on continue d'écouter, en silence, comme si rien n'avait été dit —
     * ce qui est le cas.
     */
    /**
     * LE MICRO PARLE-T-IL ENCORE ? Sans lui, aucune provenance ne peut être jugée.
     *
     * Un téléphone dont le contexte audio n'a pas démarré ne livre aucune trame : le
     * détecteur reste vierge, et la règle de proximité rejetterait TOUT. Mieux vaut alors
     * faire confiance à la reconnaissance, comme avant ce filtre — une Ket qui n'écoute
     * plus est un défaut bien pire qu'un bruit qui passe.
     */
    _microFiable() {
        return this._derniereTrame !== undefined
            && performance.now() - this._derniereTrame < this.constructor.SILENCE_MICRO_MS;
    }

    _retenirOuIgnorer(texteEntendu, confiance = null) {
        // CE QUE KET VIENT DE DIRE NE VOUS APPARTIENT PAS. La reconnaissance du
        // navigateur entend aussi le haut-parleur : quand on parle PENDANT qu'elle parle,
        // elle fond les deux voix en une seule phrase, et l'écho entrait dans le fil sous
        // le nom de l'utilisateur. Le juge de provenance ne pouvait rien : quelqu'un
        // parlait bel et bien tout près du micro.
        const texte = retirerLaVoixDeKet(texteEntendu, this._phrasesDeKet ?? []);
        // ET QUAND LE RETRANCHEMENT NE PEUT RIEN, LA RESSEMBLANCE TRANCHE. La
        // reconnaissance déforme (« laissez-moi » → « laisse-moi ») : le retrait mot à mot
        // s'arrête au premier écart et rend la phrase entière. Le harnais l'a montré le
        // 2026-09-21 — l'intermède « Hum, laisse-moi vérifier cela… » repartait au moteur
        // comme une question de l'utilisateur, avec les références de sa VRAIE salve
        // précédente. C'est la porte d'entrée de la boucle infinie.
        if (ressembleAKet(texte, this._phrasesDeKet ?? [])) {
            return this._suivreLeVerdict({ recevable: false, motif: 'echo', marge: 0 }, texte);
        }
        const verdict = phraseRecevable({
            texte,
            confiance,
            priseDeParole: this._detecteur?.dernierePriseDeParole() ?? null,
            instantMs: performance.now(),
            microFiable: this._microFiable(),
        });

        return this._suivreLeVerdict(verdict, texte);
    }

    /**
     * RECOUD LES MORCEAUX D'UNE MÊME PRISE DE PAROLE, puis les envoie d'un bloc.
     *
     * Rien n'est jamais jeté : chaque morceau reçu est gardé dans l'ordre où il a été
     * dit, et c'est le SILENCE qui décide que la phrase est finie. C'est la règle posée
     * par l'utilisateur — « elle ne doit rien jeter ni ignorer qui vienne de moi » —,
     * tenue ici sans rien demander au moteur.
     */
    _deposerLeTexte(texte) {
        // ⚠ ON NE FAIT ATTENDRE PERSONNE SANS RAISON. Une oreille qui ne hache pas —
        // celle d'un ordinateur, qui tient sa session ouverte — rend une phrase entière
        // d'un coup : la retenir ajouterait REGROUPEMENT_MS à CHAQUE tour, et le harnais
        // Live l'a mesuré (2104 ms au lieu de 1200). On n'attend donc que là où le
        // hachage est PROUVÉ : la session s'est refermée toute seule (cf. _oreilleHachee).
        if (this._oreilleHachee !== true) {
            this._evenement('texte-entendu', { texte });

            return;
        }
        this._morceaux = [...(this._morceaux ?? []), texte];
        clearTimeout(this._regroupement);
        this._regroupement = setTimeout(() => this._envoyerLesMorceaux(), this.constructor.REGROUPEMENT_MS);
    }

    /** La phrase entière prend la route — une seule question, dans l'ordre dit. */
    _envoyerLesMorceaux() {
        clearTimeout(this._regroupement);
        this._regroupement = null;
        const phrase = (this._morceaux ?? []).join(' ').replace(/\s+/g, ' ').trim();
        this._morceaux = [];
        if (phrase !== '') this._evenement('texte-entendu', { texte: phrase });
    }

    /** Ce qu'on fait d'un verdict, qu'il porte sur le son seul ou sur le texte. */
    _suivreLeVerdict(verdict, texte) {
        if (verdict.recevable) {
            this._deposerLeTexte(texte);

            return true;
        }

        // Journalisé avec ses chiffres : c'est ce qui permettra de régler les seuils sur
        // des mesures réelles plutôt qu'au jugé.
        console.debug(`Mode Live — ignoré (${verdict.motif}, marge ${verdict.marge.toFixed(1)}) : « ${texte} »`);
        this._emettre('ket-live:ignore', { motif: verdict.motif, marge: verdict.marge, texte });
        this._noterUnRejet();

        return false;
    }

    /** La phrase part au serveur ; s'il n'a pas d'oreilles, le navigateur prend le relais. */
    async _transcrire() {
        const phrase = this._phrase ?? new Float32Array(0);
        this._phrase = null;
        if (duree(phrase) < 0.3) {
            this._evenement('silence');
            return;
        }

        // ON NE FAIT PAS TRANSCRIRE UNE TÉLÉVISION. Ce que le micro sait de la salve
        // suffit à l'écarter, et l'écarter ICI épargne la requête, l'attente et les
        // crédits — le texte n'apprendrait rien de plus sur sa provenance.
        const surLeSon = priseRecevable(this._detecteur?.dernierePriseDeParole(), performance.now());
        if (this._microFiable() && !surLeSon.recevable) {
            this._suivreLeVerdict(surLeSon, '(non transcrit)');
            this._evenement('silence');
            return;
        }

        try {
            const reponse = await fetch(this.transcrireUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'audio/wav' },
                body: encoderWav(phrase),
            });
            if (reponse.status === 402) {
                this._evenement('oreille-indisponible');
                return;
            }
            if (!reponse.ok) {
                this._evenement('erreur');
                return;
            }
            const data = await reponse.json();
            // PAS D'OREILLE ICI, ET CE N'EST PAS UNE PANNE : quota épuisé, clé absente.
            // Le serveur le dit par un `repli` dans une réponse normale — une 5xx aurait
            // noirci la console à chaque phrase pour un cas prévu.
            if (typeof data.repli === 'string') {
                this._evenement('oreille-indisponible');
                return;
            }
            const texte = String(data.texte ?? '').trim();
            // Rien d'entendu, ou rien qui vous soit adressé : on réécoute sans rien dire.
            if (texte === '' || !this._retenirOuIgnorer(texte)) this._evenement('silence');
        } catch (error) {
            console.warn('Mode Live : transcription impossible.', error);
            this._evenement('oreille-indisponible');
        }
    }

    /**
     * QUI ÉCOUTE, ET QUAND. La reconnaissance du navigateur ne tourne que dans l'état
     * ÉCOUTE — jamais pendant que Ket réfléchit (ses intermèdes sont audibles), jamais
     * pendant qu'elle parle.
     *
     * ELLE ÉCOUTE TOUT LE TEMPS, ET C'EST VOULU. Fermer l'oreille dès que Ket réfléchit
     * protégeait de sa voix, mais au prix de trois défauts que l'utilisateur a payés :
     * une question posée pendant qu'elle cherchait était PERDUE, le début d'une phrase
     * était mangé par le délai de réouverture, et il fallait attendre son tour pour
     * parler. Ce qui protège désormais n'est plus la surdité mais la PROVENANCE : le
     * micro, lui, a l'annulation d'écho, et il sait qu'un intermède vient du
     * haut-parleur et non de vous (ket-live-tri.js).
     *
     * L'INCIDENT DE FOND (2026-09-19) reste à connaître : cette reconnaissance a sa
     * PROPRE captation, sur laquelle nos contraintes d'écho ne s'appliquent PAS. Elle
     * entend donc Ket. Et en mode `continuous`, elle GARDE ses phrases finalisées et les
     * relivre à chaque événement — d'où, jadis, une question qui repartait en
     * s'allongeant à chaque tour. On ne lit donc QUE les finales nouvelles (cf.
     * `_finalesLues`), au lieu de tout refusionner.
     */
    _accorderLOreille() {
        const invitee = this.session.ecoute !== 'demande' || this.session.micDemande === true;
        // Une oreille qui a prouvé sa surdité ne revient pas : on tournerait en rond
        // entre elle et le serveur, sans que rien ne soit jamais entendu.
        if (this._navigateurSourd && this.session.oreille === 'navigateur') {
            this._arreterReconnaissance();
            this._evenement('oreille-serveur');

            return;
        }
        const doitEcouter = this.session.oreille === 'navigateur' && this.session.etat !== ETATS.ARRET && invitee;
        clearTimeout(this._repriseOreille);

        if (!doitEcouter) {
            this._arreterReconnaissance();
            return;
        }
        this._ecouterAvecLeNavigateur();
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
        // Instance NEUVE : sa liste de résultats part vide.
        this._finalesLues = 0;
        const reconnaissance = new Reconnaissance();
        reconnaissance.lang = documentLocale() === 'en' ? 'en-US' : 'fr-FR';
        reconnaissance.continuous = true;
        reconnaissance.interimResults = true;

        reconnaissance.onresult = (event) => {
            if (this.session.etat === ETATS.ARRET) return;

            // SEULEMENT CE QUI VIENT D'ÊTRE DIT (cf. finalesNouvelles) : la liste cumule
            // tout le passé de la session, et sur Android elle recommence à chaque phrase.
            const { finales: complets, lues } = finalesNouvelles(
                event.results,
                this._finalesLues ?? 0,
                typeof event.resultIndex === 'number' ? event.resultIndex : null,
            );
            this._finalesLues = lues;
            if (complets.length === 0) return;
            const texte = fusionnerTranscripts(complets.map((r) => r[0].transcript));
            if (texte.trim() === '') return;
            // La preuve que cette oreille FONCTIONNE : elle rend des mots.
            this._textesRecus = (this._textesRecus ?? 0) + 1;

            // La confiance du navigateur n'est qu'un second témoin : elle vaut zéro, ou
            // rien du tout, sur bien des versions. Le juge en tient compte sans s'y fier.
            const confiances = complets.map((r) => r[0].confidence).filter((c) => typeof c === 'number' && c > 0);
            this._retenirOuIgnorer(texte, confiances.length > 0 ? Math.min(...confiances) : null);
        };
        reconnaissance.onerror = (event) => {
            const cause = event?.error;
            // Le cours normal des choses : personne n'a parlé, ou c'est nous qui arrêtons.
            if (cause === 'no-speech' || cause === 'aborted') return;
            console.debug('Mode Live — la reconnaissance a renoncé :', cause);

            // LE MICRO EST DÉJÀ PRIS, et c'est le défaut propre au téléphone : la
            // reconnaissance et notre flux d'analyse se disputent le micro ; celui qui
            // arrive second n'entend rien, et se tait sans rien dire. Entendre
            // l'utilisateur prime sur détecter son interruption : on lâche le micro.
            if (cause === 'audio-capture' && this._flux) {
                this._laisserLeMicroALaReconnaissance();
                return;
            }
            if (this.constructor.CAUSES_OREILLE[cause] === undefined) return;

            // Cette oreille-ci ne fonctionnera pas : on passe à celles du serveur, et on
            // DIT pourquoi. Une session qui affiche « Ket vous écoute… » sans entendre
            // quoi que ce soit est la pire des réponses.
            this._navigateurSourd = true;
            this._raisonSourde = this.constructor.CAUSES_OREILLE[cause];
            this._evenement('oreille-serveur');
        };
        reconnaissance.onend = () => {
            // Le navigateur clôt sa session au silence : on relance SANS ATTENDRE, tant
            // que la session dure. Un délai ici, c'est le début d'une phrase mangé.
            // SURTOUT PAS DE REMISE À ZÉRO ICI. Sur Android, la reconnaissance clôt sa
            // session après CHAQUE phrase : remettre le compteur à zéro à chaque reprise
            // faisait relire toutes les finales déjà posées — d'où les mêmes phrases
            // transcrites plusieurs fois, et les réponses qui s'emmêlaient. C'est la
            // liste elle-même qui dira qu'elle a recommencé (finalesNouvelles).
            if (this.session.etat !== ETATS.ARRET && this.session.oreille === 'navigateur') {
                // LA PREUVE DU HACHAGE, et elle vaut mieux qu'un test de navigateur :
                // cette oreille vient de refermer sa session alors qu'on écoutait
                // toujours. C'est la signature d'Android, qui clôt à chaque respiration
                // et fait partir une question par bout de phrase. Désormais on recoud
                // (cf. _deposerLeTexte) — et seulement ici.
                this._oreilleHachee = true;
                // LA SESSION EST FINIE : son décompte aussi. C'est `resultIndex` — le
                // repère du navigateur — qui empêchera de relire une liste qui, elle,
                // aurait continué (cf. finalesNouvelles). Garder le compteur d'une
                // session close faisait PERDRE la phrase suivante quand la nouvelle liste
                // avait la même longueur : « il faut répéter plusieurs fois pour qu'elle
                // réponde », et « elle ne considère que la dernière phrase ».
                this._finalesLues = 0;
                try { reconnaissance.start(); } catch (e) { /* déjà démarrée */ }
            }
        };
        this._reconnaissance = reconnaissance;
        try { reconnaissance.start(); } catch (e) { /* déjà démarrée */ }
    }

    /**
     * LÂCHER LE MICRO POUR QUE LA RECONNAISSANCE L'AIT.
     *
     * Sur ordinateur, les deux cohabitent. Sur téléphone, le flux d'analyse que nous
     * ouvrons peut priver la reconnaissance du micro : elle ne rend alors plus un mot,
     * sans erreur ni message, et le panneau affiche « Ket vous écoute… » pendant que
     * personne n'écoute. On abandonne donc l'analyse — donc la détection d'interruption
     * et le tri par provenance, qui se désactive de lui-même faute de trames — pour
     * garder l'essentiel : entendre l'utilisateur.
     *
     * UNE SEULE FOIS par session : si la reconnaissance reste muette après cela, le
     * micro n'est pas le problème.
     */
    _laisserLeMicroALaReconnaissance() {
        if (this._microLache) return;
        this._microLache = true;
        console.debug('Mode Live — le micro est laissé à la reconnaissance (conflit de capture).');
        this._emettre('ket-live:micro-lache', {});

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
        // La reconnaissance repart sur un micro désormais libre.
        this._arreterReconnaissance();
        this._ecouterAvecLeNavigateur();
    }

    _arreterReconnaissance() {
        clearTimeout(this._repriseOreille);
        if (!this._reconnaissance) return;
        this._reconnaissance.onend = null;
        try { this._reconnaissance.stop(); } catch (e) { /* déjà arrêtée */ }
        this._reconnaissance = null;
    }

    // ── Les intermèdes ───────────────────────────────────────────────────────

    /** Préchargés au démarrage : un intermède qui se ferait attendre ne servirait à rien. */
    _prechargerIntermedes() {
        if (!this.hasIntermedeUrlValue || this._audios.size > 0) return;
        for (const parMoment of Object.values(this.intermedesValue ?? {})) {
            // Le serveur rend « clé => phrase » ; une ancienne page peut encore rendre une
            // simple liste de clés. Les deux formes se préchargent pareil.
            const cles = Array.isArray(parMoment) ? parMoment : Object.keys(parMoment ?? {});
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
        // Les deux attentes que l'utilisateur SUBIT : pendant que le serveur transcrit sa
        // phrase, et pendant que Ket cherche. La machine d'états programme les intermèdes
        // dans les deux cas ; les restreindre à la réflexion les rendait muets sur la
        // première, alors que c'est justement là que le silence commence.
        if (this.session.etat !== ETATS.REFLEXION && this.session.etat !== ETATS.TRANSCRIPTION) return;
        const cle = choisir(this.intermedesValue ?? {}, moment, this._ditsPendantLAttente);
        const audio = cle ? this._audios.get(cle) : null;
        if (!audio) return;
        this._ditsPendantLAttente.push(cle);
        this._retenirCeQuElleDit(null, this._phraseDeLIntermede(cle));
        this._enCours = audio;
        audio.currentTime = 0;
        // Un intermède est un son de Ket comme un autre : il a sa propre force.
        this._detecteur?.recalibrer(performance.now());
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
