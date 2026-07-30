/**
 * Logique PURE d'un menu flottant ancré — AUCUN accès au DOM, au viewport réel
 * ni au réseau. Séparée exprès pour être testable sous Node
 * (`node --test tests/js/`) sans bundler ni navigateur, sur le modèle de
 * `assistant-theme.js` et `assistant-chart-spec.js` : la coquille Stimulus
 * mesure (getBoundingClientRect, offsetWidth, window.inner*) et passe des
 * nombres ici.
 *
 * Deux besoins, deux fonctions :
 *
 *  - positionnerMenu() : où poser le menu. L'ancre est un simple rectangle, ce
 *    qui permet au MÊME code de servir un bouton (rectangle du bouton) et un
 *    clic droit (rectangle 0×0 au curseur) — c'est ce qui évite d'écrire deux
 *    fois la même géométrie.
 *  - indexApresTouche() : navigation clavier au sein d'une liste d'items.
 *    Réutilisée par le menu de bulle ET par la liste filtrée du picker de
 *    destinataires (↑/↓ dans les résultats).
 */

/** Marge minimale conservée entre le menu et les bords du viewport (px). */
export const MARGE_VIEWPORT = 8;

/**
 * Position `fixed` d'un menu ancré : aligné sur le bord DROIT de l'ancre (le
 * déclencheur vit au coin supérieur droit de sa bulle), posé dessous par
 * défaut, basculé au-dessus s'il déborderait en bas, puis écrêté aux marges du
 * viewport. L'écrêtage passe en dernier : il l'emporte toujours sur
 * l'alignement, donc le menu reste visible même dans une colonne étroite.
 *
 * @param {{
 *   ancre: {left: number, right: number, top: number, bottom: number},
 *   menu: {largeur: number, hauteur: number},
 *   viewport: {largeur: number, hauteur: number},
 *   marge?: number,
 * }} entrees
 * @returns {{left: number, top: number}}
 */
export function positionnerMenu({ ancre, menu, viewport, marge = MARGE_VIEWPORT }) {
    let left = ancre.right - menu.largeur;
    let top = ancre.bottom + 4;

    // Bascule au-dessus de l'ancre s'il n'y a pas la place dessous.
    if (top + menu.hauteur + marge > viewport.hauteur) {
        top = ancre.top - menu.hauteur - 4;
    }

    // Écrêtage final. Math.max en second : sur un menu plus grand que le
    // viewport, mieux vaut coller au bord haut/gauche (le début du contenu
    // reste lisible) que déborder du bord bas/droit.
    left = Math.max(marge, Math.min(left, viewport.largeur - menu.largeur - marge));
    top = Math.max(marge, Math.min(top, viewport.hauteur - menu.hauteur - marge));

    return { left, top };
}

/**
 * Index de l'item à focaliser après une touche de navigation, ou `null` si la
 * touche n'est pas gérée (l'appelant laisse alors filer l'événement).
 *
 * `indexCourant = -1` signifie « aucun item focalisé » : ↓ entre par le premier,
 * ↑ par le dernier. Le parcours boucle, conformément au pattern menu de l'ARIA
 * Authoring Practices.
 *
 * @param {string} touche `event.key`
 * @param {number} indexCourant index focalisé, ou -1
 * @param {number} nombre nombre d'items navigables
 * @returns {number|null}
 */
export function indexApresTouche(touche, indexCourant, nombre) {
    if (!Number.isInteger(nombre) || nombre <= 0) {
        return null;
    }

    const depart = Number.isInteger(indexCourant) ? indexCourant : -1;

    switch (touche) {
        case 'ArrowDown':
            // depart = -1 → 0 (entrée par le premier item).
            return (depart + 1) % nombre;
        case 'ArrowUp':
            // depart = -1 comme depart = 0 → dernier item (entrée par le bas).
            return depart <= 0 ? nombre - 1 : depart - 1;
        case 'Home':
            return 0;
        case 'End':
            return nombre - 1;
        default:
            return null;
    }
}
