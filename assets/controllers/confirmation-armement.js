/**
 * Cœur PUR de l'armement de la boîte de confirmation — la règle qui décide si une
 * confirmation doit être acceptée. Aucun DOM, aucun Bootstrap, aucune horloge
 * implicite : le temps est passé en paramètre, la règle est donc testable seule
 * (même parti pris que assets/controllers/assistant-theme.js).
 *
 * POURQUOI CETTE RÈGLE EXISTE (incident du 2026-08-12). La boîte « Nouvelle
 * conversation » s'ouvrait et se confirmait d'elle-même : le fil courant était
 * remplacé sans que personne n'ait rien décidé. La trace l'a montré sans ambiguïté
 * — un seul geste produisait l'ouverture PUIS la confirmation.
 *
 * La garde d'alors ne tenait qu'à « shown.bs.modal », l'événement que Bootstrap
 * émet quand la modale est affichée. Or il n'arrive APRÈS l'animation que s'il y a
 * une animation à jouer : sans transition effective (mouvement réduit, feuille de
 * style surchargée, modale déjà en cours d'affichage), Bootstrap l'émet
 * SYNCHRONEMENT dans show() — c'est-à-dire à l'intérieur même du gestionnaire de
 * clic qui vient d'ouvrir la boîte. La garde se réarmait ainsi pile pendant le
 * geste qu'elle devait bloquer.
 *
 * Le délai plancher, lui, ne dépend d'aucun détail d'animation : il rend une
 * confirmation issue du geste d'ouverture impossible, quel que soit le chemin.
 */

/**
 * Délai minimal entre l'ouverture de la boîte et la première confirmation acceptée.
 * Ce n'est pas un ralentissement de confort : c'est le verrou. 400 ms est très en
 * dessous du temps de LECTURE de la question posée, et au-dessus de la durée d'un
 * clic comme d'un double-clic.
 */
export const DELAI_ARMEMENT_MS = 400;

/**
 * La confirmation doit-elle être acceptée ?
 *
 * @param {object}  etat
 * @param {boolean} etat.armed      la modale s'est-elle annoncée affichée ?
 * @param {number}  etat.ouvertA    instant d'ouverture (horloge monotone)
 * @param {number}  etat.maintenant instant courant (même horloge)
 * @param {number}  [etat.delai]    délai plancher, pour les tests
 * @returns {boolean}
 */
export function confirmationAutorisee({ armed, ouvertA, maintenant, delai = DELAI_ARMEMENT_MS }) {
    // Fail-closed : tout état non numérique ou non affiché refuse la confirmation.
    if (armed !== true) {
        return false;
    }
    if (!Number.isFinite(ouvertA) || !Number.isFinite(maintenant)) {
        return false;
    }

    return maintenant - ouvertA >= delai;
}
