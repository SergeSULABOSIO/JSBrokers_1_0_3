/**
 * LE RAIL D'OUTILS DÉFILE EN DERNIER RECOURS.
 *
 * Le rail de la colonne 3 tient dans le cas courant (mesuré : 9 boutons à 595 px de haut).
 * Au-delà — fenêtre plus basse, plus de 4 actions —, sa zone défile (décision de Serge :
 * le défilement en dernier recours, jamais un bouton qui saute dans un menu selon la
 * hauteur). La molette, le doigt et le focus clavier le font nativement ; ce module ne fait
 * que DIRE qu'il reste quelque chose au-dessus ou au-dessous — flèches et fondus en
 * surcouche, qui ne prennent aucune place (leçon de chips-defilement.js : une flèche dans
 * le flux faisait défiler ce qui tenait).
 */

/**
 * @param {{haut: number, contenu: number, visible: number}} mesure scrollTop, scrollHeight,
 *        clientHeight de la zone qui défile.
 * @returns {{avant: boolean, apres: boolean}} reste-t-il à voir au-dessus, au-dessous.
 */
export function etatDuDefilement({ haut, contenu, visible }) {
    // 1 px de tolérance : les hauteurs sont fractionnaires, et un « presque zéro »
    // allumerait une flèche en permanence au bout de la course.
    const debordement = contenu - visible;
    if (debordement <= 1) return { avant: false, apres: false };

    return { avant: haut > 1, apres: debordement - haut > 1 };
}

export class DefilementRail {
    /**
     * @param {HTMLElement} rail     l'élément `role="toolbar"`, qui porte les classes d'état.
     * @param {HTMLElement} defilant la zone qui défile, à l'intérieur du rail.
     */
    constructor(rail, defilant) {
        this.rail = rail;
        this.defilant = defilant;
        this.boundMarquer = () => this.marquer();
        defilant.addEventListener('scroll', this.boundMarquer, { passive: true });
        this.observateur = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(this.boundMarquer);
        // La zone change de hauteur avec la fenêtre ; son contenu, avec la sélection (les
        // boutons d'action apparaissent et disparaissent).
        this.observateur?.observe(defilant);
        Array.from(defilant.children).forEach((enfant) => this.observateur?.observe(enfant));
        this.marquer();
    }

    marquer() {
        const { avant, apres } = etatDuDefilement({
            haut: this.defilant.scrollTop,
            contenu: this.defilant.scrollHeight,
            visible: this.defilant.clientHeight,
        });
        this.rail.classList.toggle('is-defile-avant', avant);
        this.rail.classList.toggle('is-defile-apres', apres);
    }

    /** Une « page » vers le haut (-1) ou le bas (1), en gardant un bouton de recouvrement. */
    avancer(sens) {
        const pas = Math.max(40, this.defilant.clientHeight - 40);
        // Rien ne glisse pour qui a demandé moins de mouvement.
        const reduit = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        this.defilant.scrollBy({ top: sens * pas, behavior: reduit ? 'auto' : 'smooth' });
    }

    detruire() {
        this.defilant.removeEventListener('scroll', this.boundMarquer);
        this.observateur?.disconnect();
    }
}
