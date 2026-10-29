/**
 * LE STOCKAGE DU NAVIGATEUR N'APPARTIENT QU'À SON UTILISATEUR — règles pures (chantier 15).
 *
 * L'espace de travail garde dans le navigateur ses onglets, leurs filtres, le panneau de Ket,
 * les choix des échanges. Les clés ne portaient que le CABINET : sur un poste partagé, le
 * collègue qui se connectait après retrouvait les onglets — et les filtres, noms de clients
 * compris — du précédent. Décisions de Serge (2026-10-20/21) :
 *
 *   — clés par UTILISATEUR et cabinet ;
 *   — à la connexion d'un autre utilisateur, tout le travail laissé est effacé, sur TOUS les
 *     cabinets, ancien format compris. On ne mémorise que l'identifiant NUMÉRIQUE du dernier
 *     utilisateur, jamais son nom ni son e-mail ;
 *   — à la déconnexion, vidage ciblé (les préférences d'affichage survivent) ;
 *   — le localStorage (copie pour un NOUVEL onglet du navigateur) ne garde que la disposition
 *     et les filtres structurés, et expire en 7 jours. L'état complet — termes saisis,
 *     sélection — ne vit que dans le sessionStorage de l'onglet (F5, reprise après plantage).
 *
 * Les Storage sont passés en paramètre : tout s'éprouve sans navigateur
 * (tests/js/stockage-de-travail.test.mjs). Le branchement vit dans stockage-de-travail.js.
 */

/** La seule trace de l'utilisateur précédent : son identifiant numérique. */
export const CLE_DERNIER_UTILISATEUR = 'jsb-dernier-utilisateur';

/** Durée de vie de la copie durable (localStorage). */
export const DUREE_DE_VIE_MS = 7 * 24 * 3600 * 1000;

/**
 * Les familles de clés qui portent le TRAVAIL d'un utilisateur (tous cabinets, tous formats).
 * Les préférences d'affichage (`menuCol2Collapsed_`, `chatFullscreen_`, `tableauDeBord_`,
 * cache d'icônes `jsb-icon-v1::`) n'en sont pas : elles ne disent rien de personne.
 */
export const PREFIXES_DE_TRAVAIL = [
    'workspaceTabs_',
    'visualizationHtmlTab_',
    'lastActiveState_',
    'echangePerimetre_',
    'echangeChoix_',
    'echangeOnglet_',
];

/** L'ancien format, sans utilisateur : `workspaceTabs_<cabinet>`, `visualizationHtmlTab_<cabinet>`. */
const ANCIEN_FORMAT = /^(workspaceTabs|visualizationHtmlTab)_\d+$/;
/** Le format actuel : `<famille>_<utilisateur>_<cabinet>` ; capture l'utilisateur. */
const AVEC_UTILISATEUR = /^(?:workspaceTabs|visualizationHtmlTab)_(\d+)_\d+$/;

/** Un identifiant d'utilisateur ou de cabinet : entier strictement positif, sinon null. */
function identifiant(valeur) {
    const texte = valeur === null || valeur === undefined ? '' : String(valeur).trim();
    return /^[1-9]\d*$/.test(texte) ? texte : null;
}

/** @returns {string|null} la clé des onglets de cet utilisateur dans ce cabinet */
export function cleOnglets(idUtilisateur, idEntreprise) {
    const u = identifiant(idUtilisateur);
    const c = identifiant(idEntreprise);
    return u && c ? `workspaceTabs_${u}_${c}` : null;
}

/** @returns {string|null} la clé du panneau de la colonne 4 (chat de Ket) */
export function clePanneauVisualisation(idUtilisateur, idEntreprise) {
    const u = identifiant(idUtilisateur);
    const c = identifiant(idEntreprise);
    return u && c ? `visualizationHtmlTab_${u}_${c}` : null;
}

/** Retire les clés qui satisfont `doitPartir`. On relève d'abord : retirer décale les index. */
function retirer(stockage, doitPartir) {
    try {
        const cles = [];
        for (let i = 0; i < stockage.length; i++) {
            const cle = stockage.key(i);
            if (cle !== null && doitPartir(cle)) cles.push(cle);
        }
        cles.forEach((cle) => stockage.removeItem(cle));
    } catch { /* stockage indisponible (navigation privée, quota) : rien à effacer */ }
}

const estDuTravail = (cle) => PREFIXES_DE_TRAVAIL.some((prefixe) => cle.startsWith(prefixe));

/**
 * À chaque page : l'ancien format disparaît ; si l'utilisateur connecté n'est pas le dernier
 * (ou si on ignore qui était le dernier), tout le travail laissé est effacé.
 *
 * @param {Storage} local
 * @param {Storage} session
 * @param {string|number|null|undefined} idUtilisateur identifiant de l'utilisateur connecté, s'il y en a un
 */
export function surArrivee(local, session, idUtilisateur) {
    retirer(local, (cle) => ANCIEN_FORMAT.test(cle));

    const id = identifiant(idUtilisateur);
    if (!id) return; // page sans utilisateur (vitrine, connexion) : on ne sait à qui confier quoi

    let dernier = null;
    try { dernier = local.getItem(CLE_DERNIER_UTILISATEUR); } catch { /* indisponible */ }
    if (dernier !== id) {
        retirer(local, estDuTravail);
        retirer(session, estDuTravail);
    } else {
        // Le dernier est le bon, mais un AUTRE onglet du navigateur a pu garder — ou réécrire,
        // depuis une page restée ouverte — les clés d'un utilisateur précédent : elles partent.
        const dUnAutre = (cle) => {
            const m = AVEC_UTILISATEUR.exec(cle);
            return m !== null && m[1] !== id;
        };
        retirer(local, dUnAutre);
        retirer(session, dUnAutre);
    }
    try { local.setItem(CLE_DERNIER_UTILISATEUR, id); } catch { /* indisponible */ }
}

/**
 * Cette page peut-elle encore écrire le travail de `idUtilisateur` ? Non si un autre s'est
 * connecté depuis dans ce navigateur (page restée ouverte dans un autre onglet) : ses
 * écritures recréeraient des clés que plus personne n'effacerait.
 * @param {Storage} local
 * @param {string|number|null|undefined} idUtilisateur
 */
export function peutEcrire(local, idUtilisateur) {
    const id = identifiant(idUtilisateur);
    if (!id) return false;
    try {
        return local.getItem(CLE_DERNIER_UTILISATEUR) === id;
    } catch {
        return false;
    }
}

/** Déconnexion : le travail part, les préférences restent. */
export function surDeconnexion(local, session) {
    retirer(local, estDuTravail);
    retirer(session, estDuTravail);
}

// ── La copie durable : disposition + filtres structurés ─────────────────────────────

/**
 * Chips dont la valeur ou le libellé est un TEXTE saisi ou un NOM (personne, partenaire).
 * ⚠ Tout NOUVEAU chip qui porte un nom s'ajoute ici (ou à LIBELLES_NEUTRES).
 */
const PORTENT_UN_NOM_OU_UN_TEXTE = new Set([
    '__ou_texte_libre__',
    '__beneficiaire_production__',
    '__beneficiaire_reversement__',
]);

/**
 * Chips GARDÉS, mais dont le libellé est remplacé : il peut nommer quelqu'un, la valeur non.
 * `__mon_portefeuille__` porte le nom du portefeuille (souvent celui de son gestionnaire) ;
 * l'écarter ne marche pas — la restauration remplace tout le jeu de critères, et le nouvel
 * onglet montrait alors tout le cabinet. Sa valeur est l'identifiant de l'invité.
 */
const LIBELLES_NEUTRES = { __mon_portefeuille__: 'Mon portefeuille' };
const CHIP = /^__[a-z_]+__$/;
const DATE = /^\d{4}-\d{2}-\d{2}/;
const NOMBRE = /^-?\d+([.,]\d+)?$/;
/** Comparaisons qu'un champ TEXTE ne produit jamais (« = » oui : un texte exact peut être un n° de téléphone). */
const COMPARAISONS = new Set(['<', '>', '<=', '>=']);

/**
 * Ce critère peut-il aller dans le localStorage ? Oui s'il ne peut nommer personne :
 * chip à valeur simple (son libellé vient du vocabulaire du serveur), comparaison numérique
 * (`<`, `>`, `<=`, `>=`), plage de dates, booléen. Non pour un texte saisi (LIKE, ou exact —
 * même s'il ressemble à un nombre : téléphone, n° de police, NIF), une relation (son libellé
 * est le nom de la fiche liée), un chip qui porte un nom, et tout ce qu'on ne reconnaît pas.
 */
function critereStructure(cle, critere) {
    if (PORTENT_UN_NOM_OU_UN_TEXTE.has(cle)) return false;
    if (typeof critere === 'string') return critere === '0' || critere === '1';
    if (!critere || typeof critere !== 'object' || Array.isArray(critere)) return false;
    if ('from' in critere || 'to' in critere) {
        return [critere.from, critere.to].every((d) => d === undefined || d === '' || DATE.test(String(d)));
    }
    if (critere.operator === 'LIKE') return false;
    if (CHIP.test(cle)) return critere.value !== null && typeof critere.value !== 'object';
    if ('label' in critere) return false;
    return COMPARAISONS.has(critere.operator) && NOMBRE.test(String(critere.value ?? ''));
}

/**
 * La copie durable des onglets : disposition et filtres structurés, sans sélection ni taille de
 * page (elle ne vit qu'avec l'onglet du navigateur — Serge, 2026-10-29).
 * @param {Array<object>} onglets  l'état complet (celui du sessionStorage)
 * @param {string|null} idActif
 * @param {number} maintenant      horodatage en millisecondes
 * @returns {{tabs: Array<object>, activeTabId: string|null, expireLe: number}}
 */
export function copieLocale(onglets, idActif, maintenant) {
    // Les RUBRIQUES seulement. Un aperçu (relevé de compte, note, bordereau : `loadUrl`) porte
    // dans son titre le nom du client ou la référence de la pièce : il reste dans l'onglet.
    const tabs = (Array.isArray(onglets) ? onglets : []).filter((o) => o && o.id && !o.loadUrl).map((onglet) => {
        const { selection, criteres, parPage, ...disposition } = onglet;
        if (criteres && typeof criteres === 'object') {
            const gardes = Object.fromEntries(Object.entries(criteres)
                .filter(([cle, c]) => critereStructure(cle, c))
                .map(([cle, c]) => [cle, cle in LIBELLES_NEUTRES ? { ...c, label: LIBELLES_NEUTRES[cle] } : c]));
            if (Object.keys(gardes).length > 0) disposition.criteres = gardes;
        }
        return disposition;
    });

    return { tabs, activeTabId: idActif ?? null, expireLe: maintenant + DUREE_DE_VIE_MS };
}

/**
 * Relit une copie durable ; null si elle est illisible, sans échéance ou expirée.
 * @param {string|null} json
 * @param {number} maintenant
 * @returns {{tabs: Array<object>, activeTabId: string|null}|null}
 */
export function lireCopieLocale(json, maintenant) {
    let copie;
    try { copie = JSON.parse(json); } catch { return null; }
    if (!copie || typeof copie !== 'object' || !Array.isArray(copie.tabs)) return null;
    if (typeof copie.expireLe !== 'number' || copie.expireLe <= maintenant) return null;

    return { tabs: copie.tabs, activeTabId: copie.activeTabId ?? null };
}

// ── Le panneau de la colonne 4 (chat de Ket) ────────────────────────────────────────

/**
 * La copie durable du panneau : de quoi le rouvrir, pas son titre — celui d'une conversation
 * reprend souvent la première question (« Sinistre Mme Marlette »). Le titre vit dans la
 * copie de l'onglet (sessionStorage).
 * @param {{tabKey: string, iconAlias?: string, sourceUrl: string}} panneau
 * @param {number} maintenant
 */
export function copiePanneau(panneau, maintenant) {
    return {
        tabKey: panneau.tabKey,
        iconAlias: panneau.iconAlias ?? null,
        sourceUrl: panneau.sourceUrl,
        expireLe: maintenant + DUREE_DE_VIE_MS,
    };
}

/**
 * Relit un panneau mémorisé (copie de l'onglet, ou copie durable non expirée) ; null sinon.
 * @param {string|null} json
 * @param {number} maintenant
 * @returns {{tabKey: string, iconAlias?: string|null, sourceUrl: string, title?: string}|null}
 */
export function lirePanneau(json, maintenant) {
    let panneau;
    try { panneau = JSON.parse(json); } catch { return null; }
    if (!panneau || typeof panneau !== 'object' || !panneau.tabKey || !panneau.sourceUrl) return null;
    if ('expireLe' in panneau && !(typeof panneau.expireLe === 'number' && panneau.expireLe > maintenant)) return null;

    const { expireLe, ...lu } = panneau;
    return lu;
}
