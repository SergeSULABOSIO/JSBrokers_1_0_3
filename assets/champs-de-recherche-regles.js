/**
 * La règle du filet contre le remplissage automatique, sans effet de bord (chantier 16).
 * Installée par assets/champs-de-recherche.js ; testée par tests/js/champs-de-recherche.test.mjs.
 */

/** Le nom de l'animation qu'app.css attache à `:autofill` / `:-webkit-autofill`. */
export const NOM_ANIMATION = 'jsb-remplissage-auto';

/**
 * Effacer ? Seulement un champ de recherche marqué, NON vide, que le NAVIGATEUR vient de remplir
 * (son animation d'`:autofill`). Une valeur posée par l'application n'émet aucune animation.
 *
 * @param {{ nomAnimation: string, cible: { matches?: Function, value?: string, tagName?: string } | null }} evenement
 */
export function doitEffacer({ nomAnimation, cible }) {
    if (nomAnimation !== NOM_ANIMATION || !cible || typeof cible.matches !== 'function') {
        return false;
    }
    if (!cible.matches('input[data-champ-recherche]')) {
        return false;
    }

    return typeof cible.value === 'string' && cible.value !== '';
}
