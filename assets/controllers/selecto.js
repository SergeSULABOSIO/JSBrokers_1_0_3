/**
 * LE NOM D'UNE FICHE, TEL QUE LES ACTIONS LE CITENT.
 *
 * Un « selecto » — l'objet qui désigne une fiche auprès du cerveau — porte un `name` que
 * les handlers reprennent dans leurs confirmations (« Retirer Marlette du portefeuille ? »).
 * Deux surfaces en fabriquent : la ligne de liste (list-row) et la barre d'actions du
 * dialogue d'entité. Une règle écrite deux fois finirait par nommer la même fiche de deux
 * façons selon l'endroit d'où part le clic.
 *
 * Ordre : l'étiquette posée par le serveur (la colonne principale de la liste), sinon ce
 * que l'entité sérialisée porte de nommant, sinon son numéro. Jamais vide.
 *
 * @param {string|null|undefined} etiquette Étiquette rendue par le gabarit.
 * @param {object|null|undefined} entite Entité sérialisée (groupe list:read).
 * @param {string|number} id
 * @returns {string}
 */
export function nomAffiche(etiquette, entite, id) {
    const libelle = (etiquette || '').trim();
    if (libelle !== '') {
        return libelle;
    }

    for (const champ of ['nom', 'libelle', 'reference', 'titre', 'numero']) {
        const valeur = entite?.[champ];
        if (typeof valeur === 'string' && valeur.trim() !== '') {
            return valeur.trim();
        }
    }

    return `Élément #${id}`;
}
