/**
 * UNE FICHE ENREGISTRÉE QUE LE FILTRE ACTIF ÉCARTE — règles pures (testées en node).
 *
 * Après un enregistrement, la liste se rafraîchit avec SES critères. Une fiche qui n'y
 * répond pas (une piste dont le client n'est dans aucun de mes portefeuilles, sous « Mon
 * portefeuille ») n'y reparaît pas : le toast disait « réussi » et la fiche semblait perdue.
 * Le serveur dit si elle passe le filtre, et lequel l'écarte ; on le dit à l'utilisateur, avec
 * le geste qui la montre.
 *
 * Le message est du TEXTE : le toast le pose par textContent (notification-manager). Il
 * n'est donc pas échappé ici — il le serait deux fois.
 */

/**
 * Le message, ou null s'il n'y a rien à dire (fiche visible, ou étrangère à la liste).
 *
 * @param {{id: number, visible: boolean|null, masqueePar: {cle: string, libelle: string}[]}} verification
 * @param {string|null} libelle
 * @returns {{texte: string, action: string|null, cles: string[]}|null}
 */
export function messageFicheHorsFiltre(verification, libelle) {
    if (!verification || verification.visible !== false) return null;

    const fiche = libelle ? `« ${libelle} »` : 'La fiche';
    const filtres = (verification.masqueePar || []).filter((m) => m && m.cle);
    if (filtres.length === 0) {
        return {
            texte: `${fiche} est bien enregistrée, mais le filtre actif de la liste ne la montre pas.`,
            action: null,
            cles: [],
        };
    }

    const noms = filtres.map((m) => `« ${m.libelle || m.cle} »`);
    const liste = noms.length === 1 ? noms[0] : `${noms.slice(0, -1).join(', ')} et ${noms.at(-1)}`;
    const pluriel = filtres.length > 1;

    return {
        texte: `${fiche} est bien enregistrée, mais ${pluriel ? 'les filtres' : 'le filtre'} ${liste} `
            + `la ${pluriel ? 'masquent' : 'masque'} dans cette liste.`,
        action: pluriel ? 'Retirer ces filtres' : 'Retirer ce filtre',
        cles: filtres.map((m) => m.cle),
    };
}

/** Les critères actifs, moins ceux qui écartent la fiche. */
export function criteresSans(criteres, cles) {
    const reste = { ...(criteres || {}) };
    (cles || []).forEach((cle) => { delete reste[cle]; });
    return reste;
}
