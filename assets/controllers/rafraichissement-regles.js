/**
 * QUAND UNE MISE À JOUR AUTOMATIQUE A UN SENS — la règle, sans effet de bord (testée en node).
 *
 * Signalé par Serge (2026-10-25, console de production) : connexion coupée, le tableau de bord
 * continuait de se mettre à jour en arrière-plan ; chaque requête échouait, était retentée,
 * échouait encore, et les blocs affichaient « Erreur de mise à jour ».
 *
 * Une mise à jour AUTOMATIQUE (minuterie, pas un geste de l'utilisateur) n'a de sens que si :
 *   - le navigateur n'est pas HORS LIGNE (il le sait : `navigator.onLine === false`) ;
 *   - la page est VISIBLE (personne ne lit un bloc dans une fenêtre réduite) ;
 *   - le réseau n'est pas EN PAUSE : Wi-Fi connecté mais Internet muet (le navigateur se croit
 *     en ligne), une coupure CONFIRMÉE — nouvelle tentative comprise — suspend les mises à jour
 *     un temps qui double à chaque coupure (pauseApresEchecs) ; un succès la lève.
 * Un état inconnu ne bloque rien. Une mise à jour sautée se rattrape (rafraichissement-de-fond.js).
 *
 * @param {{enLigne?: boolean, visible?: boolean, enPause?: boolean}} poste
 * @returns {boolean}
 */
export function rafraichissementPermis({ enLigne, visible, enPause } = {}) {
    return enLigne !== false && visible !== false && enPause !== true;
}

/** Première pause après une coupure confirmée (ms), puis elle double. */
export const PAUSE_INITIALE_MS = 60000;
/** La pause ne dépasse jamais dix minutes. */
export const PAUSE_MAXIMALE_MS = 600000;

/**
 * La pause des mises à jour automatiques après `echecs` coupures confirmées d'affilée.
 *
 * @param {number} echecs
 * @returns {number} ms
 */
export function pauseApresEchecs(echecs) {
    if (!(echecs > 0)) {
        return 0;
    }

    return Math.min(PAUSE_INITIALE_MS * 2 ** (echecs - 1), PAUSE_MAXIMALE_MS);
}
