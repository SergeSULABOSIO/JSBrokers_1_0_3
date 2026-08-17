/**
 * Logique PURE du repli de la COLONNE 2 du workspace (rubriques & descriptions)
 * — AUCUN accès au DOM, à `localStorage` ni au viewport réel. Séparée exprès
 * pour être testable sous Node (`node --test tests/js/`) sans bundler ni
 * navigateur, sur le modèle de `assistant-theme.js` et `menu-flottant.js` : la
 * coquille Stimulus (`workspace-manager`) mesure (getBoundingClientRect,
 * offsetHeight, window.innerHeight) et passe des nombres ici.
 *
 * Deux besoins, deux exports :
 *
 *  - sommetDuFlyout() : où poser verticalement le panneau flottant quand la
 *    colonne est repliée. Géométrie triviale mais piégeuse (le cas « panneau
 *    plus grand que la fenêtre » se trompe une fois sur deux).
 *  - etatSuivant() : la machine à états du panneau. C'est elle qui paye. Le
 *    panneau a deux façons d'être ouvert — TRANSITOIRE au survol d'un groupe,
 *    ÉPINGLÉ après un clic — et trois façons de se fermer. Écrite à la main
 *    dans les gestionnaires d'événements, cette table se trompe toujours sur
 *    le même point : le re-clic sur le groupe déjà épinglé, qui doit REFERMER.
 *
 * La marge de viewport est importée de `menu-flottant.js` : une seule constante
 * de marge dans l'application, sinon deux panneaux flottants ne s'arrêtent pas
 * au même endroit du bord de l'écran.
 */

import { MARGE_VIEWPORT } from './menu-flottant.js';

export { MARGE_VIEWPORT };

/** Le panneau est fermé : la colonne repliée n'occupe rien à l'écran. */
export const FERME = 'ferme';
/** Ouvert au survol d'un groupe : se referme dès que le pointeur s'en va. */
export const SURVOL = 'survol';
/** Ouvert par un clic sur un groupe : reste en place pour être parcouru. */
export const EPINGLE = 'epingle';

/**
 * Position verticale (`top`, en px) du panneau flottant : aligné sur le haut de
 * l'icône du groupe, puis écrêté aux marges de la fenêtre.
 *
 * L'écrêtage bas passe AVANT l'écrêtage haut (`Math.max` en second, comme
 * `positionnerMenu`) : sur un panneau plus grand que la fenêtre, mieux vaut
 * coller au bord haut — le début de la liste reste lisible et le reste s'atteint
 * par le défilement interne — que déborder par le bas, où le contenu serait
 * inatteignable.
 *
 * @param {{
 *   ancreTop: number,        // rect.top de l'icône du groupe (coordonnées viewport)
 *   hauteurPanneau: number,  // offsetHeight du panneau, contenu déjà injecté
 *   hauteurViewport: number, // window.innerHeight
 *   marge?: number,
 * }} entrees
 * @returns {number}
 */
export function sommetDuFlyout({ ancreTop, hauteurPanneau, hauteurViewport, marge = MARGE_VIEWPORT }) {
    const bas = hauteurViewport - hauteurPanneau - marge;

    return Math.max(marge, Math.min(ancreTop, bas));
}

/**
 * Transition de la machine à états du panneau.
 *
 * L'état ne suffit pas à décider : « clic sur un groupe » ferme ou ré-épingle
 * selon que c'est le MÊME groupe ou un autre. D'où le couple (état, groupe) en
 * entrée comme en sortie — `groupe` étant un identifiant opaque (le nom du
 * groupe côté appelant), jamais un élément du DOM.
 *
 * Actions reconnues :
 *  - `survol`      {groupe}  survol d'un item de la colonne 1
 *  - `quitte`                le pointeur quitte l'item (et le panneau)
 *  - `clicGroupe`  {groupe}  clic sur un groupe de la colonne 1
 *  - `clicRubrique`          clic sur une rubrique dans le panneau
 *  - `echap`                 touche Échap
 *  - `exterieur`             clic hors de la navigation
 *  - `deplie`                la colonne repasse en affichage permanent
 *
 * Toute action inconnue laisse l'état intact : la coquille peut lui envoyer des
 * gestes qu'elle ne gère pas encore sans provoquer de fermeture surprise.
 *
 * @param {{etat: string, groupe: (string|null)}} courant
 * @param {{type: string, groupe?: (string|null)}} action
 * @returns {{etat: string, groupe: (string|null)}}
 */
export function etatSuivant(courant, action) {
    const etat = courant?.etat ?? FERME;
    const groupe = courant?.groupe ?? null;
    const type = action?.type;
    const cible = action?.groupe ?? null;

    switch (type) {
        case 'survol':
            // Un panneau ÉPINGLÉ ne se laisse pas déclasser par un survol : il
            // montre la description du groupe survolé (c'est la colonne 2 qui
            // s'en charge, inchangée depuis toujours) mais reste épinglé sur le
            // sien, et le mouseleave le lui rend.
            return etat === EPINGLE ? courant : { etat: SURVOL, groupe: cible };

        case 'quitte':
            return etat === EPINGLE ? courant : { etat: FERME, groupe: null };

        case 'clicGroupe':
            // Re-cliquer le groupe déjà épinglé referme — c'est la seule façon
            // de ranger le panneau sans ouvrir une rubrique.
            if (etat === EPINGLE && groupe === cible) {
                return { etat: FERME, groupe: null };
            }

            return { etat: EPINGLE, groupe: cible };

        case 'clicRubrique':
        case 'echap':
        case 'exterieur':
        case 'deplie':
            return { etat: FERME, groupe: null };

        default:
            return courant ?? { etat: FERME, groupe: null };
    }
}

/**
 * L'élément visé par cette action devient-il l'ANCRE DE REPOS du panneau — celle
 * sur laquelle il se recale quand plus rien n'est survolé ?
 *
 * La distinction est ténue et se trompe facilement. Panneau épinglé sur
 * « Production », l'utilisateur survole « Sinistre » : la colonne montre bien la
 * description de Sinistre, à hauteur de Sinistre, mais l'épingle n'a pas bougé.
 * Quand le pointeur s'en va, le panneau redevient celui de Production et doit
 * revenir SE POSER SUR PRODUCTION. Retenir Sinistre comme ancre de repos ferait
 * réapparaître la longue liste de Production à hauteur de Sinistre — et,
 * l'écrêtage aidant, déborder sous le bas de la fenêtre.
 *
 * @param {{etat: string, groupe: (string|null)}} suivant état issu de etatSuivant()
 * @param {{type: string, groupe?: (string|null)}} action l'action qui vient d'être jouée
 * @returns {boolean}
 */
export function ancreDeReposChange(suivant, action) {
    if (suivant?.etat === SURVOL) {
        return true;
    }

    // Épinglé : seule l'action qui épingle CE groupe-là déplace l'ancre de repos.
    return suivant?.etat === EPINGLE && suivant.groupe === (action?.groupe ?? null);
}

/**
 * Quelle rubrique de la liste qu'on vient d'afficher doit porter la marque de
 * sélection — celle qui est ouverte dans l'espace de travail — ou `null`.
 *
 * Le HTML des rubriques est recopié depuis un `<template>` inerte : il arrive donc
 * TOUJOURS vierge de toute classe `active`. Sans ce rapprochement, rouvrir la liste
 * d'un groupe efface le rappel de ce qu'on a sous les yeux — d'autant plus gênant
 * dans le panneau flottant, où cette liste est tout ce que l'utilisateur voit.
 *
 * Deux garde-fous qui ne se voient pas à l'œil nu :
 *  - un onglet d'un AUTRE groupe ne marque rien (sinon on désignerait une rubrique
 *    absente de la liste affichée, ou pire, une homonyme) ;
 *  - les onglets injectés à la volée (aperçu de note, panneau HTML du chat) n'ont
 *    pas de `componentName` : ils ne désignent aucune rubrique.
 *
 * @param {{componentName?: string, entityName?: string, groupName?: string}|null} ongletActif
 * @param {string} groupeAffiche
 * @returns {{componentName: string, entityName: string}|null}
 */
export function rubriqueAMarquer(ongletActif, groupeAffiche) {
    if (!ongletActif || !ongletActif.componentName) {
        return null;
    }

    if ((ongletActif.groupName || '') !== groupeAffiche) {
        return null;
    }

    return {
        componentName: ongletActif.componentName,
        entityName: ongletActif.entityName || '',
    };
}

/**
 * Le panneau est-il visible dans cet état ? Un seul endroit pour en décider :
 * la coquille ne doit pas comparer les constantes à la main à chaque geste.
 *
 * @param {{etat: string}|string} courant
 * @returns {boolean}
 */
export function estOuvert(courant) {
    const etat = typeof courant === 'string' ? courant : courant?.etat;

    return etat === SURVOL || etat === EPINGLE;
}
