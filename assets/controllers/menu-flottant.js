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
 * `alignement` choisit le bord de l'ancre sur lequel caler le menu. Le défaut reste
 * « droite », qui est la géométrie du menu de bulle pour qui cette fonction a été écrite ;
 * un déclencheur posé À GAUCHE de sa zone — le chip-sélecteur d'un filtre — se lit mieux
 * aligné à gauche, sans quoi le panneau part vers l'arrière du geste. L'écrêtage final
 * s'applique dans les deux cas, donc aucun alignement ne peut sortir du viewport.
 *
 * @param {{
 *   ancre: {left: number, right: number, top: number, bottom: number},
 *   menu: {largeur: number, hauteur: number},
 *   viewport: {largeur: number, hauteur: number},
 *   marge?: number,
 *   alignement?: 'gauche'|'droite',
 * }} entrees
 * @returns {{left: number, top: number}}
 */
export function positionnerMenu({ ancre, menu, viewport, marge = MARGE_VIEWPORT, alignement = 'droite' }) {
    let left = alignement === 'gauche' ? ancre.left : ancre.right - menu.largeur;
    let top = ancre.bottom + 4;

    // Bascule au-dessus de l'ancre s'il n'y a pas la place dessous — et seulement s'il y a plus
    // de place au-dessus : un menu plus haut que les deux côtés reste du côté le plus spacieux
    // (l'écrêtage le ramène dans l'écran), au lieu de remonter par-dessus sa ligne.
    const placeDessous = viewport.hauteur - marge - (ancre.bottom + 4);
    const placeDessus = ancre.top - 4 - marge;
    if (menu.hauteur > placeDessous && placeDessus > placeDessous) {
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

/**
 * Le curseur d'un clic droit, en ancre : un rectangle de 0×0. Avec `alignement: 'gauche'`, le
 * menu s'ouvre à droite et sous le curseur (basculé au-dessus faute de place).
 *
 * @param {{x: number, y: number}} point
 * @returns {{left: number, right: number, top: number, bottom: number}}
 */
export function ancreAuPoint({ x, y }) {
    return { left: x, right: x, top: y, bottom: y };
}

/**
 * Ce que fait une touche dans un menu OUVERT (coquille commune, menu-contextuel.js) :
 *   - `aller` : focaliser l'entrée `index` (flèches, Début/Fin — indexApresTouche) ;
 *   - `activer` : cliquer l'entrée focalisée (Entrée, Espace) ;
 *   - `fermer` : Échap — le focus revient à l'élément d'origine ;
 *   - `sortir` : Tab — le menu se ferme, le focus suit son cours ;
 *   - `null` : pas une touche du menu (les raccourcis propres à un écran restent à l'écran).
 *
 * @param {string} touche `event.key`
 * @param {number} indexCourant index de l'entrée focalisée, ou -1
 * @param {number} nombre nombre d'entrées navigables
 * @returns {{type: 'aller'|'activer', index: number}|{type: 'fermer'|'sortir'}|null}
 */
export function toucheDuMenu(touche, indexCourant, nombre) {
    if (touche === 'Escape' || touche === 'Esc') {
        return { type: 'fermer' };
    }
    if (touche === 'Tab') {
        return { type: 'sortir' };
    }
    if (touche === 'Enter' || touche === ' ') {
        return Number.isInteger(indexCourant) && indexCourant >= 0 && indexCourant < nombre
            ? { type: 'activer', index: indexCourant }
            : null;
    }
    const index = indexApresTouche(touche, indexCourant, nombre);

    return index === null ? null : { type: 'aller', index };
}

/** Délai pendant lequel un `contextmenu` suit une touche de menu (ms). */
export const DELAI_TOUCHE_DE_MENU = 1000;

/**
 * La touche Menu, ou Maj+F10 : les deux gestes du clavier qui ouvrent un menu contextuel.
 *
 * @param {string} touche `event.key`
 * @param {boolean} maj `event.shiftKey`
 * @returns {boolean}
 */
export function estToucheDeMenu(touche, maj) {
    return touche === 'ContextMenu' || (maj === true && touche === 'F10');
}

/**
 * Un `contextmenu` venu du CLAVIER (le menu s'ouvre alors sous l'élément focalisé, sur sa
 * première entrée) plutôt que de la souris (au curseur) :
 *   - Chromium le marque `button = -1` ;
 *   - Firefox ne le marque pas : il suit de près une touche de menu.
 * Un clic droit (`button = 2`) n'en est jamais un ; un Ctrl+clic sur Mac (`button = 0`, aucune
 * touche de menu) non plus.
 *
 * @param {number} bouton `event.button`
 * @param {number} msDepuisTouche temps écoulé depuis la dernière touche de menu (Infinity : aucune)
 * @returns {boolean}
 */
export function contextmenuAuClavier(bouton, msDepuisTouche) {
    if (bouton === 2) {
        return false;
    }

    return bouton === -1 || msDepuisTouche < DELAI_TOUCHE_DE_MENU;
}
