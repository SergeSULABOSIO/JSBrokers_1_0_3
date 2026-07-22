import { documentLocale } from './locale.js';

/*
 * Écriture des nombres dans la notation de la LANGUE ACTIVE — miroir exact du
 * filtre Twig `format_nombre` (App\Services\ServiceNombres) : anglais → 8,800 et
 * 10.00 ; français → 8 800 et 10,00. Vaut pour les compteurs comme pour les
 * montants. Toute évolution de la règle se fait des DEUX côtés.
 *
 * Volontairement fait à la main plutôt qu'avec Intl.NumberFormat : ce dernier
 * groupe le français avec une espace fine insécable (U+202F), qui ne correspond
 * pas à l'espace produite par number_format() côté serveur — le nombre rendu au
 * chargement et celui réécrit au rafraîchissement ne seraient pas identiques.
 */

/**
 * Signature calquée sur celle du service PHP (valeur, décimales, langue).
 *
 * @param {number|string} valeur      Compteur de tokens ou montant.
 * @param {number}        [decimales] Nombre de décimales (0 pour un compteur).
 * @param {string}        [locale]    Langue forcée ; par défaut celle du document.
 * @returns {string} Nombre formaté, '' si la valeur n'est pas un nombre.
 */
export function formatNombre(valeur, decimales = 0, locale = null) {
    const n = Number(valeur);
    if (!Number.isFinite(n)) return '';

    const anglais = (locale || documentLocale()).toLowerCase().startsWith('en');
    const milliers = anglais ? ',' : ' ';
    const decimale = anglais ? '.' : ',';

    // toFixed() arrondit comme number_format() côté serveur.
    const [entier, fraction] = Math.abs(n).toFixed(decimales).split('.');
    const groupe = entier.replace(/\B(?=(\d{3})+(?!\d))/g, milliers);

    return (n < 0 ? '-' : '') + groupe + (fraction ? decimale + fraction : '');
}
