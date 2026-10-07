/**
 * LE NOM D'UNE FICHE, TEL QUE LES ACTIONS ET LES TITRES LE CITENT.
 *
 * Un « selecto » — l'objet qui désigne une fiche auprès du cerveau — porte un `name` que
 * les handlers reprennent dans leurs confirmations (« Retirer Marlette du portefeuille ? »).
 * Deux surfaces en fabriquent : la ligne de liste (list-row) et la barre d'actions du
 * dialogue d'entité. Le titre du dialogue nomme la même fiche. Une règle écrite plusieurs
 * fois finirait par nommer la même fiche de deux façons selon l'endroit d'où l'on regarde.
 *
 * Ordre : l'étiquette posée par le serveur (la colonne principale de la liste), sinon ce
 * que l'entité sérialisée porte de nommant.
 */

/**
 * Le libellé d'une fiche, ou une chaîne VIDE si rien ne la nomme.
 *
 * @param {string|null|undefined} etiquette Étiquette rendue par le gabarit.
 * @param {object|null|undefined} entite Entité sérialisée (groupe list:read).
 * @returns {string}
 */
export function libelleDeFiche(etiquette, entite) {
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

    return '';
}

/**
 * Le nom affiché d'une fiche : son libellé, sinon son numéro. Jamais vide — une
 * confirmation doit toujours nommer ce qu'elle s'apprête à toucher.
 *
 * @param {string|null|undefined} etiquette
 * @param {object|null|undefined} entite
 * @param {string|number} id
 * @returns {string}
 */
export function nomAffiche(etiquette, entite, id) {
    return libelleDeFiche(etiquette, entite) || `Élément #${id}`;
}

/** Le jeton d'identifiant d'un gabarit de titre : « #%id% », « n°%id% », « #12 », « %id% ». */
const JETON_IDENTIFIANT = /\s*(?:#|n°)\s*(?:%id%|\d+|\?)|\s*%id%/u;

/**
 * LE TITRE D'UN DIALOGUE NOMME LA FICHE, PAS SON NUMÉRO.
 *
 * « Modification du Client #118 » ne dit rien à celui qui l'ouvre : il connaît Marlette
 * SULA EKUMBO, pas le 118. Le jeton d'identifiant du gabarit est remplacé par le libellé
 * de la fiche, entre guillemets français ; une fiche sans libellé perd simplement son
 * numéro (« Modification du Client ») plutôt que d'afficher un chiffre. Un gabarit sans
 * jeton (« Modifier la piste ») est rendu tel quel.
 *
 * @param {string} gabarit Gabarit de titre (canevas, collection, tableau de bord…).
 * @param {string} libelle Libellé de la fiche (cf. libelleDeFiche), éventuellement vide.
 * @returns {string}
 */
export function titreDeFiche(gabarit, libelle) {
    const texte = String(gabarit || '');
    if (!JETON_IDENTIFIANT.test(texte)) {
        return texte;
    }
    const nom = (libelle || '').trim();

    return texte.replace(JETON_IDENTIFIANT, nom ? ` « ${nom} »` : '').trim();
}
