/**
 * LA TOUCHE ET LE BOUTON VISÉ, dans une barre d'outils (pattern Toolbar de l'APG).
 *
 * Une barre HORIZONTALE (dialogue d'entité) se parcourt par ← et → ; une barre VERTICALE
 * (le rail d'outils de la colonne 3, `aria-orientation="vertical"`) par ↑ et ↓. Début et
 * Fin vont aux extrémités. La décision vit ici, hors du DOM, pour être éprouvée seule.
 */

/**
 * @param {string} touche `event.key`
 * @param {number} index position du bouton focalisé parmi les arrêts clavier
 * @param {number} nombre nombre d'arrêts clavier
 * @param {boolean} vertical la barre déclare `aria-orientation="vertical"`
 * @returns {number|null} l'index visé ; null = la touche ne concerne pas la barre
 */
export function indexVise(touche, index, nombre, vertical) {
    if (nombre <= 0 || index < 0) return null;
    const suivante = vertical ? 'ArrowDown' : 'ArrowRight';
    const precedente = vertical ? 'ArrowUp' : 'ArrowLeft';
    switch (touche) {
        case suivante: return (index + 1) % nombre;
        case precedente: return (index - 1 + nombre) % nombre;
        case 'Home': return 0;
        case 'End': return nombre - 1;
        default: return null;
    }
}
