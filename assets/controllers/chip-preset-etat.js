/**
 * L'ÉTAT D'UN CHIP DE FILTRE RAPIDE, d'après les critères courants de la liste.
 *
 * Deux familles de chips coexistent, et elles ne se lisent pas de la même façon :
 *
 *  — le chip à VALEUR (« Sans pièce », « Ce mois ») est actif quand le critère porte SA
 *    valeur ; l'option vide (« Tous ») l'est quand le critère est absent ;
 *  — le chip-SÉLECTEUR (« Choisir un agent… ») ne porte aucune valeur : il va les chercher
 *    au clic. Le comparer à une valeur absente le rendait actif quand RIEN n'était filtré —
 *    en même temps que « Tous » —, et il ne nommait jamais l'agent retenu. Un filtre qu'on
 *    ne peut pas lire est un filtre qu'on croit absent.
 *
 * La règle vit ici, hors du contrôleur, pour être éprouvée sans DOM : c'est une décision,
 * pas un rendu.
 */

/**
 * @param {{valeurAttendue: string|null, estSelecteur: boolean, libelleDefaut: string,
 *         prefixe?: string}} chip - `prefixe` n'est posé que lorsqu'un même critère
 *        accepte plusieurs FAMILLES de valeurs (bénéficiaire agent / partenaire).
 * @param {{value?: *, label?: string}|string|number|null|undefined} critere - le critère
 *        courant pour la CLÉ de ce chip (tel que stocké par le cerveau).
 * @returns {{actif: boolean, libelle: string|null}} `libelle` null = ne pas y toucher.
 */
export function etatChipPreset(chip, critere) {
    const valeur = (critere && typeof critere === 'object')
        ? String(critere.value ?? '')
        : String(critere ?? '');

    if (!chip.estSelecteur) {
        return { actif: valeur === String(chip.valeurAttendue ?? ''), libelle: null };
    }

    // Un sélecteur est actif dès qu'une valeur est posée, et il l'ANNONCE : le libellé du
    // critère (le nom de l'agent) s'il y en a un, la valeur brute à défaut — mieux vaut un
    // identifiant qu'un intitulé qui ment.
    if (valeur === '') {
        return { actif: false, libelle: chip.libelleDefaut };
    }

    // DEUX SÉLECTEURS PEUVENT PARTAGER UNE CLÉ. Le bénéficiaire d'un reversement est un
    // agent OU un partenaire : deux colonnes, un seul filtre, donc deux chips côte à côte.
    // Sans ce test, choisir un agent allumait AUSSI « Choisir un partenaire… » et le
    // faisait porter le nom de l'agent — un filtre qui ment sur ce qu'il filtre.
    if (chip.prefixe) {
        if (!valeur.startsWith(`${chip.prefixe}:`)) {
            return { actif: false, libelle: chip.libelleDefaut };
        }
    }

    const libelle = (critere && typeof critere === 'object' && critere.label) ? critere.label : valeur;

    return { actif: true, libelle: String(libelle) };
}
