/**
 * LE STOCKAGE DU NAVIGATEUR N'APPARTIENT QU'À SON UTILISATEUR (chantier 15).
 *
 * Décisions de Serge (2026-10-20/21) :
 *   — à la connexion d'un AUTRE utilisateur, tout ce que le premier avait laissé (onglets,
 *     filtres, panneau de Ket, choix des échanges) est effacé, sur TOUS les cabinets, ancien
 *     format de clé compris — c'est le vrai garde-fou, testé en premier ;
 *   — pour détecter le changement, on ne garde QUE l'identifiant numérique du dernier
 *     utilisateur, jamais son nom ni son e-mail ;
 *   — à la déconnexion, vidage ciblé (pas Clear-Site-Data) : les préférences d'affichage
 *     survivent ;
 *   — les anciennes clés `workspaceTabs_<cabinet>` (sans utilisateur) disparaissent au premier
 *     chargement ;
 *   — le localStorage ne garde que la DISPOSITION et les filtres STRUCTURÉS (chips,
 *     échéances, nombres, dates), jamais un texte saisi ni un nom ; il expire en 7 jours.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    CLE_DERNIER_UTILISATEUR,
    DUREE_DE_VIE_MS,
    cleOnglets,
    clePanneauVisualisation,
    surArrivee,
    surDeconnexion,
    copieLocale,
    lireCopieLocale,
    copiePanneau,
    lirePanneau,
    peutEcrire,
} from '../../assets/stockage-de-travail-regles.js';

/** Un Storage en mémoire, avec la même interface que celui du navigateur. */
function stockage(initial = {}) {
    const m = new Map(Object.entries(initial));
    return {
        get length() { return m.size; },
        key: (i) => [...m.keys()][i] ?? null,
        getItem: (k) => (m.has(k) ? m.get(k) : null),
        setItem: (k, v) => { m.set(k, String(v)); },
        removeItem: (k) => { m.delete(k); },
        cles: () => [...m.keys()].sort(),
    };
}

/** Ce qu'un utilisateur 7 laisse derrière lui, sur DEUX cabinets (3 et 12). */
const TRAVAIL_DE_7 = {
    workspaceTabs_7_3: '{"tabs":[]}',
    workspaceTabs_7_12: '{"tabs":[]}',
    visualizationHtmlTab_7_3: '{"tabKey":"conv-1","title":"Sinistre Mme Marlette"}',
    echangePerimetre_3_piste: 'tous',
    echangeChoix_12_piste_tri: 'date',
    echangeOnglet_3: 'notes',
};
/** Les préférences d'affichage : elles ne disent rien de personne et survivent. */
const PREFERENCES = {
    menuCol2Collapsed_3: '1',
    chatFullscreen_12: '1',
    'tableauDeBord_3_exercice': '2026',
    'jsb-icon-v1::action:edit': '<svg/>',
};
/** L'ancien format, sans utilisateur : propriétaire inconnu. */
const ANCIEN_FORMAT = {
    workspaceTabs_3: '{"tabs":[]}',
    workspaceTabs_12: '{"tabs":[]}',
    visualizationHtmlTab_3: '{"tabKey":"conv-9"}',
};

test("un AUTRE utilisateur se connecte : tout le travail du premier disparaît, sur tous les cabinets", () => {
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '7', ...TRAVAIL_DE_7, ...ANCIEN_FORMAT, ...PREFERENCES });
    const session = stockage({ workspaceTabs_7_3: '{"tabs":[]}', lastActiveState_3: '{}', echangeOnglet_12: 'x' });

    surArrivee(local, session, '9');

    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, ...Object.keys(PREFERENCES)].sort());
    assert.deepEqual(session.cles(), []);
    assert.equal(local.getItem(CLE_DERNIER_UTILISATEUR), '9');
});

test("le même utilisateur revient : son travail reste, seul l'ancien format part", () => {
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '7', ...TRAVAIL_DE_7, ...ANCIEN_FORMAT, ...PREFERENCES });
    const session = stockage({ workspaceTabs_7_3: '{"tabs":[]}' });

    surArrivee(local, session, 7);

    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, ...Object.keys(TRAVAIL_DE_7), ...Object.keys(PREFERENCES)].sort());
    assert.deepEqual(session.cles(), ['workspaceTabs_7_3']);
});

test("premier chargement après déploiement (aucun utilisateur mémorisé) : les anciennes clés disparaissent définitivement", () => {
    const local = stockage({ ...ANCIEN_FORMAT, ...PREFERENCES });
    const session = stockage();

    surArrivee(local, session, '7');

    for (const ancienne of Object.keys(ANCIEN_FORMAT)) assert.equal(local.getItem(ancienne), null, ancienne);
    assert.equal(local.getItem('menuCol2Collapsed_3'), '1');
    assert.equal(local.getItem(CLE_DERNIER_UTILISATEUR), '7');

    // Et au chargement suivant, rien ne les ressuscite.
    surArrivee(local, session, '7');
    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, ...Object.keys(PREFERENCES)].sort());
});

test("un travail de propriétaire INCONNU (aucun utilisateur mémorisé) n'est pas confié au premier venu", () => {
    const local = stockage({ ...TRAVAIL_DE_7 });
    surArrivee(local, stockage(), '9');
    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR]);
});

test("les clés d'un AUTRE utilisateur partent même quand le dernier mémorisé est le bon (onglet resté ouvert)", () => {
    // A se déconnecte dans l'onglet 1, B s'y connecte (dernier = B). L'onglet 2, resté sur une
    // page de A, a gardé — ou réécrit — les clés de A : la prochaine page de B les efface.
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '9', workspaceTabs_7_3: '{}', visualizationHtmlTab_7_12: '{}', workspaceTabs_9_3: '{}', ...PREFERENCES });
    const session = stockage({ workspaceTabs_7_3: '{"tabs":[{"criteres":{"nom":"Marlette"}}]}', workspaceTabs_9_3: '{}' });

    surArrivee(local, session, '9');

    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, 'workspaceTabs_9_3', ...Object.keys(PREFERENCES)].sort());
    assert.deepEqual(session.cles(), ['workspaceTabs_9_3']);
});

test("une page restée ouverte d'un utilisateur qui n'est plus le dernier n'écrit plus rien", () => {
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '9' });
    assert.equal(peutEcrire(local, '7'), false);
    assert.equal(peutEcrire(local, '9'), true);
    assert.equal(peutEcrire(local, null), false);
    // Stockage illisible : on n'écrit pas à l'aveugle.
    assert.equal(peutEcrire({ getItem() { throw new Error('x'); } }, '9'), false);
});

test("seul l'identifiant NUMÉRIQUE est mémorisé — jamais un nom ni un e-mail", () => {
    for (const intrus of ['ssula@joseara.com', 'Serge', '', '0', '-3', '7; x', null, undefined]) {
        const local = stockage({ ...TRAVAIL_DE_7 });
        surArrivee(local, stockage(), intrus);
        assert.equal(local.getItem(CLE_DERNIER_UTILISATEUR), null, String(intrus));
    }
    const local = stockage();
    surArrivee(local, stockage(), '42');
    assert.match(local.getItem(CLE_DERNIER_UTILISATEUR), /^[1-9]\d*$/);
});

test('page sans utilisateur connecté (vitrine, connexion) : rien n’est effacé hormis l’ancien format', () => {
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '7', ...TRAVAIL_DE_7, ...ANCIEN_FORMAT });
    surArrivee(local, stockage(), undefined);
    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, ...Object.keys(TRAVAIL_DE_7)].sort());
});

test('déconnexion : vidage ciblé, les préférences survivent', () => {
    const local = stockage({ [CLE_DERNIER_UTILISATEUR]: '7', ...TRAVAIL_DE_7, ...ANCIEN_FORMAT, ...PREFERENCES });
    const session = stockage({ workspaceTabs_7_3: '{}', lastActiveState_3: '{}' });

    surDeconnexion(local, session);

    assert.deepEqual(local.cles(), [CLE_DERNIER_UTILISATEUR, ...Object.keys(PREFERENCES)].sort());
    assert.deepEqual(session.cles(), []);
});

test("un stockage inaccessible (navigation privée, quota) ne fait jamais échouer la page", () => {
    const hostile = { get length() { throw new Error('SecurityError'); }, key() { throw new Error('x'); }, getItem() { throw new Error('x'); }, setItem() { throw new Error('x'); }, removeItem() { throw new Error('x'); } };
    assert.doesNotThrow(() => surArrivee(hostile, hostile, '7'));
    assert.doesNotThrow(() => surDeconnexion(hostile, hostile));
});

test('les clés portent l’utilisateur ET le cabinet', () => {
    assert.equal(cleOnglets(7, 3), 'workspaceTabs_7_3');
    assert.equal(clePanneauVisualisation('7', '3'), 'visualizationHtmlTab_7_3');
    // Sans utilisateur identifié, pas de clé : on n'écrit rien qu'un autre pourrait relire.
    assert.equal(cleOnglets(null, 3), null);
    assert.equal(cleOnglets('ssula@joseara.com', 3), null);
    assert.equal(clePanneauVisualisation(7, undefined), null);
});

// ── La copie durable (localStorage) : disposition + filtres structurés, 7 jours ─────

const ONGLETS = [
    {
        id: 'ws-a', entityName: 'Avenant', title: 'Avenants', url: '/admin/avenant/x',
        selection: [4, 5],
        parPage: 50,
        criteres: {
            __echeance_avenant__: { operator: '=', value: 'echus', label: 'Échus' },
            // Son libellé est le NOM du portefeuille (souvent celui d'un gestionnaire).
            __mon_portefeuille__: { operator: '=', value: '1', label: 'Portefeuille Jean Kabila' },
            primeTotale: { operator: '>=', value: '1000' },
            // Un texte « exact » qui RESSEMBLE à un nombre : téléphone, n° de police, NIF…
            telephone: { operator: '=', value: '0812345678' },
            endingAt: { from: '2026-01-01', to: '2026-12-31' },
            renouvelable: '1',
            // Ce qui peut nommer quelqu'un : saisi, ou libellé d'une relation / d'un bénéficiaire.
            reference: { operator: 'LIKE', value: 'Marlette' },
            nom: { operator: '=', value: 'Marlette' },
            client: { operator: '=', value: '12', label: 'Mme Marlette' },
            __beneficiaire_production__: { operator: '=', value: 'agent:3', label: 'Jean Kabila' },
            __beneficiaire_reversement__: { operator: '=', value: 'partenaire:5', label: 'Cabinet Mbuyi' },
            __ou_texte_libre__: { operator: '=', value: { champs: ['nom'], valeur: 'Marlette' } },
        },
    },
    { id: 'ws-b', entityName: 'Tranche', title: 'Tranches', url: '/admin/tranche/x' },
    // Un APERÇU (relevé de compte, note, bordereau) : son titre nomme le client ou la pièce.
    { id: 'ws-c', title: 'Relevé de compte — Mme Marlette', iconAlias: 'client', tabKey: 'soa-client-12', loadUrl: '/admin/client/soa/12/x' },
];

test('la copie durable garde la disposition et les filtres structurés, rien qui nomme quelqu’un', () => {
    const maintenant = Date.UTC(2026, 9, 21);
    const copie = copieLocale(ONGLETS, 'ws-a', maintenant);

    assert.equal(copie.activeTabId, 'ws-a');
    assert.equal(copie.expireLe, maintenant + DUREE_DE_VIE_MS);
    assert.equal(DUREE_DE_VIE_MS, 7 * 24 * 3600 * 1000);
    // Les rubriques seulement : l'aperçu (son titre nomme le client) reste dans l'onglet du navigateur.
    assert.deepEqual(copie.tabs.map((t) => t.id), ['ws-a', 'ws-b']);
    assert.equal(copie.tabs[0].entityName, 'Avenant');
    // La sélection reste dans l'onglet du navigateur.
    assert.equal('selection' in copie.tabs[0], false);
    // La taille de page aussi : elle survit au F5, pas à la fermeture (Serge, 2026-10-29).
    assert.equal('parPage' in copie.tabs[0], false);
    assert.deepEqual(Object.keys(copie.tabs[0].criteres).sort(), ['__echeance_avenant__', '__mon_portefeuille__', 'endingAt', 'primeTotale', 'renouvelable']);
    // « Mon portefeuille » est GARDÉ (la restauration remplace tout le jeu de critères : sans
    // lui, le nouvel onglet montrait tout le cabinet), mais sous un libellé NEUTRE.
    assert.deepEqual(copie.tabs[0].criteres.__mon_portefeuille__, { operator: '=', value: '1', label: 'Mon portefeuille' });
    assert.doesNotMatch(JSON.stringify(copie), /0812345678/);
    // Aucun nom, nulle part dans la copie.
    assert.doesNotMatch(JSON.stringify(copie), /Marlette|Kabila|Mbuyi/);
    // L'onglet sans filtre n'en reçoit pas.
    assert.equal('criteres' in copie.tabs[1], false);
    // L'original n'est pas touché (il part entier dans le sessionStorage).
    assert.deepEqual(ONGLETS[0].selection, [4, 5]);
    assert.ok('reference' in ONGLETS[0].criteres);
});

test('le panneau de Ket : la copie durable garde de quoi le rouvrir, pas son titre', () => {
    const panneau = { tabKey: 'conv-4', title: 'Sinistre Mme Marlette', iconAlias: 'ia', sourceUrl: '/admin/assistant/conv/4' };
    const t0 = Date.UTC(2026, 9, 21);
    const copie = copiePanneau(panneau, t0);
    assert.deepEqual(copie, { tabKey: 'conv-4', iconAlias: 'ia', sourceUrl: '/admin/assistant/conv/4', expireLe: t0 + DUREE_DE_VIE_MS });
    assert.deepEqual(lirePanneau(JSON.stringify(copie), t0), { tabKey: 'conv-4', iconAlias: 'ia', sourceUrl: '/admin/assistant/conv/4' });
    assert.equal(lirePanneau(JSON.stringify(copie), t0 + DUREE_DE_VIE_MS), null);
    // La copie de l'onglet (sessionStorage) n'expire pas : elle meurt avec l'onglet.
    assert.equal(lirePanneau(JSON.stringify(panneau), t0)?.title, 'Sinistre Mme Marlette');
    for (const intrus of [null, '{', '{"tabKey":"x"}', '{"sourceUrl":"/x"}']) assert.equal(lirePanneau(intrus, t0), null, String(intrus));
});

test('la copie durable expire après 7 jours', () => {
    const t0 = Date.UTC(2026, 9, 21);
    const json = JSON.stringify(copieLocale(ONGLETS, 'ws-b', t0));
    assert.equal(lireCopieLocale(json, t0 + DUREE_DE_VIE_MS - 1)?.activeTabId, 'ws-b');
    assert.equal(lireCopieLocale(json, t0 + DUREE_DE_VIE_MS), null);
});

test('une copie illisible ou sans échéance est ignorée, sans erreur', () => {
    const t = Date.UTC(2026, 9, 21);
    for (const intrus of [null, '', '{', '[]', '"x"', '{"tabs":[]}', JSON.stringify({ tabs: 'x', expireLe: t + 1 })]) {
        assert.equal(lireCopieLocale(intrus, t), null, String(intrus));
    }
});
