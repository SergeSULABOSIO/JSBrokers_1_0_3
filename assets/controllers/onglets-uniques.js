/**
 * UNE SEULE INSTANCE PAR RUBRIQUE DANS LA BARRE D'ONGLETS.
 *
 * Chaque clic de rubrique, chaque bouton « Voir la production », chaque `ouvrir_rubrique`
 * de Ket créait un onglet NEUF sans regarder si cette rubrique était déjà ouverte. La barre
 * finissait encombrée d'instances de la même rubrique — souvent mortes ou caduques — et
 * chaque ouverture repayait le chargement complet du composant.
 *
 * La règle vit ici, hors du contrôleur, pour être éprouvée sans DOM ni localStorage : c'est
 * une décision d'IDENTITÉ, pas un rendu. Même discipline que `criteres-persistes.js` et
 * `workspace-col2.js`.
 *
 * L'IDENTITÉ D'UNE RUBRIQUE, ce sont ses deux métadonnées de menu : le composant Twig et
 * l'entité. Elles sont déjà portées par chaque onglet, et `menu.yaml` garantit qu'aucune
 * entité n'apparaît deux fois. Le Tableau de bord est la seule entrée sans entité — son
 * composant suffit à le nommer.
 */

/**
 * La clé d'identité d'une rubrique, ou `null` si l'onglet n'en est pas une.
 *
 * POURQUOI `null` PLUTÔT QU'UNE CLÉ VIDE. Les onglets HTML injectés — aperçu de note, SOA
 * client, rapport de rétrocommission — n'ont ni composant ni entité : ils se dédoublonnent
 * par leur propre `tabKey`. Leur donner une clé de rubrique les rendrait tous identiques
 * ENTRE EUX, et ouvrir l'aperçu d'une seconde note remplacerait la première. Une clé nulle
 * les fait traverser sans jamais se confondre.
 *
 * @param {{componentName?: string, entityName?: string}|null|undefined} onglet
 * @returns {string|null}
 */
export function cleDeRubrique(onglet) {
    if (!onglet || typeof onglet !== 'object') {
        return null;
    }

    const composant = onglet.componentName;
    if (!composant) {
        return null;
    }

    return `${composant}|${onglet.entityName || ''}`;
}

/**
 * L'onglet DÉJÀ OUVERT pour cette rubrique, ou `null`.
 *
 * @param {Array<{id: string, componentName?: string, entityName?: string}>} onglets
 * @param {{componentName?: string, entityName?: string}} rubrique
 * @returns {{id: string}|null}
 */
export function ongletExistantPourRubrique(onglets, rubrique) {
    const cle = cleDeRubrique(rubrique);
    if (!cle || !Array.isArray(onglets)) {
        return null;
    }

    return onglets.find((onglet) => onglet && onglet.id && cleDeRubrique(onglet) === cle) || null;
}

/**
 * Écarte les onglets en double d'une liste RESTAURÉE, et rend l'actif remappé.
 *
 * POURQUOI À LA RESTAURATION AUSSI. Le stockage de chaque utilisateur contient déjà des
 * doublons, accumulés avant que la règle n'existe. Sans ce passage, le premier F5 les
 * ressusciterait tous — la barre se serait nettoyée à l'usage pour se resalir au
 * rechargement.
 *
 * ON GARDE LA DERNIÈRE OCCURRENCE. C'est la plus récemment ouverte, donc celle qui porte
 * l'état — filtre et sélection — que l'utilisateur a le plus de chances de reconnaître.
 * L'ordre relatif des survivants est préservé : la barre ne se réorganise pas sous les yeux
 * de l'utilisateur, elle se raccourcit.
 *
 * ET L'ACTIF SUIT. Si l'onglet actif était l'un des écartés, il est remplacé par le survivant
 * de sa rubrique : l'utilisateur retrouve la rubrique qu'il regardait, jamais un écran
 * d'accueil parce que son onglet a disparu du nettoyage.
 *
 * @param {Array<{id: string}>} onglets
 * @param {string|null|undefined} idActif
 * @returns {{onglets: Array<{id: string}>, idActif: string|null}}
 */
export function dedoublonnerOnglets(onglets, idActif) {
    if (!Array.isArray(onglets)) {
        return { onglets: [], idActif: idActif || null };
    }

    // Dernier passage gagnant : on parcourt à l'envers et on retient la première rencontre.
    const vues = new Set();
    const retenus = [];
    const remplacantParCle = new Map();

    for (let i = onglets.length - 1; i >= 0; i -= 1) {
        const onglet = onglets[i];
        if (!onglet || !onglet.id) {
            continue;
        }

        const cle = cleDeRubrique(onglet);
        if (cle === null) {
            retenus.unshift(onglet);
            continue;
        }
        if (vues.has(cle)) {
            continue;
        }

        vues.add(cle);
        remplacantParCle.set(cle, onglet.id);
        retenus.unshift(onglet);
    }

    // L'actif n'a besoin d'être remappé que s'il ne survit pas tel quel.
    let actifRetenu = idActif || null;
    if (actifRetenu && !retenus.some((onglet) => onglet.id === actifRetenu)) {
        const perdu = onglets.find((onglet) => onglet && onglet.id === actifRetenu);
        actifRetenu = remplacantParCle.get(cleDeRubrique(perdu)) || null;
    }

    return { onglets: retenus, idActif: actifRetenu };
}
