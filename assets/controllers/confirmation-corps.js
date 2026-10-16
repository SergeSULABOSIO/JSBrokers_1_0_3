/**
 * LE CORPS D'UNE CONFIRMATION : `corpsTexte` EN TEXTE, `body` EN HTML.
 *
 * ⚠ `body` reste du HTML pour ne rien changer aux appelants JS existants : un appelant
 * nouveau passe `corpsTexte`. (Renommer `body` en `corpsHtml` est prévu au commit suivant
 * du chantier d'échappement.)
 *
 * `corpsTexte` est posé par textContent : c'est ce que transporte `confirm-action`, dont
 * le corps vient d'un attribut de gabarit où Twig place des noms saisis — et que
 * Stimulus rend DÉCODÉ. Injecté en HTML, un nom de cabinet `<img onerror=…>`
 * s'exécutait chez l'agent de la console qui ouvrait la confirmation.
 *
 * `body` reste du HTML pour les appelants JS qui composent eux-mêmes un balisage
 * (un nom en <strong>), à charge pour eux d'en échapper les données.
 *
 * @param {{corpsTexte?: string, body?: string}|undefined} demande
 * @returns {{html: boolean, valeur: string}}
 */
export function contenuDuCorps({ corpsTexte, body } = {}) {
    if (typeof corpsTexte === 'string' && corpsTexte !== '') {
        return { html: false, valeur: corpsTexte };
    }
    if (typeof body === 'string' && body !== '') {
        return { html: true, valeur: body };
    }

    return { html: false, valeur: 'Êtes-vous sûr ?' };
}
