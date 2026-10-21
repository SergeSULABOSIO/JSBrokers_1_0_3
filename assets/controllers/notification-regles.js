/**
 * LES MESSAGES (toasts) — règles pures (chantier 2).
 *
 * Tous les toasts portaient `role="alert"` (annonce IMMÉDIATE, qui coupe la parole du lecteur
 * d'écran), à l'intérieur d'un conteneur lui-même `aria-live` : deux régions imbriquées, et un
 * « Enregistrement réussi » annoncé comme une alarme. Désormais (exigences de Serge,
 * 2026-10-20) : une région `role="status"` (polie) pour l'information, une région
 * `role="alert"` réservée aux ERREURS — toutes deux présentes dès le chargement de la page ;
 * et une erreur ne disparaît pas seule : on ne laisse pas filer ce qu'il faut lire.
 */

/**
 * La région où insérer un message de ce type.
 * @param {string|undefined} type  success | error | info | warning
 * @returns {'alerte'|'statut'}
 */
export function regionDuMessage(type) {
    return type === 'error' ? 'alerte' : 'statut';
}

/**
 * Combien de temps le message reste affiché ; null = jusqu'à ce que l'utilisateur le ferme.
 * La pause au survol et au focus est assurée par le Toast de Bootstrap.
 * @param {string|undefined} type
 * @param {boolean} avecAction  le message propose un geste : le temps de lire PUIS d'agir
 * @returns {number|null}
 */
export function delaiDuMessage(type, avecAction) {
    if (type === 'error') return null;
    return avecAction ? 20000 : 10000;
}

/**
 * Au plus autant d'ERREURS à l'écran : elles ne partent plus seules, et sans plafond elles
 * s'empilaient hors de l'écran, par-dessus les dialogues (revue du chantier 2).
 */
export const PLAFOND_DES_ERREURS = 3;

/**
 * Ce message est-il déjà affiché, à l'identique ? Une même panne répétée (réseau coupé,
 * relance toutes les 5 minutes) ne doit pas empiler le même texte.
 * @param {{type: string, texte: string}} message
 * @param {Array<{type: string, texte: string}>} affiches
 */
export function messageEnDouble(message, affiches) {
    return affiches.some((a) => a.type === message.type && a.texte === message.texte);
}

/**
 * Combien d'erreurs déjà affichées retirer (les plus anciennes) avant d'en montrer une de plus.
 * @param {number} combienDejaAffichees
 */
export function erreursARetirer(combienDejaAffichees) {
    return Math.max(0, combienDejaAffichees + 1 - PLAFOND_DES_ERREURS);
}
