/**
 * Normalisation des textes de recherche des pickers — module PUR, testable sous
 * Node (`node --test tests/js/`).
 *
 * Besoin : sur un carnet d'adresses francophone, taper « echeance » doit trouver
 * « Échéance », et « SONAS » doit trouver « Sonas ». Sans cela, le filtrage d'un
 * picker de plusieurs centaines de lignes oblige à connaître l'orthographe
 * exacte, accents compris.
 *
 * PROPRIÉTÉ ESSENTIELLE — la normalisation préserve la LONGUEUR : chaque
 * caractère est normalisé isolément, donc `normaliserRecherche(s)[i]` correspond
 * toujours à `s[i]`. C'est ce qui permet au surlignage de chercher les positions
 * sur le texte normalisé et de découper le texte D'ORIGINE avec les mêmes index.
 * Une normalisation NFD globale casserait cette correspondance ('é' devenant
 * 'e' + accent combinant, soit deux caractères avant suppression).
 */

/** Marques diacritiques Unicode, retirées après décomposition NFD. */
const DIACRITIQUES = /\p{Diacritic}/gu;

/**
 * Minuscules, sans accents, longueur inchangée.
 *
 * @param {*} texte
 * @returns {string}
 */
export function normaliserRecherche(texte) {
    const source = String(texte ?? '').toLowerCase();
    let resultat = '';
    for (const caractere of source) {
        const sansAccent = caractere.normalize('NFD').replace(DIACRITIQUES, '');
        // Un caractère qui ne se réduit pas à un seul signe (ligature, emoji,
        // signe entièrement diacritique) est conservé tel quel : la longueur
        // reste égale, quitte à ne pas être « déplié ».
        resultat += sansAccent.length === 1 ? sansAccent : caractere;
    }

    return resultat;
}

/**
 * Une ligne correspond-elle à la recherche ? Recherche par MOTS : tous les
 * termes doivent être présents, dans n'importe quel ordre — « sinistre sonas »
 * trouve le contact sinistres de SONAS sans dépendre de l'ordre de saisie.
 *
 * @param {string} texteLigne texte indexé de la ligne (déjà concaténé)
 * @param {string} recherche saisie brute de l'utilisateur
 * @returns {boolean}
 */
export function ligneCorrespond(texteLigne, recherche) {
    const termes = normaliserRecherche(recherche).split(/\s+/).filter((t) => t !== '');
    if (termes.length === 0) {
        return true;
    }
    const cible = normaliserRecherche(texteLigne);

    return termes.every((terme) => cible.includes(terme));
}
