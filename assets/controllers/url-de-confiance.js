/**
 * UNE URL RELUE DU STOCKAGE NE SORT PAS DE CHEZ NOUS.
 *
 * L'espace de travail se reconstitue au rechargement depuis le localStorage : chaque
 * onglet y garde l'URL de son contenu (`loadUrl`, `sourceUrl`), que l'on refetche puis
 * injecte en innerHTML. Une URL réécrite vers un autre site ferait injecter SON HTML
 * dans la page, à chaque rechargement. On n'accepte donc que la même origine.
 */

/** L'URL si elle pointe sur notre origine, sinon null. */
export function urlDeMemeOrigine(url, origine = globalThis.location?.origin) {
    if (typeof url !== 'string' || url.trim() === '' || !origine) return null;
    let cible;
    try {
        cible = new URL(url, origine);
    } catch {
        return null;
    }
    if (cible.origin !== origine) return null;
    if (cible.protocol !== 'http:' && cible.protocol !== 'https:') return null;

    return cible.pathname + cible.search + cible.hash;
}
