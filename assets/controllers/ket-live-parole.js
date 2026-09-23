/**
 * Cœur PUR du mode Live : SAVOIR QUAND L'UTILISATEUR A FINI DE PARLER.
 *
 * Sans cela, il faudrait un clic pour envoyer chaque phrase — et ce clic est
 * exactement ce que le mode Live supprime. On mesure l'énergie du micro trame par
 * trame : la parole commence quand elle dépasse le bruit ambiant, et la phrase se
 * termine après un silence assez long pour ne pas couper une respiration.
 *
 * LE BRUIT DE FOND EST APPRIS, jamais fixé : un bureau climatisé, un téléphone dans
 * la rue et une pièce silencieuse n'ont pas le même plancher. Un seuil en dur
 * n'écouterait jamais les uns et n'arrêterait jamais les autres.
 *
 * PENDANT QUE KET PARLE, le seuil est relevé : le haut-parleur revient dans le micro
 * (l'annulation d'écho du navigateur n'est jamais parfaite), et Ket se couperait
 * elle-même. Il faut alors une VRAIE voix, plus forte, pour l'interrompre.
 *
 * Aucun micro, aucun DOM : testable sous `node --test tests/js/`.
 */

/** Durée de voix continue avant de déclarer que la phrase commence. */
export const DEBUT_MS = 150;

/**
 * Silence qui clôt une phrase : assez long pour laisser respirer, assez court pour ne
 * pas ajouter d'attente. Ramené de 900 à 700 ms le 2026-09-18, dans la campagne qui a
 * fait tomber le tour de 25 s à une dizaine.
 */
export const FIN_MS = 700;

/** Une phrase ne dépasse pas cette durée : au-delà, on transcrit ce qui a été dit. */
export const PHRASE_MAX_MS = 30000;

/** Le seuil ne descend jamais sous ce plancher : un micro muet n'est pas de la parole. */
export const PLANCHER = 0.012;

/**
 * EN DESSOUS DE QUOI UNE TRAME NE CONTIENT RIEN — pas même le souffle d'une pièce
 * vide, pas même le bruit de fond d'un micro d'ordinateur portable.
 *
 * Trente fois sous le plancher de parole, à dessein : il ne s'agit pas de
 * distinguer une voix d'un murmure, mais un micro VIVANT d'un micro MORT. Un
 * micro réel, dans le silence le plus complet, rend toujours un peu de souffle ;
 * un flux dont le système a retiré la source rend des zéros exacts.
 */
export const SILENCE_NUMERIQUE = 0.0005;

/**
 * CETTE TRAME PORTE-T-ELLE QUOI QUE CE SOIT ?
 *
 * ── POURQUOI CETTE QUESTION EXISTE (incident du 2026-09-23, sur téléphone) ──
 *
 * « Entendu, mais le micro n'a rien capté de votre voix » — dans un bureau
 * silencieux, en parlant fort. La reconnaissance du navigateur entendait
 * parfaitement ; notre flux d'analyse, lui, ne recevait que du vide.
 *
 * SUR ANDROID, LA RECONNAISSANCE PREND LE MICRO. Le système continue alors de
 * livrer des trames à notre flux — au rythme normal, ce qui le faisait passer
 * pour vivant — mais elles sont VIDES. Le juge de provenance en concluait que
 * personne n'avait parlé près de ce micro, et écartait chaque phrase. La
 * détection d'interruption tombait avec lui : Ket continuait de parler
 * par-dessus l'utilisateur, faute d'entendre qu'il la coupait.
 *
 * COMPTER LES TRAMES NE SUFFIT DONC PAS : il faut regarder si elles portent
 * quelque chose. « Des trames arrivent » et « le micro capte » sont deux
 * questions différentes, et c'est la seconde qui compte.
 */
export function trameVivante(niveau) {
    return typeof niveau === 'number' && niveau > SILENCE_NUMERIQUE;
}

/** Il faut dépasser ce multiple du bruit de la pièce pour que ce soit une voix. */
export const FACTEUR_PAROLE = 2.2;

/**
 * Il faut dépasser ce multiple de LA FUITE DU HAUT-PARLEUR pour couper Ket.
 *
 * Ce n'est pas un multiple du bruit de la pièce, et c'est tout l'enjeu : pendant que
 * Ket parle, ce qui revient dans le micro n'est pas le silence du bureau, c'est SA
 * VOIX. Comparer la voix de l'utilisateur au bruit de la pièce revenait à comparer
 * Ket elle-même à ce bruit — elle franchissait le seuil, se prenait pour
 * l'utilisateur, et se coupait au bout d'une seconde.
 *
 * Deux fois la fuite mesurée : une voix humaine, à trente centimètres du micro, est
 * très au-dessus du haut-parleur qu'elle recouvre ; Ket, elle, reste à une fois.
 */
export const FACTEUR_INTERRUPTION = 2;

/**
 * Temps d'écoute de la fuite au début de chaque prise de parole de Ket.
 *
 * On ne peut pas connaître d'avance ce que le haut-parleur renvoie : cela dépend du
 * volume, de l'appareil, de la pièce, d'un casque branché ou non. On l'ÉCOUTE donc, une
 * demi-seconde, avant d'autoriser la moindre interruption. Pendant ce court instant,
 * Ket ne peut pas être coupée — c'est le prix à payer pour qu'elle ne se coupe jamais
 * elle-même, et personne n'interrompt quelqu'un dans sa première demi-seconde.
 */
export const CALIBRATION_MS = 600;

/** Énergie (valeur efficace) d'une trame d'échantillons. */
export function energie(trame) {
    if (!trame || trame.length === 0) return 0;
    let somme = 0;
    for (let i = 0; i < trame.length; i++) somme += trame[i] * trame[i];
    return Math.sqrt(somme / trame.length);
}

/**
 * Détecteur de parole. `pousser(energie, instantMs, ketParle)` rend l'événement du
 * moment : « debut », « fin », « trop-long », ou null.
 *
 * @param {{debutMs?: number, finMs?: number, phraseMaxMs?: number}} options
 */
export function creerDetecteur(options = {}) {
    const debutMs = options.debutMs ?? DEBUT_MS;
    const finMs = options.finMs ?? FIN_MS;
    const phraseMaxMs = options.phraseMaxMs ?? PHRASE_MAX_MS;

    const calibrationMs = options.calibrationMs ?? CALIBRATION_MS;

    let fond = PLANCHER;   // le bruit de la pièce, Ket silencieuse
    let fuite = 0;         // ce que le haut-parleur de Ket renvoie dans le micro
    let calibreJusqua = null;
    let ketParlaitAvant = false;
    let parle = false;
    let depuis = null; // début de la salve de voix en cours
    let silenceDepuis = null;
    let debutPhrase = null;
    let salve = null;          // la prise de parole en cours, observée
    let derniereSalve = null;  // la dernière achevée

    /** Le seuil du moment : la pièce quand Ket se tait, sa fuite quand elle parle. */
    const seuilPour = (ketParle) => (ketParle
        ? Math.max(PLANCHER, fuite * FACTEUR_INTERRUPTION, fond * FACTEUR_PAROLE)
        : Math.max(PLANCHER, fond * FACTEUR_PAROLE));

    return {
        /** Le seuil courant, utile aux tests et à l'affichage du niveau. */
        seuil(ketParle = false) {
            return seuilPour(ketParle);
        },
        /** La fuite mesurée pendant la dernière prise de parole de Ket (diagnostic). */
        fuiteMesuree() {
            return fuite;
        },
        /**
         * CE QUE LE MICRO A RÉELLEMENT ENTENDU en dernier — la pièce à conviction qui
         * manquait pour trier les faux bruits.
         *
         * La reconnaissance du navigateur rend du texte sans jamais dire d'où il vient :
         * une question dite à trente centimètres et une télévision à trois mètres lui
         * paraissent identiques. Le micro, lui, fait la différence — encore fallait-il
         * qu'il en garde la trace.
         *
         * `marge` est le rapport de la CRÊTE au seuil de parole du moment : 1 signifie
         * « tout juste assez fort pour être une voix », 5 « manifestement tout près ».
         * C'est un rapport, jamais un niveau absolu : le gain automatique du micro
         * interdit de raisonner en décibels.
         *
         * @returns {{crete: number, marge: number, dureeMs: number, finMs: number, enCours: boolean}|null}
         */
        dernierePriseDeParole() {
            const observee = salve ?? derniereSalve;
            if (observee === null) return null;

            return {
                crete: observee.crete,
                marge: observee.seuil > 0 ? observee.crete / observee.seuil : 0,
                dureeMs: Math.max(0, observee.finMs - observee.debutMs),
                finMs: observee.finMs,
                enCours: observee === salve,
            };
        },
        /**
         * À APPELER CHAQUE FOIS QU'UN NOUVEAU SON DE KET COMMENCE : sa réponse, un
         * intermède. C'est le seul instant où l'on peut mesurer ce que ce son-là renvoie
         * dans le micro, et chaque source a sa propre force.
         *
         * Le faire au seul changement d'état ne suffit PAS : Ket est « audible » dès sa
         * réflexion, mais quand aucun intermède n'est disponible, ce qu'on mesure alors
         * est le SILENCE. La barre retombait donc au plancher, et sa réponse — bien plus
         * forte — la franchissait aussitôt : elle se coupait elle-même en 150 ms, et ne
         * disait plus un mot.
         */
        recalibrer(instantMs) {
            calibreJusqua = instantMs + calibrationMs;
            fuite = 0;
            this.reinitialiser();
        },
        /** Remise à zéro entre deux phrases (le bruit de fond appris, lui, est gardé). */
        reinitialiser() {
            // La salve qui s'achève devient la pièce à conviction de la phrase qu'on
            // vient d'entendre : le texte reconnu arrivera après, et c'est elle qu'on
            // interrogera pour savoir s'il venait d'assez près.
            if (salve !== null) {
                derniereSalve = salve;
                salve = null;
            }
            parle = false;
            depuis = null;
            silenceDepuis = null;
            debutPhrase = null;
        },
        pousser(niveau, instantMs, ketParle = false) {
            // KET VIENT DE PRENDRE LA PAROLE : on écoute d'abord ce que son haut-parleur
            // renvoie, sans rien interpréter. Une phrase commencée avant ne se poursuit
            // pas à travers sa voix.
            if (ketParle && !ketParlaitAvant) {
                ketParlaitAvant = true;
                calibreJusqua = instantMs + calibrationMs;
                fuite = 0;
                this.reinitialiser();
            }
            if (!ketParle) {
                ketParlaitAvant = false;
                calibreJusqua = null;
            }
            if (calibreJusqua !== null && instantMs < calibreJusqua) {
                fuite = Math.max(fuite, niveau);

                return null;
            }

            const seuil = seuilPour(ketParle);
            const auDessus = niveau > seuil;

            // Le bruit de fond suit LENTEMENT les silences, et jamais la parole : sinon une
            // longue phrase ferait monter le seuil jusqu'à s'effacer elle-même.
            //
            // ET SURTOUT PAS PENDANT QUE KET PARLE. C'était le défaut qui rendait
            // l'interruption IMPOSSIBLE : le haut-parleur revient toujours un peu dans le
            // micro, sous le seuil relevé ; cette fuite était donc apprise comme du bruit
            // ambiant, le fond montait le temps d'une réponse, et le seuil d'interruption
            // — un multiple de ce fond — grimpait avec lui. Plus Ket parlait longtemps,
            // plus il fallait crier pour la couper. Le fond se gèle donc pendant sa
            // parole : c'est celui de la pièce, pas celui du haut-parleur.
            if (!auDessus && !parle) {
                if (ketParle) {
                    // La fuite suit le haut-parleur (qui monte et descend avec la
                    // diction), jamais la voix de l'utilisateur — elle, est au-dessus.
                    fuite = fuite * 0.9 + niveau * 0.1;
                } else {
                    fond = fond * 0.95 + niveau * 0.05;
                }
            }

            if (!parle) {
                if (!auDessus) {
                    depuis = null;
                    return null;
                }
                depuis ??= instantMs;
                if (instantMs - depuis >= debutMs) {
                    parle = true;
                    debutPhrase = depuis;
                    silenceDepuis = null;
                    // Le seuil est figé ICI : c'est celui de la pièce juste avant qu'on
                    // parle, et c'est à lui que la crête sera comparée.
                    salve = { crete: niveau, seuil, debutMs: depuis, finMs: instantMs };
                    return 'debut';
                }
                return null;
            }

            if (salve !== null && auDessus) {
                // La durée ne compte que la VOIX : le silence de fin de phrase (700 ms)
                // n'allonge pas la prise de parole, sinon un claquement de porte suivi
                // d'un silence passerait pour une phrase.
                salve.crete = Math.max(salve.crete, niveau);
                salve.finMs = instantMs;
            }

            if (auDessus) {
                silenceDepuis = null;
            } else {
                silenceDepuis ??= instantMs;
                if (instantMs - silenceDepuis >= finMs) {
                    this.reinitialiser();
                    return 'fin';
                }
            }

            if (debutPhrase !== null && instantMs - debutPhrase >= phraseMaxMs) {
                this.reinitialiser();
                return 'trop-long';
            }

            return null;
        },
    };
}
