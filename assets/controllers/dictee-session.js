/**
 * Cœur PUR d'une SESSION de dictée de Ket : c'est l'utilisateur qui décide quand il
 * a fini de parler, pas le navigateur.
 *
 * Le constat. Même avec `continuous = true`, Chrome clôt la reconnaissance au premier
 * silence de quelques secondes (sur Android, souvent après une seule phrase). Le chat
 * se contentait alors de repasser le micro au repos : on avait l'impression que Ket
 * coupait la parole. Une dictée VOULUE survit donc à ces fins imposées — la
 * reconnaissance est relancée — et ne s'arrête que sur un geste de l'utilisateur, ou
 * au plafond de sécurité.
 *
 * Aucun DOM, aucun micro : testable sous `node --test tests/js/`.
 */

/** Plafond de sécurité d'une dictée : au-delà, on arrête et on le dit. */
export const DICTEE_DUREE_MAX_MS = 3 * 60 * 1000;

/** Une session plus courte que ceci n'a rien pu entendre : c'est une fin anormale. */
export const SESSION_ECLAIR_MS = 1000;

/** Fins éclair consécutives tolérées avant de conclure à une boucle. */
export const FINS_ECLAIR_MAX = 3;

/**
 * Faut-il relancer la reconnaissance que le navigateur vient de clore ?
 *
 * @param {{voulue: boolean, dureeTotaleMs: number, dureeSessionMs: number, finsEclair: number}} etat
 *   finsEclair = fins éclair consécutives AVANT celle-ci
 * @returns {{relancer: boolean, raison: (null|'utilisateur'|'plafond'|'boucle'), finsEclair: number}}
 */
export function decisionApresFin({ voulue, dureeTotaleMs, dureeSessionMs, finsEclair }) {
    if (!voulue) {
        return { relancer: false, raison: 'utilisateur', finsEclair: 0 };
    }
    if (dureeTotaleMs >= DICTEE_DUREE_MAX_MS) {
        return { relancer: false, raison: 'plafond', finsEclair: 0 };
    }
    // Une reconnaissance qui meurt aussitôt née (micro coupé, service en panne) : la
    // relancer sans fin ferait clignoter le micro sans jamais rien entendre.
    const eclairs = dureeSessionMs < SESSION_ECLAIR_MS ? finsEclair + 1 : 0;
    if (eclairs >= FINS_ECLAIR_MAX) {
        return { relancer: false, raison: 'boucle', finsEclair: eclairs };
    }
    return { relancer: true, raison: null, finsEclair: eclairs };
}

/** Durée écoulée au format « m:ss » (compteur à côté du micro). */
export function formaterDuree(ms) {
    const secondes = Math.max(0, Math.floor((Number(ms) || 0) / 1000));
    return `${Math.floor(secondes / 60)}:${String(secondes % 60).padStart(2, '0')}`;
}

/** Le texte de la zone de saisie : socle + transcript, tronqué à la limite du champ. */
export function assemblerTexte(socle, transcript, max) {
    const base = String(socle ?? '');
    const dicte = String(transcript ?? '');
    const separateur = base !== '' && dicte !== '' ? ' ' : '';
    return (base + separateur + dicte).slice(0, max);
}

/**
 * La partie DICTÉE de la zone de saisie : ce qui suit le texte présent avant la
 * dictée. Null si l'utilisateur a retouché ce socle — on ne sait plus alors ce qui a
 * été dit, et la finition ne doit toucher à rien.
 */
export function segmentDicte(valeur, socle) {
    const texte = String(valeur ?? '');
    const base = String(socle ?? '');
    if (!texte.startsWith(base)) return null;
    return texte.slice(base.length).trim();
}

/** Remplace le segment dicté par sa version mise au propre, socle intact. */
export function remplacerSegment(socle, propre, max) {
    return assemblerTexte(socle, String(propre ?? '').trim(), max);
}
