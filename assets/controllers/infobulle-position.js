/**
 * OÙ POSER L'INFOBULLE SOMBRE — règles pures (testées en node).
 *
 * AU CURSEUR, la règle reprise des anciennes infobulles du tableau de bord : en haut à
 * gauche du pointeur, tout contre lui, et de l'autre côté du pointeur
 * quand le bord manque. Calculée au POINT D'ENTRÉE du pointeur, une fois : l'infobulle unique
 * ne suit plus la souris (chantier 3 — elle doit pouvoir être survolée, WCAG 1.4.13).
 *
 * ⚠ ET TOUJOURS DANS L'ÉCRAN. La règle d'origine ne regardait que les bords haut et gauche :
 * une infobulle haute, appelée près du bas de l'écran, passait SOUS le pointeur puis
 * débordait en bas — sa fin ne se lisait pas. On la ramène dans l'écran sur les quatre
 * bords ; si elle est plus haute que l'écran, c'est son DÉBUT qui reste visible.
 *
 * SOUS L'ÉLÉMENT, pour le clavier : un élément atteint au Tab n'a pas de pointeur. On
 * l'aligne sur le bord gauche de l'élément, en dessous — au-dessus s'il manque de place.
 */

// 4 px, plus 10 (revue du chantier 3) : la bulle est SURVOLABLE, et le chemin du pointeur vers
// elle ne doit traverser aucun autre élément porteur d'infobulle (une carte voisine la reprendrait).
const DECALAGE = 4;
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

/**
 * SOUS UN BOUTON DE BARRE D'ACTIONS (décision de Serge, chantier 3) : centrée sous lui, au-dessus
 * s'il manque de place — au curseur, la bulle masquerait les boutons voisins.
 *
 * @param {{left: number, top: number, bottom: number, width: number}} cadre
 * @param {{largeur: number, hauteur: number}} bulle
 * @param {{largeur: number, hauteur: number}} ecran
 * @returns {{left: number, top: number}}
 */
export function positionCentreeSous(cadre, bulle, ecran) {
    let top = cadre.bottom + MARGE;
    if (top + bulle.hauteur > ecran.hauteur - MARGE) top = cadre.top - bulle.hauteur - MARGE;
    const left = cadre.left + cadre.width / 2 - bulle.largeur / 2;

    return { left: dansLEcran(left, bulle.largeur, ecran.largeur), top: dansLEcran(top, bulle.hauteur, ecran.hauteur) };
}

/**
 * À DROITE D'UN BOUTON DU RAIL D'OUTILS (décision de Serge, 2026-10-28) : centrée sur la hauteur
 * du bouton, à sa droite — à sa gauche s'il manque de place. Dessous, dans un rail vertical,
 * elle recouvrait le bouton suivant.
 *
 * @param {{left: number, right: number, top: number, height: number}} cadre
 * @param {{largeur: number, hauteur: number}} bulle
 * @param {{largeur: number, hauteur: number}} ecran
 * @returns {{left: number, top: number}}
 */
export function positionADroite(cadre, bulle, ecran) {
    let left = cadre.right + MARGE;
    if (left + bulle.largeur > ecran.largeur - MARGE) left = cadre.left - bulle.largeur - MARGE;
    const top = cadre.top + cadre.height / 2 - bulle.hauteur / 2;

    return { left: dansLEcran(left, bulle.largeur, ecran.largeur), top: dansLEcran(top, bulle.hauteur, ecran.hauteur) };
}
