/**
 * CE QU'ON GARDE D'UN CHIP À CHOIX UNIQUE, ET CE QU'ON EN REFAIT AU RECHARGEMENT.
 *
 * LA RÈGLE DU PROJET : tout chip qu'un utilisateur peut cliquer survit au F5. Sans
 * exception. Un chip est un CHOIX, et refaire un choix à chaque rechargement finit par
 * dissuader d'en faire — on reprend alors le réglage par défaut faute de courage, ce qui
 * revient à ne pas offrir le réglage du tout.
 *
 * ⚠ CES RÈGLES VIVENT HORS DES CONTRÔLEURS, et c'est ce qui les rend éprouvables : ni DOM,
 * ni `localStorage`, ni Stimulus. Ce sont des décisions, pas des rendus.
 *
 * Elles étaient nées dans la rubrique Importation (`echange-perimetre-persiste.js`), qui
 * les ré-exporte pour ne rien casser. Le tableau de bord a le même besoin — mémoriser un
 * exercice comptable —, et il aurait été absurde de le réécrire.
 */

/**
 * La valeur mémorisée est-elle encore proposée ?
 *
 * ⚠ UN CHOIX PEUT DISPARAÎTRE ENTRE DEUX VISITES. L'exercice 2025 mémorisé n'a plus de
 * chip le jour où la dernière police de 2025 est supprimée ; le reposer laisserait un
 * réglage actif que rien à l'écran ne montre, et un écran vide sans explication. On
 * retombe alors sur le défaut, ce qui est le comportement le moins surprenant.
 *
 * @param {unknown} memorise ce qui sort du stockage (peut être n'importe quoi)
 * @param {string[]} valeursOffertes
 * @returns {string|null} la valeur à reposer, ou null pour garder le défaut
 */
export function choixARestaurer(memorise, valeursOffertes) {
    if (typeof memorise !== 'string' || !Array.isArray(valeursOffertes)) {
        return null;
    }

    return valeursOffertes.includes(memorise) ? memorise : null;
}

/**
 * Clé de rangement d'un réglage de TABLEAU DE BORD, par cabinet.
 *
 * ⚠ PAR CABINET, ET SURTOUT PAS PAR ONGLET. L'identifiant d'un onglet de travail est
 * fabriqué à la volée (`ws-tab-<horodatage>-<aléa>`) : il change à chaque ouverture. S'y
 * indexer reviendrait à ne jamais rien retrouver — le réglage semblerait ne pas survivre au
 * F5 alors qu'il aurait bien été écrit.
 *
 * @param {number|string} idEntreprise
 * @param {string} nom nom du réglage ('exercice'…)
 * @returns {string}
 */
export function cleDuTableauDeBord(idEntreprise, nom) {
    return `tableauDeBord_${idEntreprise}_${nom}`;
}
