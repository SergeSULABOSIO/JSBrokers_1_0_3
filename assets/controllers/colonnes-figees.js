/**
 * LES LARGEURS DES COLONNES, FIGÉES LE TEMPS D'UN CHARGEMENT.
 *
 * Les colonnes de montants d'une liste prennent la largeur de leur contenu
 * (`.th-numeric--contenu`). Pendant une recherche, les lignes laissent place à un squelette
 * qui n'en a aucun : les colonnes s'effondraient sur leur entête puis revenaient — la liste
 * sautait à chaque recherche, page ou rafraîchissement. On fige les largeurs AVANT le
 * squelette, on les libère au retour des lignes.
 *
 * La décision vit ici, hors du contrôleur, pour être éprouvée sans DOM.
 */

/**
 * @param {number[]} largeurs - largeur affichée de chaque entête (getBoundingClientRect).
 * @param {boolean} dejaFigees - un chargement précédent les a déjà figées.
 * @returns {number[]|null} les largeurs à poser, en pixels ; null = ne rien figer.
 */
export function largeursAFiger(largeurs, dejaFigees) {
    // Un second chargement pendant le squelette mesurerait le squelette lui-même.
    if (dejaFigees) return null;
    // Liste masquée (état vide) : des largeurs nulles écraseraient le tableau au retour.
    if (largeurs.length === 0 || largeurs.every((l) => l <= 0)) return null;

    return largeurs.map((l) => Math.round(l));
}
