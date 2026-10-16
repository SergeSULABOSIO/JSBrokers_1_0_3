/**
 * LA SEULE FONCTION D'ÉCHAPPEMENT DU NAVIGATEUR.
 *
 * Une donnée se pose d'abord par textContent. On n'échappe que là où l'on DOIT composer
 * du HTML autour d'elle (un nom en <strong> dans une confirmation, une infobulle à
 * plusieurs lignes). Les cinq caractères, tous : un échappement sans `"` ni `'` laisse
 * une donnée sortir d'un attribut (incident de la barre de recherche, 2026-10-16).
 * Treize copies locales, de couverture inégale, existaient avant celle-ci.
 */
const ENTITES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

/** @param {unknown} valeur  @returns {string} */
export function echapper(valeur) {
    return String(valeur ?? '').replace(/[&<>"']/g, (c) => ENTITES[c]);
}
