/**
 * LE CACHE PERSISTANT DES ICÔNES — UNE SEULE PORTE.
 *
 * Le circuit d'icônes garde chaque SVG reçu du serveur dans le localStorage
 * (`jsb-icon-v1::<nom>::<taille>`), puis l'injecte en innerHTML à chaque rechargement.
 * Ce stockage est relu SANS repasser par le serveur : une entrée réécrite (extension,
 * script tiers, ancienne faille) s'exécuterait à chaque page, durablement. On ne relit
 * donc qu'un SVG, et un SVG inerte ; une entrée douteuse est effacée et l'icône
 * redemandée au serveur.
 *
 * Trois lecteurs (cerveau, et les deux circuits de bordereau-analysis) passent par ici.
 */

const PREFIXE = 'jsb-icon-v1::';

/** Ce qu'un pictogramme n'a aucune raison de porter. */
const INTERDIT = /<\s*\/?\s*(script|foreignobject|iframe|object|embed)\b|\son[a-z]+\s*=|javascript:/i;

/** Un SVG, et rien qui s'exécute. */
export function estIconeSvg(html) {
    return typeof html === 'string' && /^\s*<svg[\s>]/i.test(html) && !INTERDIT.test(html);
}

const cle = (nom, taille) => `${PREFIXE}${nom}::${taille}`;

/** L'icône mémorisée, ou null — une entrée qui n'est pas un SVG inerte est effacée. */
export function lireIconeMemorisee(nom, taille) {
    let html = null;
    try {
        html = globalThis.localStorage?.getItem(cle(nom, taille)) ?? null;
    } catch { return null; /* stockage indisponible : on retombe sur le serveur */ }
    if (html === null) return null;
    if (estIconeSvg(html)) return html;
    try { globalThis.localStorage.removeItem(cle(nom, taille)); } catch { /* no-op */ }
    return null;
}

/** Mémorise un SVG reçu du serveur ; un commentaire d'erreur ou autre chose n'est pas gardé. */
export function memoriserIcone(nom, taille, html) {
    if (!estIconeSvg(html)) return;
    try { globalThis.localStorage?.setItem(cle(nom, taille), html); } catch { /* quota, mode privé */ }
}
