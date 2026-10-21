/**
 * ROUTAGE PAR ONGLET DE RUBRIQUE — à quel onglet appartient une réponse, une recherche.
 *
 * ── LA PANNE QUE CELA RÉPARE (chantier 15, défaut 3) ─────────────────────────────
 * Le Cerveau étiquetait ses diffusions avec l'onglet AFFICHÉ au moment où elles
 * partaient. Pour une réponse lente, ce n'est plus celui qui l'a demandée : Avenants
 * charge, l'utilisateur ouvre Tranches, la réponse d'Avenants arrive — et ses critères
 * (« Échus ») partaient sous l'identifiant de Tranches. Le workspace-manager les
 * mémorisait sur Tranches, et le F5 les y rejouait. Une recherche sans destinataire,
 * elle, s'appliquait à l'onglet affiché, quel qu'il soit.
 *
 * La règle : une réponse appartient à l'onglet qui l'a DEMANDÉE ; une recherche va à
 * l'onglet qu'elle NOMME. L'onglet courant n'est qu'un repli pour les émetteurs muets.
 * Chaque barre (outils, totaux, recherche, liste) écoute pour SON onglet seulement :
 * une diffusion bien étiquetée met à jour l'onglet en arrière-plan sans toucher l'écran.
 */

/** Identifiant logique de la liste principale d'un onglet de rubrique. */
export const LISTE_PRINCIPALE = 'principal';

/**
 * L'onglet de rubrique propriétaire : celui qu'annonce l'émetteur, sinon le courant.
 * @param {string|null|undefined} annonce
 * @param {string|null|undefined} courant
 * @returns {string|null}
 */
export function ongletProprietaire(annonce, courant) {
    return annonce || courant || null;
}

/**
 * Une liste vient d'être chargée : faut-il publier son contexte, et sous quel onglet ?
 *
 * Affiché : seule la liste ACTIVE de l'écran publie (comportement historique). En
 * arrière-plan : sa liste principale publie — c'est la seule qu'un onglet qui naît
 * montre —, pour que ses propres barres et sa mémoire soient justes ; une collection,
 * qui ne naît que d'un clic de l'utilisateur, jamais.
 *
 * @param {{tabId: string, ongletInitialise?: string|null, ongletCourant?: string|null, listeActive?: string|null}} p
 * @returns {{publier: boolean, affiche: boolean, workspaceTabId: string|null}}
 */
export function publicationApresInitialisation({ tabId, ongletInitialise = null, ongletCourant = null, listeActive = null }) {
    const workspaceTabId = ongletProprietaire(ongletInitialise, ongletCourant);
    const affiche = !ongletInitialise || ongletInitialise === ongletCourant;
    const publier = affiche ? listeActive === tabId : tabId === LISTE_PRINCIPALE;

    return { publier, affiche, workspaceTabId };
}

/**
 * La liste que vise une recherche.
 * @param {string|null|undefined} annonce   onglet nommé par l'émetteur
 * @param {string|null|undefined} courant   onglet affiché
 * @param {string|null|undefined} listeActive liste active de l'onglet affiché
 * @returns {{workspaceTabId: string|null, affiche: boolean, listeId: string}}
 */
export function cibleDeRecherche(annonce, courant, listeActive) {
    const workspaceTabId = ongletProprietaire(annonce, courant);
    const affiche = workspaceTabId === (courant || null);

    return { workspaceTabId, affiche, listeId: affiche ? (listeActive || LISTE_PRINCIPALE) : LISTE_PRINCIPALE };
}
