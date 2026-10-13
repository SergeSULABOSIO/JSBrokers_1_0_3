/**
 * LA LISTE QUE LE SERVEUR A RENDUE DOIT-ELLE S'ANNONCER ?
 *
 * `app:list.rendered` déclenche la repose des lignes cochées après un F5. La liste
 * principale l'émet donc aussi à son premier rendu — sans quoi une rubrique consultée sans
 * filtre perdait sa sélection. Deux règles, et elles vivent HORS du contrôleur pour qu'un
 * test puisse les tenir.
 */

/**
 * Est-ce la liste PRINCIPALE d'une rubrique ?
 *
 * ⚠ ELLE PORTE L'ID `principal`. Le test lisait seulement `list-manager-…`, préfixe qu'aucun
 * gabarit ne produit : il ne valait jamais vrai, et l'annonce du premier rendu était du code
 * mort. Seule la liste principale l'émet : c'est la seule dont la sélection soit persistée,
 * et une collection contextuelle partage le `workspaceTabId` de sa rubrique.
 *
 * @param {string} id l'id de l'élément porteur du list-manager
 * @returns {boolean}
 */
export function estListePrincipale(id) {
    return id === 'principal' || String(id ?? '').startsWith('list-manager-');
}

/**
 * Le premier rendu s'annonce-t-il ?
 *
 * PAS SI UN CHARGEMENT VIENT DE DÉMARRER pour cette liste. Un onglet restauré avec des
 * filtres les repose dans la MÊME pile d'appels que son initialisation : la recherche part,
 * le squelette remplace déjà les lignes. Annoncer à ce moment ferait lire « 0 sur N » à la
 * barre de statut, éteindrait la barre de progression avant la réponse, et ferait recocher
 * la sélection sur des lignes qui vont disparaître. Le rendu qui compte est alors celui du
 * rafraîchissement, qui s'annonce de lui-même.
 *
 * @param {string} id
 * @param {boolean} chargementEnCours
 * @returns {boolean}
 */
export function doitAnnoncerLePremierRendu(id, chargementEnCours) {
    return estListePrincipale(id) && !chargementEnCours;
}
