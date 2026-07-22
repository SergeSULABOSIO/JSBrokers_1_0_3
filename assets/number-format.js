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

    const en = anglais(locale || documentLocale());
    const milliers = en ? ',' : ' ';
    const decimale = en ? '.' : ',';

    // toFixed() arrondit comme number_format() côté serveur.
    const [entier, fraction] = Math.abs(n).toFixed(decimales).split('.');
    const groupe = entier.replace(/\B(?=(\d{3})+(?!\d))/g, milliers);

    return (n < 0 ? '-' : '') + groupe + (fraction ? decimale + fraction : '');
}

/**
 * Montant symbole compris, placé selon la langue : « 10,00 $ » en français,
 * « $10.00 » en anglais. Miroir de ServiceNombres::formatMontant().
 *
 * @param {number|string} valeur
 * @param {number}        [decimales]
 * @param {string}        [locale]
 * @param {string}        [symbole]
 * @returns {string} '' si la valeur n'est pas un nombre.
 */
export function formatMontant(valeur, decimales = 2, locale = null, symbole = '$') {
    const nombre = formatNombre(valeur, decimales, locale);
    if (nombre === '') return '';

    return anglais(locale || documentLocale()) ? symbole + nombre : nombre + ' ' + symbole;
}

/** La langue demande-t-elle la notation anglaise (1,000.00 et $ en préfixe) ? */
function anglais(locale) {
    return locale.toLowerCase().startsWith('en');
}
