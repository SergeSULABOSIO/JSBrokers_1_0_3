/**
 * Cœur PUR de la dictée vocale de Ket : transforme la liste des résultats de
 * l'API Web Speech en un seul texte.
 *
 * Deux navigateurs, deux comportements avec `continuous = true` :
 * - Chrome/Edge ordinateur : chaque résultat est un MORCEAU distinct de la
 *   phrase (« bonjour Cathy », « comment vas-tu ») → on les met bout à bout ;
 * - Chrome Android : chaque nouvelle hypothèse arrive comme un résultat de plus
 *   qui REPREND toute la phrase depuis le début (« ok », « ok merci »,
 *   « ok merci », « ok merci est-ce »…) → les coller recopiait chaque brouillon.
 *
 * Règle unique, sans détection du navigateur, par rapport au dernier segment
 * gardé : un texte qui le prolonge le remplace, un texte qu'il contient déjà
 * est ignoré, tout autre texte s'ajoute.
 */

/** Forme de comparaison : casse et espaces superflus ignorés. */
const cle = (texte) => texte.toLocaleLowerCase().replace(/\s+/g, ' ').trim();

/**
 * @param {Iterable<string>} resultats textes des résultats, dans l'ordre reçu
 * @returns {string} le transcript fusionné, segments séparés par un espace
 */
export function fusionnerTranscripts(resultats) {
    const segments = [];
    for (const brut of resultats ?? []) {
        const texte = String(brut ?? '').replace(/\s+/g, ' ').trim();
        if (texte === '') continue;

        const dernier = segments[segments.length - 1];
        if (dernier === undefined) {
            segments.push(texte);
        } else if (cle(texte).startsWith(cle(dernier))) {
            segments[segments.length - 1] = texte;
        } else if (!cle(dernier).startsWith(cle(texte))) {
            segments.push(texte);
        }
    }
    return segments.join(' ');
}
