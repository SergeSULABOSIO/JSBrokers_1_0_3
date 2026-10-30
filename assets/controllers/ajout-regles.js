/**
 * Logique PURE du bouton « Ajouter » d'une liste du workspace — AUCUN accès au DOM.
 *
 * LE NAVIGATEUR NE DÉCIDE PAS. « Peut-on créer cette fiche d'ici ? » est tranché par le serveur
 * (DecisionDeCreation : droit d'écriture, mode de création, parent du contexte) et posé dans le
 * canevas, `parametres.ajout` — pour la liste principale comme pour chaque onglet contextuel,
 * calculé avec SON lien vers son parent. La barre d'outils et le menu contextuel le lisent ici ;
 * l'état vide le lit côté serveur. Une seule réponse, aucun point d'entrée ne la refait.
 *
 * Le bouton est ABSENT, pas grisé (Serge, 2026-10-30) : il n'y a rien à faire ici, donc rien à
 * montrer. Un canevas sans décision n'offre rien (fermé par défaut).
 */

/** L'onglet d'une collection contextuelle : `collection-<champ>-for-<id du parent>`. */
export function estOngletContextuel(tabId) {
    return /^collection-.+-for-\d+$/.test(tabId || '');
}

/**
 * @param {object} parametres les `parametres` du canevas de formulaire de la liste active
 * @returns {boolean} « Ajouter » est offert
 */
export function ajoutOffert(parametres) {
    return parametres?.ajout?.offert === true;
}
