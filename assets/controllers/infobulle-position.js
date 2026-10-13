/**
 * OÙ POSER L'INFOBULLE SOMBRE — règles pures (testées en node).
 *
 * AU CURSEUR, la règle des infobulles du tableau de bord (#db-piste-tip, #db-renew-tip,
 * commit-tip) : en haut à gauche du pointeur, à 10 px, et de l'autre côté du pointeur
 * quand le bord manque. L'infobulle suit la souris sans jamais la masquer.
 *
 * ⚠ ET TOUJOURS DANS L'ÉCRAN. La règle d'origine ne regardait que les bords haut et gauche :
 * une infobulle haute, appelée près du bas de l'écran, passait SOUS le pointeur puis
 * débordait en bas — sa fin ne se lisait pas. On la ramène dans l'écran sur les quatre
 * bords ; si elle est plus haute que l'écran, c'est son DÉBUT qui reste visible.
 *
 * SOUS L'ÉLÉMENT, pour le clavier : un élément atteint au Tab n'a pas de pointeur. On
 * l'aligne sur le bord gauche de l'élément, en dessous — au-dessus s'il manque de place.
 */

const DECALAGE = 10;
const MARGE = 8;

/** Ramène une coordonnée dans [MARGE, limite − taille − MARGE] ; le début l'emporte. */
function dansLEcran(valeur, taille, limite) {
    return Math.max(MARGE, Math.min(valeur, limite - taille - MARGE));
}

/**
 * @param {{x: number, y: number}} pointeur
 * @param {{largeur: number, hauteur: number}} bulle
 * @param {{largeur: number, hauteur: number}} ecran
 * @returns {{left: number, top: number}}
 */
export function positionAuCurseur(pointeur, bulle, ecran) {
    let left = pointeur.x - bulle.largeur - DECALAGE;
    let top = pointeur.y - bulle.hauteur - DECALAGE;
    if (left < MARGE) left = pointeur.x + DECALAGE;
    if (top < MARGE) top = pointeur.y + DECALAGE;

    return { left: dansLEcran(left, bulle.largeur, ecran.largeur), top: dansLEcran(top, bulle.hauteur, ecran.hauteur) };
}

/**
 * @param {{left: number, top: number, bottom: number}} cadre  getBoundingClientRect() de l'élément
 * @param {{largeur: number, hauteur: number}} bulle
 * @param {{largeur: number, hauteur: number}} ecran
 * @returns {{left: number, top: number}}
 */
export function positionSousLElement(cadre, bulle, ecran) {
    let top = cadre.bottom + MARGE;
    if (top + bulle.hauteur > ecran.hauteur - MARGE) top = cadre.top - bulle.hauteur - MARGE;

    return { left: dansLEcran(cadre.left, bulle.largeur, ecran.largeur), top: dansLEcran(top, bulle.hauteur, ecran.hauteur) };
}
