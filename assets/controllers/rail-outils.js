/**
 * LES OUTILS DE RUBRIQUE AU RAIL, AUTANT QU'IL EN TIENT.
 *
 * Sélectionner tout, Actualiser, Paramètres et Quitter : AUSSITÔT qu'un espace se dégage dans le
 * rail d'outils de la colonne 3, les outils cachés y reviennent, autant que possible, dans
 * l'ordre ; ceux qui n'ont pas de place restent dans « Plus », qui disparaît quand tous tiennent
 * (décision de Serge, 2026-10-28). Sans sélection, le rail ne portait qu'« Ajouter » et un « ⋮ » :
 * beaucoup de place vide, et quatre outils cachés pour rien.
 *
 * La décision vit ici, hors du DOM. Les hauteurs viennent du contrôleur, MESURÉES puis gardées,
 * et `base` exclut tout ce que la décision fait varier (les outils, « Plus ») : elle ne peut donc
 * pas osciller.
 */

/**
 * @param {{disponible: number, base: number, bouton: number, nombre: number}} m
 *        `disponible` : hauteur utile du rail ; `base` : hauteur de son contenu SANS les outils ni
 *        « Plus » (le séparateur du pied compris) ; `bouton` : un bouton et son écart ;
 *        `nombre` : le nombre d'outils.
 * @returns {number|null} combien d'outils au rail (les premiers, dans l'ordre) ; null = rail pas
 *          encore mesurable (onglet masqué).
 */
export function outilsAuRail({ disponible, base, bouton, nombre }) {
    if (disponible <= 0 || bouton <= 0) return null;

    // 1 px de tolérance : les hauteurs sont fractionnaires.
    const tient = (n, avecPlus) => base + n * bouton + (avecPlus ? bouton : 0) <= disponible + 1;
    if (tient(nombre, false)) return nombre;
    for (let n = nombre - 1; n > 0; n -= 1) {
        if (tient(n, true)) return n;
    }

    return 0;
}
