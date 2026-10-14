/**
 * LES COOKIES DU NAVIGATEUR, écrits et lus d'UNE seule façon.
 *
 * Deux manières cohabitaient : le `saveCookie` historique (base_controller.js, recopié dans
 * app.js) PRÉFIXAIT la valeur de « 9111986 » et écrivait une date d'expiration invalide
 * (`toUTCString` sans parenthèses : le cookie mourait avec la session) ; la vue Cartes avait
 * dû écrire les siens à côté, pour que le serveur lise « cartes » et non « 9111986cartes ».
 * L'historique n'avait plus aucun appelant : il est retiré, et ceci reste seul.
 *
 * Fonctions PURES : elles fabriquent ou lisent une chaîne `document.cookie`, sans y toucher —
 * c'est ce qui permet à un test de les tenir.
 */

/** Le préfixe que l'ancien `saveCookie` ajoutait à la valeur. */
export const PREFIXE_HISTORIQUE = '9111986';

/** Un an, en secondes. */
export const UN_AN = 31536000;

/**
 * La chaîne à affecter à `document.cookie` : valeur encodée, tout le site, `SameSite=Lax`.
 *
 * @param {string} nom
 * @param {string|number|null|undefined} valeur
 * @param {{maxAge?: number, path?: string}} [options]
 * @returns {string}
 */
export function cookieAEcrire(nom, valeur, { maxAge = UN_AN, path = '/' } = {}) {
    return `${nom}=${encodeURIComponent(String(valeur ?? ''))}; path=${path}; max-age=${maxAge}; SameSite=Lax`;
}

/**
 * La valeur du cookie `nom` dans une chaîne `document.cookie`, ou null s'il est absent.
 *
 * REPLI : une valeur écrite par l'ancien `saveCookie` porte encore le préfixe historique
 * jusqu'à expiration — il est retiré à la lecture.
 *
 * @param {string|null|undefined} cookies le contenu de `document.cookie`
 * @param {string} nom
 * @returns {string|null}
 */
export function lireCookie(cookies, nom) {
    const trouve = String(cookies ?? '').split(';').map((c) => c.trim()).find((c) => c.startsWith(`${nom}=`));
    if (trouve === undefined) {
        return null;
    }
    const brute = trouve.slice(nom.length + 1);
    let valeur;
    try {
        valeur = decodeURIComponent(brute);
    } catch {
        valeur = brute; // un % orphelin : on rend ce qui est écrit plutôt que rien
    }

    return valeur.startsWith(PREFIXE_HISTORIQUE) ? valeur.slice(PREFIXE_HISTORIQUE.length) : valeur;
}
