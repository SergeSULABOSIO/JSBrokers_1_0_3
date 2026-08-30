/**
 * CE QU'ON GARDE D'UN ONGLET, ET CE QU'ON EN REFAIT AU RECHARGEMENT.
 *
 * Deux choses y survivent désormais : ses FILTRES et sa SÉLECTION. Elles obéissent à
 * la même discipline — décorer une entrée d'onglet existante, ne jamais en créer une,
 * et ne reposer que ce qui a du sens.
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

    return decorer(onglets, tabId, { criteres });
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

/**
 * Range la SÉLECTION sur l'entrée d'onglet correspondante, et rend la liste MISE À JOUR.
 *
 * On ne garde que les IDENTIFIANTS. L'état complet de la sélection porte l'entité
 * sérialisée et son canevas — plusieurs kilo-octets par ligne, qui seraient périmés au
 * rechargement de toute façon : ce qu'on veut retrouver, c'est QUELLES lignes étaient
 * cochées, et elles seront relues du serveur.
 *
 * @param {Array<{id: string, selection?: Array<number|string>}>} onglets
 * @param {string} tabId
 * @param {Array<{id: number|string}>|null|undefined} selection
 * @returns {Array<{id: string, selection?: Array<number|string>}>}
 */
export function memoriserSelection(onglets, tabId, selection) {
    if (!Array.isArray(onglets) || !tabId || !Array.isArray(selection)) {
        return onglets;
    }

    const ids = selection
        .map((element) => (element && element.id !== undefined ? element.id : null))
        .filter((id) => id !== null && id !== '');

    return decorer(onglets, tabId, { selection: ids });
}

/**
 * Les sélections à REPOSER après une restauration, indexées par identifiant d'onglet.
 *
 * Un onglet dont la sélection était VIDE n'entre pas dans le résultat : il n'y a rien à
 * recocher, et la liste s'ouvre déjà sans sélection. C'est la même règle que pour les
 * filtres — on ne fait rien plutôt que de faire un geste sans effet.
 *
 * @param {Array<{id: string, selection?: Array<number|string>}>} onglets
 * @returns {Record<string, Array<number|string>>}
 */
export function selectionARestaurer(onglets) {
    if (!Array.isArray(onglets)) {
        return {};
    }

    const enAttente = {};
    for (const onglet of onglets) {
        if (!onglet || !onglet.id || !Array.isArray(onglet.selection) || onglet.selection.length === 0) {
            continue;
        }
        enAttente[onglet.id] = onglet.selection;
    }

    return enAttente;
}
/**
 * Décore UNE entrée d'onglet, et rend le tableau d'origine si elle n'existe pas.
 *
 * L'identité du tableau est le signal que l'appelant attend : il ne persiste que si elle
 * a changé. Rendre systématiquement une copie — ce que fait `map()` — le faisait écrire
 * dans le stockage à chaque changement de contexte, y compris pour un onglet inconnu.
 *
 * @param {Array<{id: string}>} onglets
 * @param {string} tabId
 * @param {object} decoration
 * @returns {Array<{id: string}>}
 */
function decorer(onglets, tabId, decoration) {
    if (!onglets.some((onglet) => onglet && onglet.id === tabId)) {
        return onglets;
    }

    return onglets.map((onglet) => (
        onglet && onglet.id === tabId ? { ...onglet, ...decoration } : onglet
    ));
}