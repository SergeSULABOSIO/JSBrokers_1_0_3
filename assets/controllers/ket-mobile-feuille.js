/**
 * Logique PURE de la coquille mobile « mode Ket » — AUCUN accès au DOM ni au
 * viewport. Séparée exprès pour être testable sous Node (`node --test
 * tests/js/`), sur le modèle de `workspace-col2.js` et `assistant-theme.js` :
 * la coquille Stimulus (`ket-mobile`) mesure et manipule, la décision est ici.
 *
 * Deux exports, deux pièges distincts :
 *
 *  - surfaceSuivante() : sur un téléphone, une seule surface est visible à la
 *    fois — la conversation, ou la feuille. Écrite à la main dans les
 *    gestionnaires d'événements, cette table se trompe toujours au même
 *    endroit : l'ouverture d'une conversation DEPUIS la feuille, qui doit
 *    refermer celle-ci. Sans quoi le chat s'installe derrière un panneau qui le
 *    masque, et l'utilisateur croit que son geste n'a rien fait. C'est le même
 *    principe que le tiroir de la version étroite du workspace, qui se referme
 *    au choix d'une rubrique.
 *
 *  - indexDuProchainFocus() : le piégeage du focus dans la feuille. Une modale
 *    dont la tabulation s'échappe rend le reste de la page atteignable au
 *    clavier alors qu'il est visuellement recouvert (WCAG 2.4.3). Le calcul est
 *    trivial et se trompe pourtant systématiquement aux DEUX bords du cycle,
 *    ainsi que sur l'élément actif introuvable (focus posé sur le conteneur).
 */

/** La conversation occupe l'écran. C'est la surface d'accueil. */
export const CHAT = 'chat';

/** La feuille recouvre la conversation (conversations + sorties de l'espace). */
export const FEUILLE = 'feuille';

/**
 * Quelle surface est visible après cet événement.
 *
 * @param {string} surface Surface actuelle (CHAT ou FEUILLE).
 * @param {string} evenement 'ouvrir-feuille' | 'fermer-feuille' | 'echap' | 'chat-pose'
 * @returns {string} La surface visible ensuite.
 */
export function surfaceSuivante(surface, evenement) {
    switch (evenement) {
        case 'ouvrir-feuille':
            return FEUILLE;

        // Échap et la croix ont exactement le même effet : c'est ce qui rend la
        // fermeture prévisible. Sur le chat, ils ne font rien — Échap y est déjà
        // pris par les boîtes de dialogue que Ket ouvre.
        case 'fermer-feuille':
        case 'echap':
            return CHAT;

        // Une conversation vient d'être posée dans la surface de travail : la
        // feuille a fait son office et se retire, sinon elle masquerait ce
        // qu'elle vient d'ouvrir.
        case 'chat-pose':
            return CHAT;

        default:
            return surface;
    }
}

/**
 * Index de l'élément à focaliser dans un cycle piégé.
 *
 * @param {{nombre: number, indexActif: number, versArriere: boolean}} params
 *   `nombre` : combien d'éléments focalisables la feuille contient ;
 *   `indexActif` : index de l'élément qui a le focus, ou -1 s'il n'est pas dans
 *   la liste (focus sur le conteneur, ou hors de la feuille) ;
 *   `versArriere` : Maj+Tab.
 * @returns {number|null} L'index à focaliser, ou `null` s'il n'y a rien à
 *   focaliser — auquel cas la coquille laisse le navigateur faire, plutôt que
 *   d'empêcher une tabulation sans rien proposer à la place.
 */
export function indexDuProchainFocus({ nombre, indexActif, versArriere }) {
    if (!Number.isInteger(nombre) || nombre <= 0) {
        return null;
    }

    // Focus hors du cycle (sur le conteneur `role="dialog"`, ou resté dehors) :
    // on ENTRE par le bord d'où l'on vient — début en avant, fin en arrière.
    if (!Number.isInteger(indexActif) || indexActif < 0 || indexActif >= nombre) {
        return versArriere ? nombre - 1 : 0;
    }

    if (versArriere) {
        return indexActif === 0 ? nombre - 1 : indexActif - 1;
    }

    return indexActif === nombre - 1 ? 0 : indexActif + 1;
}
