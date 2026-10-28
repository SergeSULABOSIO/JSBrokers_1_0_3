/**
 * Logique PURE des filtres actifs de la barre de recherche — AUCUN accès au DOM.
 * Séparée exprès pour être testable sous Node (`node --test tests/js/`) : la
 * coquille Stimulus (`search-bar_controller.js`) mesure le DOM et rend ce que ces
 * fonctions décident.
 *
 *  - formatterTexteFiltre() : le libellé lisible d'UN filtre, selon son type
 *    (plage de dates, relation, booléen, nombre, texte).
 *  - filtresActifs() : la LIGNE DES FILTRES ACTIFS (refonte de la colonne 3, C2) —
 *    tout ce qui masque des données, en texte, défauts compris (« par défaut »).
 *    Elle remplace le repli « Filtres N » et son volet, qui cachaient justement ce
 *    qu'il fallait lire (BIC Cristal : l'état réel visible).
 *  - clesARetirer() : « Tout retirer », selon la règle du chantier 6.
 */

/** Le critère « Mon portefeuille » (PortefeuilleScope::CRITERION_KEY). */
export const CRITERE_PORTEFEUILLE = '__mon_portefeuille__';

/**
 * Les critères que propose la barre de recherche SIMPLE, dans l'ordre du sélecteur.
 *
 * Elle accepte les critères texte et les relations DIRECTES (une relation y est cherchée en
 * texte libre sur son champ d'affichage). Les chemins (« portefeuille.gestionnaire ») et les
 * autres types (nombre, date, booléen) restent à la recherche avancée, comme les critères
 * synthétiques (« __mon_portefeuille__ »). Exception : un critère que le canevas déclare de
 * RECHERCHE RAPIDE (`Rapide`, ex. la police d'une tranche) y figure même au bout d'un chemin,
 * et EN TÊTE — c'est lui que la barre propose d'emblée.
 *
 * @param {Array<object>|null|undefined} definitions Le searchCanvas de la rubrique.
 * @returns {Array<object>}
 */
export function criteresDeLaBarreSimple(definitions) {
    const tous = Array.isArray(definitions) ? definitions : [];
    const rapides = tous.filter((c) => c.Rapide === true && c.Type === 'Text');
    const directs = tous.filter((c) => c.Rapide !== true
        && (c.Type === 'Text' || c.Type === 'Relation')
        && !String(c.Nom).includes('.')
        && !String(c.Nom).startsWith('__'));

    return [...rapides, ...directs];
}

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

/** La valeur d'un critère, quelle que soit sa forme (`{value, label}` ou valeur nue). */
function valeurDe(critere) {
    if (critere === null || critere === undefined) return '';
    return String(typeof critere === 'object' ? (critere.value ?? '') : critere);
}

/**
 * LA LIGNE DES FILTRES ACTIFS : tout ce qui masque des données, en texte.
 *
 * Les chips d'abord, dans l'ordre de leurs groupes (« Prime : Impayée ») ; puis les autres
 * critères dans l'ordre reçu (terme, critères avancés, « Mon portefeuille »). Un critère égal
 * au défaut posé par le serveur porte `parDefaut`. Un groupe SANS « Toutes » a un défaut que le
 * serveur applique toujours (le statut de Production intermédiaires) : rien n'étant posé, il est
 * montré quand même, sans × — le retirer ne changerait rien ; posé explicitement à cette même
 * valeur, il reste le défaut. Un critère IMPOSÉ par le serveur (la visibilité des congés, qu'il
 * repose à chaque recherche) se lit, sans × non plus. Un groupe de PRÉSENTATION (la maille de
 * lecture d'une liste, pas un filtre) n'apparaît jamais.
 *
 * @param {object|null|undefined} criteres les critères actifs, par nom de critère.
 * @param {{
 *   definitions?: Array<object>|null,
 *   groupes?: Array<{cle: string, titre: string, options: Object<string, string>, defaut?: string,
 *                    presentation?: boolean}>|null,
 *   defauts?: object|null,
 *   imposes?: string[]|null,
 * }} contexte `definitions` : le searchCanvas ; `groupes` : les chips de la liste ; `defauts` :
 *   les critères posés d'office par le serveur (initialCriteria de la liste) ; `imposes` : les
 *   clés que le serveur impose à chaque recherche.
 * @returns {Array<{cle: string, texte: string, parDefaut: boolean, retirable: boolean}>}
 */
export function filtresActifs(criteres, { definitions, groupes, defauts, imposes } = {}) {
    const defs = Array.isArray(definitions) ? definitions : [];
    const lesGroupes = Array.isArray(groupes) ? groupes : [];
    const actifs = (criteres && typeof criteres === 'object') ? criteres : {};
    const parDefaut = (cle, valeur) => !!defauts && Object.prototype.hasOwnProperty.call(defauts, cle) && valeurDe(defauts[cle]) === valeur;
    const impose = (cle) => Array.isArray(imposes) && imposes.includes(cle);
    const vus = new Set();
    const filtres = [];

    lesGroupes.forEach((groupe) => {
        vus.add(groupe.cle);
        if (groupe.presentation) return;
        const options = groupe.options || {};
        const valeur = valeurDe(actifs[groupe.cle]);
        if (valeur === '') {
            if (groupe.defaut !== undefined && groupe.defaut !== null && groupe.defaut !== '') {
                filtres.push({ cle: groupe.cle, texte: `${groupe.titre} : ${options[groupe.defaut] ?? groupe.defaut}`, parDefaut: true, retirable: false });
            }
            return;
        }
        const critere = actifs[groupe.cle];
        const libelle = options[valeur] ?? ((critere && typeof critere === 'object' && critere.label) || valeur);
        // La valeur même du défaut d'un groupe sans « Toutes » : c'est lui, rien à retirer.
        const leDefaut = groupe.defaut !== undefined && groupe.defaut !== null && String(groupe.defaut) === valeur;
        const fixe = leDefaut || impose(groupe.cle);
        filtres.push({ cle: groupe.cle, texte: `${groupe.titre} : ${libelle}`, parDefaut: fixe || parDefaut(groupe.cle, valeur), retirable: !fixe });
    });

    Object.entries(actifs).forEach(([cle, critere]) => {
        if (vus.has(cle)) return;
        const valeur = valeurDe(critere);
        const plage = critere && typeof critere === 'object' && (critere.from || critere.to);
        if (valeur === '' && !plage) return;
        const def = defs.find((c) => c && c.Nom === cle);
        const libelle = def && def.Display ? def.Display : cle;
        filtres.push({ cle, texte: formatterTexteFiltre(libelle, critere, def), parDefaut: impose(cle) || parDefaut(cle, valeur), retirable: !impose(cle) });
    });

    return filtres;
}

/**
 * « TOUT RETIRER » — la règle du chantier 6 (CauseDeListeVide), du côté de l'écran : tout ce que
 * la ligne affiche comme RETIRABLE part (jamais la maille de lecture, jamais ce que le serveur
 * impose) ; « Mon portefeuille » seulement pour un ADMINISTRATEUR du cabinet. Le × de la barre
 * de recherche suit la même règle.
 *
 * @param {Array<{cle: string, retirable: boolean}>|null|undefined} filtres la sortie de filtresActifs.
 * @param {boolean} portefeuilleRetirable l'invité administre-t-il le cabinet ? (rendu par le serveur)
 * @returns {string[]} les clés à retirer.
 */
export function clesARetirer(filtres, portefeuilleRetirable) {
    if (!Array.isArray(filtres)) return [];

    return filtres
        .filter((f) => f.retirable && (portefeuilleRetirable || f.cle !== CRITERE_PORTEFEUILLE))
        .map((f) => f.cle);
}

/**
 * LES CRITÈRES QUI RESTENT après « réinitialiser » — le × de la recherche, « Tout retirer », le
 * « Réinitialiser » de la recherche avancée et celui de l'état vide (décision de Serge du
 * 2026-10-28 : une seule règle pour les trois gestes).
 *
 * @param {object|null|undefined} criteres les critères actifs.
 * @param {Array<{cle: string, retirable: boolean}>|null|undefined} filtres la sortie de filtresActifs.
 * @param {boolean} portefeuilleRetirable l'invité administre-t-il le cabinet ?
 * @returns {object} les critères à garder.
 */
export function criteresApresRetrait(criteres, filtres, portefeuilleRetirable) {
    const reste = { ...(criteres && typeof criteres === 'object' ? criteres : {}) };
    clesARetirer(filtres, portefeuilleRetirable).forEach((cle) => { delete reste[cle]; });

    return reste;
}

/**
 * Ce qui distingue un critère : son opérateur (absent = « = ») et sa valeur, ou les bornes d'une
 * plage ; '' = il ne filtre rien.
 */
function signature(critere) {
    if (critere && typeof critere === 'object' && (critere.from || critere.to)) return `${critere.from || ''}…${critere.to || ''}`;
    const valeur = valeurDe(critere);
    if (valeur === '') return '';

    return `${(critere && typeof critere === 'object' && critere.operator) || '='} ${valeur}`;
}

/**
 * LES CRITÈRES SONT-ILS DÉJÀ CEUX PAR DÉFAUT ? Alors « Réinitialiser » ne changerait rien, et ne
 * s'offre pas. Comparés par opérateur et valeur (pas par libellé) ; une valeur vide (chip
 * « Toutes ») compte comme absente. N'entrent PAS dans la comparaison, comme pour la ligne
 * (filtresActifs) : la maille de lecture (groupe de présentation, qui ne masque rien), un
 * critère imposé (le serveur le repose de toute façon) et le défaut d'un groupe sans « Toutes »
 * (posé ou non, le serveur l'applique).
 *
 * @param {object|null|undefined} criteres les critères en vigueur.
 * @param {object|null|undefined} defauts les critères par défaut de la liste.
 * @param {{groupes?: Array<{cle: string, defaut?: string, presentation?: boolean}>|null, imposes?: string[]|null}} [contexte]
 * @returns {boolean}
 */
export function auxDefauts(criteres, defauts, { groupes, imposes } = {}) {
    const lesGroupes = Array.isArray(groupes) ? groupes : [];
    const horsComparaison = (cle, critere) => (Array.isArray(imposes) && imposes.includes(cle))
        || lesGroupes.some((g) => g.cle === cle && (g.presentation
            || (g.defaut !== undefined && g.defaut !== null && String(g.defaut) === valeurDe(critere))));
    const actifs = (o) => Object.entries(o && typeof o === 'object' ? o : {})
        .filter(([cle, critere]) => !horsComparaison(cle, critere))
        .map(([cle, critere]) => [cle, signature(critere)])
        .filter(([, valeur]) => valeur !== '');
    const a = new Map(actifs(criteres));
    const b = new Map(actifs(defauts));

    return a.size === b.size && [...a].every(([cle, valeur]) => b.get(cle) === valeur);
}
