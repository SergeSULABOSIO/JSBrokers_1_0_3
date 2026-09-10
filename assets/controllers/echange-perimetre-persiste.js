/**
 * CE QU'ON GARDE DU PÉRIMÈTRE D'ÉCHANGE, ET CE QU'ON EN REFAIT AU RECHARGEMENT.
 *
 * Quarante-deux cases, cinq familles : décocher ce qu'on ne veut pas exporter est un
 * vrai travail. Le perdre à chaque F5 — ou à chaque retour sur l'onglet, puisque la
 * rubrique se recharge — c'est demander de le refaire, et finir par exporter tout
 * plutôt que de recommencer.
 *
 * ⚠ ON MÉMORISE LES EXCLUSIONS, JAMAIS LES INCLUSIONS. La différence n'est pas de
 * forme. Le périmètre BOUGE : une entité s'ouvre à l'échange, un droit s'ajoute, un
 * rôle change. Si l'on gardait la liste de ce qui est coché, toute donnée apparue
 * depuis reviendrait DÉCOCHÉE — absente du fichier sans que rien ne le dise, et c'est
 * la panne la plus discrète qu'une persistance puisse produire. En gardant ce que
 * l'utilisateur a explicitement REFUSÉ, une donnée nouvelle arrive cochée, comme toute
 * donnée qu'on n'a jamais écartée.
 *
 * ⚠ ET LES EXCLUSIONS SE REPOSENT PAR LE MÊME GESTE QU'UN CLIC. Décocher pousse :
 * écarter les clients écarte les polices qui les nomment. Reposer les cases une à une
 * sans repasser par cette fermeture rendrait un état que l'utilisateur n'aurait jamais
 * pu produire à la main — et un fichier renvoyant vers des lignes absentes.
 *
 * La règle vit ici, hors du contrôleur, pour être éprouvée sans DOM ni localStorage :
 * c'est une décision, pas un rendu.
 */

/**
 * Clé de rangement, par cabinet ET par onglet.
 *
 * Le cabinet parce qu'un utilisateur en sert plusieurs et que leurs périmètres n'ont
 * rien à voir. L'onglet parce qu'« exporter ma production » et « réimporter tout » sont
 * deux intentions distinctes : les confondre ferait qu'un choix d'export restreindrait
 * un import, en silence.
 *
 * @param {number|string} idEntreprise
 * @param {string} contexte 'exporter' ou 'importer'
 * @returns {string}
 */
export function cleDuPerimetre(idEntreprise, contexte) {
    return `echangePerimetre_${idEntreprise}_${contexte}`;
}

/**
 * Clé d'un chip à CHOIX UNIQUE — validité, exercice — par cabinet et par onglet.
 *
 * ⚠ SÉPARÉE DE CELLE DU PÉRIMÈTRE, et ce n'est pas de la coquetterie : le périmètre
 * mémorise une LISTE d'exclusions, ces chips-là une SEULE valeur. Les loger ensemble
 * aurait obligé chaque lecture à deviner ce qu'elle tient.
 *
 * @param {number|string} idEntreprise
 * @param {string} contexte 'exporter' ou 'importer'
 * @param {string} nom nom du réglage ('validite', 'exercice'…)
 * @returns {string}
 */
export function cleDuChoix(idEntreprise, contexte, nom) {
    return `echangeChoix_${idEntreprise}_${contexte}_${nom}`;
}

/**
 * Clé de l'ONGLET retenu — la vue de la rubrique, par cabinet.
 *
 * ⚠ SANS CONTEXTE, ET C'EST TOUTE LA DIFFÉRENCE avec les deux clés ci-dessus. Elles se
 * rangent PAR onglet, parce qu'un réglage d'export n'a rien à dire d'un import. Celle-ci
 * désigne l'onglet lui-même : l'y indexer reviendrait à demander où l'on est pour savoir
 * où l'on était.
 *
 * ⚠ ET C'EST UN CHIP COMME LES AUTRES. Tout chip qu'un utilisateur peut cliquer doit
 * survivre au rechargement : refaire un choix à chaque F5 finit par dissuader d'en faire.
 * Celui-ci l'était moins que les autres, alors qu'il est le premier qu'on pose — un
 * cabinet qui reprend ses données retombait sur « Exporter » à chaque rafraîchissement.
 *
 * @param {number|string} idEntreprise
 * @returns {string}
 */
export function cleDeLOnglet(idEntreprise) {
    return `echangeOnglet_${idEntreprise}`;
}

/**
 * ⚠ `choixARestaurer()` A DÉMÉNAGÉ dans `./choix-persiste.js`, et n'est PAS ré-exportée
 * ici. Le tableau de bord mémorise lui aussi un exercice et se posait exactement la même
 * question — un choix peut disparaître entre deux visites —, alors la règle est devenue
 * générique.
 *
 * Elle n'est pas ré-exportée à dessein : un ré-export masque la dépendance réelle de celui
 * qui s'en sert, et le jour où ce fichier cesserait de la relayer, elle vaudrait
 * `undefined` chez lui sans que rien ne l'ait annoncé. Chacun l'importe de sa source.
 */

/**
 * Ce qu'il faut retenir d'une sélection : les codes ÉCARTÉS, triés.
 *
 * Le tri n'est pas cosmétique — il rend la valeur stable, donc comparable d'une
 * exécution à l'autre, et lisible quand on inspecte le stockage pour comprendre un
 * comportement.
 *
 * @param {Array<{code: string, retenu: boolean}>} donnees
 * @returns {string[]}
 */
export function exclusionsDe(donnees) {
    if (!Array.isArray(donnees)) {
        return [];
    }

    return donnees
        .filter((d) => d && typeof d.code === 'string' && d.code !== '' && !d.retenu)
        .map((d) => d.code)
        .sort();
}

/**
 * Les exclusions à REPOSER, restreintes à ce qui existe encore.
 *
 * Un code disparu du périmètre — droit retiré, entité sortie de l'échange — ne doit pas
 * survivre indéfiniment dans le stockage du navigateur. Le filtrer ici évite au
 * contrôleur de chercher une case qui n'existe pas, et permet de réécrire une valeur
 * propre au premier changement.
 *
 * @param {unknown} memorise ce qui sort du stockage (peut être n'importe quoi)
 * @param {string[]} codesConnus les codes réellement présents à l'écran
 * @returns {string[]}
 */
export function exclusionsARestaurer(memorise, codesConnus) {
    if (!Array.isArray(memorise) || !Array.isArray(codesConnus)) {
        return [];
    }

    const connus = new Set(codesConnus);

    return memorise.filter((code) => typeof code === 'string' && connus.has(code));
}

/**
 * Faut-il écrire quelque chose, ou effacer l'entrée ?
 *
 * ⚠ « RIEN D'EXCLU » N'EST PAS « RIEN DE MÉMORISÉ », mais les deux se rendent
 * identiquement : tout est coché. Autant effacer l'entrée plutôt que de laisser un
 * tableau vide s'accumuler pour chaque cabinet et chaque onglet visités une fois.
 *
 * C'est l'inverse du choix fait pour les filtres d'onglet, où un jeu vide EST une
 * information — l'utilisateur y a retiré un badge posé par défaut. Ici aucune donnée
 * n'est écartée par défaut : ne rien exclure est l'état de départ, pas une décision à
 * retenir.
 *
 * @param {string[]} exclusions
 * @returns {boolean}
 */
export function meriteMemorisation(exclusions) {
    return Array.isArray(exclusions) && exclusions.length > 0;
}
