import { documentLocale } from './locale.js';

/*
 * Écriture des nombres dans la notation de la LANGUE ACTIVE — miroir exact du
 * filtre Twig `format_nombre` (App\Services\ServiceNombres) : anglais → 8,800 ;
 * français → 8 800. Toute évolution de la règle se fait des DEUX côtés.
 *
 * Volontairement fait à la main plutôt qu'avec Intl.NumberFormat : ce dernier
 * groupe le français avec une espace fine insécable (U+202F), qui ne correspond
 * pas à l'espace produite par number_format() côté serveur — le nombre rendu au
 * chargement et celui réécrit au rafraîchissement ne seraient pas identiques.
 */

/**
 * @param {number|string} valeur   Compteur entier (tokens).
 * @param {string}        [locale] Langue forcée ; par défaut celle du document.
 * @returns {string} Nombre groupé par milliers, '' si la valeur n'est pas un nombre.
 */
export function formatNombre(valeur, locale = null) {
    const n = Number(valeur);
    if (!Number.isFinite(n)) return '';

    const separateur = (locale || documentLocale()).toLowerCase().startsWith('en') ? ',' : ' ';
    const entier = Math.trunc(Math.abs(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, separateur);

    return (n < 0 ? '-' : '') + entier;
}
