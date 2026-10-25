import { pauseApresEchecs, rafraichissementPermis } from './rafraichissement-regles.js';

/**
 * LES MISES À JOUR AUTOMATIQUES : elles attendent le réseau et la page, puis se rattrapent.
 *
 * `setInterval(aChaqueIntervalle(rafraichir), ms)` au lieu de `setInterval(rafraichir, ms)` :
 *   - hors ligne, page cachée, ou réseau EN PAUSE (coupures confirmées) : la mise à jour ne part
 *     pas (rafraichissement-regles.js) — aucune requête vouée à l'échec, aucune « Erreur de mise
 *     à jour » ;
 *   - elle est notée EN RETARD, et partira UNE fois au retour du réseau ou de la page.
 * Les fonctions de mise à jour sont celles du module (toujours les mêmes) : l'ensemble des
 * retards reste borné, sans écouteur par minuterie.
 *
 * La pause : `reprise-reseau.js` signale chaque coupure CONFIRMÉE (nouvelle tentative comprise)
 * et chaque réponse reçue ; la pause double à chaque coupure, un succès la lève.
 */

let echecsDAffilee = 0;
let pauseJusqua = 0;

/** Une lecture coupée malgré la nouvelle tentative : le réseau est muet. */
export function noterCoupureConfirmee() {
    echecsDAffilee += 1;
    pauseJusqua = Date.now() + pauseApresEchecs(echecsDAffilee);
}

/** Une réponse est arrivée : le réseau répond, la pause est levée. */
export function noterReponseRecue() {
    if (echecsDAffilee === 0) return;
    echecsDAffilee = 0;
    pauseJusqua = 0;
    rattraper();
}

/** L'état du poste, lu au moment du tic. */
export function etatDuPoste() {
    return {
        enLigne: typeof navigator !== 'undefined' && 'onLine' in navigator ? navigator.onLine : undefined,
        visible: typeof document !== 'undefined' ? document.visibilityState !== 'hidden' : undefined,
        enPause: Date.now() < pauseJusqua,
    };
}

const enRetard = new Set();

/**
 * @param {function(): void} rafraichir la mise à jour (fonction du module, toujours la même)
 * @returns {function(): void} le tic à passer à setInterval
 */
export function aChaqueIntervalle(rafraichir) {
    return () => {
        if (!rafraichissementPermis(etatDuPoste())) {
            enRetard.add(rafraichir);
            return;
        }
        enRetard.delete(rafraichir);
        rafraichir();
    };
}

function rattraper() {
    if (enRetard.size === 0 || !rafraichissementPermis(etatDuPoste())) return;
    const enAttente = [...enRetard];
    enRetard.clear();
    enAttente.forEach((rafraichir) => rafraichir());
}

if (typeof window !== 'undefined' && typeof document !== 'undefined' && !window.__rafraichissementDeFondInstalle) {
    window.__rafraichissementDeFondInstalle = true;
    window.addEventListener('online', rattraper);
    document.addEventListener('visibilitychange', rattraper);
}
