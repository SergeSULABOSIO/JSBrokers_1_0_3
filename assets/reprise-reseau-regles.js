/**
 * QUAND UNE LECTURE COUPÉE PAR LE RÉSEAU SE RETENTE — la règle, sans effet de bord.
 *
 * Constaté en production le 2026-10-19 : des requêtes coupées en plein vol
 * (`ERR_CONNECTION_RESET`, `ERR_CONNECTION_CLOSED`), sur des adresses différentes à chaque
 * chargement — un onglet restait vide, le suivi de Ket échouait. Le serveur n'a rien
 * refusé : la connexion a été coupée (hébergeur, réseau, antivirus qui inspecte le HTTPS).
 *
 * Une LECTURE se rejoue sans risque ; une écriture, jamais : un enregistrement ou une
 * suppression partis deux fois feraient deux fois l'opération. D'où quatre conditions :
 *
 *  1. méthode GET ou HEAD ;
 *  2. coupure RÉSEAU (`TypeError: Failed to fetch`), jamais une réponse du serveur — un
 *     404, un 500 sont des réponses, pas des coupures, et ne se retentent pas ;
 *  3. requête non annulée par l'appelant (`AbortError`, ou signal déjà levé) ;
 *  4. destination chez nous (même origine) ;
 *  5. le navigateur ne se sait pas HORS LIGNE : sinon la nouvelle tentative échouerait à
 *     coup sûr (signalé par Serge, 2026-10-25 — console remplie de tentatives vaines).
 *
 * Une seule nouvelle tentative, après un court délai. Couvert par
 * tests/js/reprise-reseau.test.mjs ; installé par assets/reprise-reseau.js.
 */

export const DELAI_AVANT_REPRISE_MS = 400;

const METHODES_DE_LECTURE = ['GET', 'HEAD'];

/** La méthode HTTP d'un appel `fetch(entree, options)` ; GET par défaut, comme `fetch`. */
export function methodeDe(entree, options) {
    const methode = options?.method ?? (typeof entree === 'object' && entree !== null && 'method' in entree ? entree.method : 'GET');

    return String(methode || 'GET').toUpperCase();
}

/** L'adresse d'un appel `fetch` : chaîne, `URL` ou `Request`. */
export function adresseDe(entree) {
    if (typeof entree === 'string') {
        return entree;
    }
    if (entree instanceof URL) {
        return entree.href;
    }

    return typeof entree?.url === 'string' ? entree.url : '';
}

/** Le signal d'annulation d'un appel `fetch`, s'il y en a un. */
export function signalDe(entree, options) {
    return options?.signal ?? (typeof entree === 'object' && entree !== null ? entree.signal : undefined) ?? null;
}

/**
 * @param {{ methode: string, erreur: unknown, signal: AbortSignal|null, adresse: string, origine: string, enLigne?: boolean }} appel
 */
export function doitReprendre({ methode, erreur, signal, adresse, origine, enLigne }) {
    if (!METHODES_DE_LECTURE.includes(methode) || enLigne === false) {
        return false;
    }
    // `fetch` lève une TypeError pour une coupure réseau ; une annulation lève une
    // DOMException « AbortError », qui n'est pas une TypeError.
    if (!(erreur instanceof TypeError) || signal?.aborted) {
        return false;
    }
    try {
        return new URL(adresse, origine).origin === origine;
    } catch {
        return false;
    }
}
