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

/** Silence qui clôt une phrase : assez long pour laisser respirer. */
export const FIN_MS = 900;

/** Une phrase ne dépasse pas cette durée : au-delà, on transcrit ce qui a été dit. */
export const PHRASE_MAX_MS = 30000;

/** Le seuil ne descend jamais sous ce plancher : un micro muet n'est pas de la parole. */
export const PLANCHER = 0.012;

/** Multiples du bruit de fond : pour parler, et pour couper Ket. */
export const FACTEUR_PAROLE = 2.2;
export const FACTEUR_INTERRUPTION = 4.5;

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

    let fond = PLANCHER;
    let parle = false;
    let depuis = null; // début de la salve de voix en cours
    let silenceDepuis = null;
    let debutPhrase = null;

    return {
        /** Le seuil courant, utile aux tests et à l'affichage du niveau. */
        seuil(ketParle = false) {
            return Math.max(PLANCHER, fond * (ketParle ? FACTEUR_INTERRUPTION : FACTEUR_PAROLE));
        },
        /** Remise à zéro entre deux phrases (le bruit de fond appris, lui, est gardé). */
        reinitialiser() {
            parle = false;
            depuis = null;
            silenceDepuis = null;
            debutPhrase = null;
        },
        pousser(niveau, instantMs, ketParle = false) {
            const seuil = Math.max(PLANCHER, fond * (ketParle ? FACTEUR_INTERRUPTION : FACTEUR_PAROLE));
            const auDessus = niveau > seuil;

            // Le bruit de fond suit LENTEMENT les silences, et jamais la parole : sinon une
            // longue phrase ferait monter le seuil jusqu'à s'effacer elle-même.
            if (!auDessus && !parle) {
                fond = fond * 0.95 + niveau * 0.05;
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
                    return 'debut';
                }
                return null;
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
