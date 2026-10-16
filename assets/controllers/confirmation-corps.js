/**
 * LE CORPS D'UNE CONFIRMATION : DU TEXTE, SAUF `corpsHtml` EXPLICITE.
 *
 * `body` et `corpsTexte` sont posés par textContent. Le corps de `confirm-action` vient
 * d'un attribut de gabarit où Twig place des noms saisis — et que Stimulus rend DÉCODÉ :
 * injecté en HTML, un nom de cabinet `<img onerror=…>` s'exécutait chez l'agent de la
 * console qui ouvrait la confirmation (correctif d'urgence du 2026-10-16).
 *
 * `corpsHtml` est la seule porte du balisage, pour un appelant JS qui compose lui-même
 * un corps (un nom en <strong>) — à charge pour lui d'en échapper chaque donnée avec
 * `echapper` (echapper.js). Un appelant qui n'y pense pas obtient du texte, pas une faille.
 *
 * @param {{corpsHtml?: string, corpsTexte?: string, body?: string}|undefined} demande
 * @returns {{html: boolean, valeur: string}}
 */
export function contenuDuCorps({ corpsHtml, corpsTexte, body } = {}) {
    if (typeof corpsHtml === 'string' && corpsHtml !== '') {
        return { html: true, valeur: corpsHtml };
    }
    for (const texte of [corpsTexte, body]) {
        if (typeof texte === 'string' && texte !== '') {
            return { html: false, valeur: texte };
        }
    }

    return { html: false, valeur: 'Êtes-vous sûr ?' };
}
