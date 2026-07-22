/*
 * Formatage des INSTANTS envoyés par le serveur (ISO-8601 avec décalage).
 *
 * SOURCE DE VÉRITÉ UNIQUE : l'horloge de référence de l'application (APP_TIMEZONE,
 * posée au démarrage par App\Kernel::boot). Le serveur la publie dans la page via
 * <meta name="app-timezone"> — le front ne fait que la suivre, il n'invente jamais
 * son propre fuseau. Serveur et navigateur affichent donc STRICTEMENT la même heure,
 * quelle que soit la machine qui lit la page.
 *
 * `medium` + `short` reproduit exactement le `format_datetime("medium", "short")`
 * de Twig utilisé en rendu serveur.
 */

/** Fuseau de référence publié par le serveur (null = réglage du poste, dernier recours). */
export function appTimezone() {
    return document.querySelector('meta[name="app-timezone"]')?.content || null;
}

/** Locale d'affichage : celle du document, repli sur le français. */
export function documentLocale() {
    return document.documentElement.lang || 'fr';
}

/**
 * @param {string|Date} instant  Date ISO-8601 (offset inclus) ou objet Date.
 * @param {string}      [locale] Locale BCP 47 ; par défaut celle du document.
 * @returns {string} Date formatée dans l'horloge de référence, '' si invalide.
 */
export function formatInstant(instant, locale = null) {
    const date = instant instanceof Date ? instant : new Date(instant);
    if (isNaN(date.getTime())) return '';

    const options = { dateStyle: 'medium', timeStyle: 'short' };
    const timeZone = appTimezone();
    if (timeZone) options.timeZone = timeZone;

    return new Intl.DateTimeFormat(locale || documentLocale(), options).format(date);
}
