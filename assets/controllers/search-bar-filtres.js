/**
 * Logique PURE du résumé des filtres actifs de la barre de recherche — AUCUN
 * accès au DOM. Séparée exprès pour être testable sous Node
 * (`node --test tests/js/`) sans bundler ni navigateur, sur le modèle de
 * `menu-flottant.js`, `assistant-theme.js` et `assistant-chart-spec.js` : la
 * coquille Stimulus (`search-bar_controller.js`) se contente de rendre ce que
 * ces fonctions décident.
 *
 * Deux responsabilités :
 *  - formatterTexteFiltre() : le libellé lisible d'UN filtre, selon son type
 *    (plage de dates, relation, booléen, nombre, texte).
 *  - construireResumeFiltres() : la stratégie d'affichage de l'ENSEMBLE des
 *    filtres sur la ligne unique de la barre (rien / badge en clair / pastille
 *    compteur + volet). C'est cette fonction qui garantit que la barre garde
 *    une hauteur constante quel que soit le nombre de critères actifs.
 */

/**
 * Au-delà de ce nombre de filtres actifs, les badges en clair ne tiennent plus
 * dans la ligne unique (le panneau de workspace est étroit : la barre partage
 * sa largeur avec la barre d'outils) : on bascule sur la pastille compteur.
 * À 1 filtre on reste en clair — reconnaissance plutôt que rappel (Nielsen #6)
 * sans aucun risque de déformation.
 */
export const SEUIL_COMPTEUR = 2;

/**
 * Construit le libellé lisible d'un filtre actif, en fonction du type de critère.
 * @param {string} displayName Libellé du critère (ex. « Client »).
 * @param {*} val La valeur du filtre (forme dépendante du type).
 * @param {object|undefined|null} criterionDef Définition du critère issue du searchCanvas.
 * @returns {string}
 */
export function formatterTexteFiltre(displayName, val, criterionDef) {
    const type = criterionDef ? criterionDef.Type : null;

    // Plage de dates : { from, to }
    if (type === 'DateTimeRange' || (typeof val === 'object' && val !== null && (val.from || val.to))) {
        const { from, to } = val;
        if (from && to) return `${displayName} : du ${from} au ${to}`;
        if (from) return `${displayName} : à partir du ${from}`;
        if (to) return `${displayName} : jusqu'au ${to}`;
    }

    // Relation : { value: id, label } → on affiche le libellé lisible, pas l'id.
    if (type === 'Relation' && typeof val === 'object' && val !== null) {
        return `${displayName} : ${val.label || val.value}`;
    }

    // Booléen : valeur simple '1' / '0' → Oui / Non.
    if (type === 'Boolean') {
        const raw = (typeof val === 'object' && val !== null) ? val.value : val;
        const label = criterionDef && criterionDef.Valeur ? criterionDef.Valeur[String(raw)] : raw;
        return `${displayName} : ${label ?? raw}`;
    }

    // Nombre (ou tout objet avec opérateur) : Display op valeur.
    if (typeof val === 'object' && val !== null && val.operator && type === 'Number') {
        return `${displayName} ${val.operator} ${val.value}`;
    }

    // Texte / repli : { value } ou chaîne simple.
    const displayValue = (typeof val === 'object' && val !== null) ? val.value : val;
    return `${displayName} : "${displayValue}"`;
}

/**
 * Décide COMMENT présenter les filtres actifs sur la ligne unique de la barre.
 *
 * @param {object|null|undefined} criteres Filtres actifs reçus du cerveau,
 *        indexés par nom de critère.
 * @param {Array<object>|null|undefined} definitions Le searchCanvas (définitions
 *        de critères) : sert à retrouver le libellé et le type de chaque filtre.
 * @returns {{
 *   mode: 'vide'|'badge'|'compteur',
 *   nombre: number,
 *   filtres: Array<{cle: string, libelle: string, texte: string}>,
 * }}
 *   - `vide`     : aucun filtre → la zone est masquée.
 *   - `badge`    : un seul filtre → badge en clair dans la barre.
 *   - `compteur` : plusieurs filtres → pastille « Filtres N » ouvrant le volet.
 */
export function construireResumeFiltres(criteres, definitions) {
    const defs = Array.isArray(definitions) ? definitions : [];
    const entrees = (criteres && typeof criteres === 'object') ? Object.entries(criteres) : [];

    const filtres = entrees.map(([cle, valeur]) => {
        const def = defs.find(c => c && c.Nom === cle);
        const libelle = def && def.Display ? def.Display : cle;
        return { cle, libelle, texte: formatterTexteFiltre(libelle, valeur, def) };
    });

    let mode = 'compteur';
    if (filtres.length === 0) mode = 'vide';
    else if (filtres.length < SEUIL_COMPTEUR) mode = 'badge';

    return { mode, nombre: filtres.length, filtres };
}
