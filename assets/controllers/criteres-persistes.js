/**
 * CE QU'ON GARDE DES FILTRES D'UN ONGLET, ET CE QU'ON EN REFAIT AU RECHARGEMENT.
 *
 * Les onglets de l'espace de travail survivent au F5 (`workspaceTabs_<entreprise>` en
 * localStorage), mais leurs FILTRES ne survivaient pas : ils ne vivaient que dans l'état
 * en mémoire du Cerveau. L'utilisateur retrouvait donc ses onglets vidés de leur filtre,
 * sans que rien ne le lui dise — et un écran qui montre tout là où l'on avait restreint
 * est aussi trompeur qu'un écran qui restreint sans le dire.
 *
 * La règle vit ici, hors du contrôleur, pour être éprouvée sans DOM ni localStorage :
 * c'est une décision, pas un rendu.
 */

/**
 * Les critères d'un onglet valent-ils la peine d'être conservés ?
 *
 * Un objet vide n'est pas « pas de filtre » : c'est une INFORMATION — l'utilisateur a
 * peut-être retiré le badge « Mon portefeuille » posé par défaut, et le lui rendre au
 * rechargement serait re-filtrer à son insu. On distingue donc « aucun critère connu »
 * (rien à dire) de « critères connus, éventuellement vides » (à respecter).
 *
 * @param {object|null|undefined} criteres
 * @returns {boolean}
 */
export function critereConservable(criteres) {
    return criteres !== null && typeof criteres === 'object' && !Array.isArray(criteres);
}

/**
 * Range les critères sur l'entrée d'onglet correspondante, et rend la liste MISE À JOUR.
 *
 * Rend le tableau d'origine tel quel si l'onglet est inconnu : la persistance ne crée
 * jamais d'onglet, elle ne fait que décorer ceux qui existent.
 *
 * @param {Array<{id: string, criteres?: object}>} onglets
 * @param {string} tabId
 * @param {object|null|undefined} criteres
 * @returns {Array<{id: string, criteres?: object}>}
 */
export function memoriserCriteres(onglets, tabId, criteres) {
    if (!Array.isArray(onglets) || !tabId || !critereConservable(criteres)) {
        return onglets;
    }

    return onglets.map((onglet) => (
        onglet && onglet.id === tabId ? { ...onglet, criteres } : onglet
    ));
}

/**
 * Les filtres à REPOSER après une restauration, indexés par identifiant d'onglet.
 *
 * Seuls les onglets qui portent des critères NON VIDES sont retenus : reposer un objet
 * vide déclencherait une recherche sans objet à l'ouverture de chaque onglet, pour un
 * résultat identique à l'absence de filtre. Le cas « l'utilisateur avait tout retiré » est
 * donc servi en ne faisant rien — ce qui est exactement le comportement voulu, puisque la
 * liste se charge déjà non filtrée.
 *
 * @param {Array<{id: string, criteres?: object}>} onglets
 * @returns {Record<string, object>}
 */
export function criteresARestaurer(onglets) {
    if (!Array.isArray(onglets)) {
        return {};
    }

    const enAttente = {};
    for (const onglet of onglets) {
        if (!onglet || !onglet.id || !critereConservable(onglet.criteres)) {
            continue;
        }
        if (Object.keys(onglet.criteres).length > 0) {
            enAttente[onglet.id] = onglet.criteres;
        }
    }

    return enAttente;
}
