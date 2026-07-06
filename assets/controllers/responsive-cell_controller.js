import { Controller } from '@hotwired/stimulus';

/**
 * @class ResponsiveCellController
 * @extends Controller
 * @description Ajuste les détails affichés d'un élément de liste à la largeur disponible.
 *
 * Posé sur la cellule principale (`<td>`) d'une ligne de liste, ce contrôleur
 * observe sa largeur via un ResizeObserver et masque progressivement les items
 * de la ligne secondaire (cibles `secondaryItem`) EN PARTANT DU DERNIER tant
 * que le contenu déborde : l'ordre des `textes_secondaires` défini dans le
 * canvas (ListCanvasProvider) fait office de priorité d'affichage.
 *
 * Mesure : débordement réel du contenu — bord droit du dernier enfant visible
 * comparé au bord droit du bloc secondaire (moins une réserve), recalculé à
 * chaque notification, aucun instantané de largeur initiale. (`scrollWidth`
 * est inutilisable ici : il ne descend jamais sous `clientWidth`, la condition
 * serait toujours vraie.) Une cellule rendue masquée (onglet inactif, dialogue,
 * collection repliée) est ignorée tant que sa largeur vaut 0 ; le
 * ResizeObserver notifie le passage à la largeur réelle dès qu'elle devient
 * visible.
 *
 * Accessibilité (WCAG 1.3.1 / 1.4.10) : le masquage utilise `visually-hidden`
 * (l'information reste vocalisée par les lecteurs d'écran) et un indicateur
 * « … » (cible `moreIndicator`) apparaît avec un `title` listant les valeurs
 * masquées, également posé sur la cellule.
 *
 * Anti-oscillation : la classe CSS `.list-row-secondary` découple le bloc de
 * la largeur intrinsèque de la colonne (masquer un item ne re-modifie pas la
 * largeur du <td>), les notifications < 1 px sont ignorées, et une réserve
 * d'hystérésis (`reserveValue`, 8 px par défaut) est exigée pour qu'un item
 * soit considéré comme « tenant ».
 */
export default class extends Controller {
    /**
     * @property {HTMLElement} secondaryInfoTarget - Le bloc contenant la ligne secondaire.
     * @property {HTMLElement[]} secondaryItemTargets - Les items secondaires, dans l'ordre de priorité du canvas.
     * @property {HTMLElement} moreIndicatorTarget - L'indicateur « … » affiché quand des items sont masqués.
     */
    static targets = ["secondaryInfo", "secondaryItem", "moreIndicator"];

    /**
     * @property {number} reserveValue - Réserve d'hystérésis en pixels : marge exigée
     * en plus de la largeur du contenu pour considérer qu'il tient (anti-clignotement).
     */
    static values = { reserve: { type: Number, default: 8 } };

    connect() {
        /**
         * @property {number} lastWidth - Dernière largeur traitée, pour ignorer les
         * notifications insignifiantes (< 1 px).
         * @private
         */
        this.lastWidth = -1;

        /**
         * @property {ResizeObserver} resizeObserver - Observe la largeur de la cellule.
         * La spec garantit une notification initiale à l'observation : l'ajustement
         * au chargement est donc couvert sans appel manuel.
         * @private
         */
        this.resizeObserver = new ResizeObserver((entries) => this.onResize(entries));
        this.resizeObserver.observe(this.element);
    }

    disconnect() {
        this.resizeObserver.disconnect();
    }

    /**
     * Filtre les notifications insignifiantes puis planifie l'ajustement dans un
     * requestAnimationFrame — muter le DOM directement dans le callback du
     * ResizeObserver provoque l'erreur « ResizeObserver loop limit exceeded ».
     * @private
     */
    onResize(entries) {
        const width = entries[entries.length - 1].contentRect.width;
        if (Math.abs(width - this.lastWidth) < 1) {
            return;
        }
        this.lastWidth = width;
        requestAnimationFrame(() => this.adjust());
    }

    /**
     * Cœur de l'ajustement : réaffiche tout, puis masque les items depuis la fin
     * tant que le bloc secondaire déborde, et expose les valeurs masquées via
     * l'indicateur « … » et le `title` de la cellule.
     * @private
     */
    adjust() {
        // Cellule masquée (onglet inactif, dialogue fermé…) : ne rien toucher,
        // le ResizeObserver re-notifiera au passage à la largeur réelle.
        if (!this.hasSecondaryInfoTarget || this.element.clientWidth === 0) {
            return;
        }

        const box = this.secondaryInfoTarget;

        // 1. État de référence : tout réafficher (aucune mémoire de largeur).
        this.secondaryItemTargets.forEach((el) => el.classList.remove('visually-hidden'));
        if (this.hasMoreIndicatorTarget) {
            this.moreIndicatorTarget.classList.add('d-none');
        }

        // 2. Masquer depuis la FIN (priorité = ordre du canvas) tant que ça déborde.
        //    L'indicateur est affiché AVANT la première mesure de masquage pour que
        //    sa propre largeur soit comptée dans le calcul.
        const visibles = [...this.secondaryItemTargets];
        const masques = [];
        while (visibles.length > 0 && this.overflows(box)) {
            if (masques.length === 0 && this.hasMoreIndicatorTarget) {
                this.moreIndicatorTarget.classList.remove('d-none');
            }
            const item = visibles.pop();
            item.classList.add('visually-hidden');
            masques.unshift(item);
        }

        // 3. Affordance : les valeurs masquées restent consultables au survol
        //    (tooltip natif) via l'indicateur et la cellule elle-même.
        this.applyTitle(masques);
    }

    /**
     * Le contenu déborde-t-il du bloc secondaire ?
     * On compare le bord droit du DERNIER enfant visible (items en flux,
     * indicateur « … » inclus s'il est affiché) au bord droit du bloc, moins la
     * réserve d'hystérésis. NE PAS remplacer par `scrollWidth > clientWidth` :
     * `scrollWidth` ne descend jamais sous `clientWidth`, la soustraction de la
     * réserve rendrait la condition toujours vraie (tout serait masqué même
     * avec de la place libre).
     * @param {HTMLElement} box - Le bloc secondaire (`secondaryInfoTarget`).
     * @returns {boolean}
     * @private
     */
    overflows(box) {
        const visibles = [...box.children].filter((el) =>
            !el.classList.contains('visually-hidden') && !el.classList.contains('d-none')
        );
        if (visibles.length === 0) {
            return false;
        }
        const dernier = visibles[visibles.length - 1];
        return dernier.getBoundingClientRect().right > box.getBoundingClientRect().right - this.reserveValue;
    }

    /**
     * Pose (ou retire) le `title` listant les valeurs masquées sur l'indicateur
     * et sur la cellule.
     * @param {HTMLElement[]} masques - Les items masqués, dans l'ordre du canvas.
     * @private
     */
    applyTitle(masques) {
        if (masques.length > 0) {
            const texte = masques
                .map((el) => {
                    // Le séparateur (et l'icône) internes sont marqués aria-hidden :
                    // on les écarte pour un tooltip ne listant que les valeurs.
                    const clone = el.cloneNode(true);
                    clone.querySelectorAll('[aria-hidden]').forEach((n) => n.remove());
                    return clone.textContent.replace(/\s+/g, ' ').trim();
                })
                .join(' • ');
            if (this.hasMoreIndicatorTarget) {
                this.moreIndicatorTarget.title = texte;
            }
            this.element.title = texte;
        } else {
            this.element.removeAttribute('title');
        }
    }
}
