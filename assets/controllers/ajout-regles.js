/**
 * Logique PURE du bouton « Ajouter » d'une liste du workspace — AUCUN accès au DOM.
 *
 * « Ajouter » n'est offert que là où la création a un sens. Une rubrique fermée à la création
 * (`creation_interdite` : condition de partage, reversement, réglages des congés…) ne l'offre
 * nulle part. Une entité qui ne naît que rattachée à son parent (`creation_depuis_le_parent`,
 * registre serveur ParentObligatoire : tranche, avenant, rôle…) ne l'offre pas dans sa liste
 * PRINCIPALE, qui ne saurait à quoi la rattacher — seulement dans l'onglet contextuel de CE
 * parent, où le Cerveau le transmet. Un onglet rattaché par un autre champ (fiche Assureur,
 * onglet « Cotations » : lien `assureur`) ne l'offre pas : le serveur exigerait la piste.
 *
 * Le bouton est ABSENT, pas grisé (Serge, 2026-10-30) : il n'y a rien à faire ici, donc rien à
 * montrer. Le serveur refuse de toute façon la création sans parent.
 */

/** L'onglet d'une collection contextuelle : `collection-<champ>-for-<id du parent>`. */
export function estOngletContextuel(tabId) {
    return /^collection-.+-for-\d+$/.test(tabId || '');
}

/**
 * @param {object} parametres les `parametres` du canevas de formulaire de la liste
 * @param {string|null} tabId l'onglet interne actif (`principal`, ou une collection contextuelle)
 * @param {string|null} [champParent] le champ qui rattache l'onglet contextuel à son parent
 *   (`parentLien.champ`, résolu par le serveur)
 * @returns {boolean} « Ajouter » est offert
 */
export function ajoutOffert(parametres, tabId, champParent = null) {
    const p = parametres || {};
    if (!p.endpoint_submit_url || p.creation_interdite === true) return false;
    if (!p.creation_depuis_le_parent) return true;
    return estOngletContextuel(tabId) && champParent === p.creation_depuis_le_parent;
}
