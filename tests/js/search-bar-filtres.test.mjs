/**
 * Tests du cœur PUR des filtres actifs de la barre de recherche
 * (assets/controllers/search-bar-filtres.js) — aucun DOM.
 *
 * LA LIGNE DES FILTRES ACTIFS (refonte de la colonne 3, C2) : tout ce qui masque des données
 * se lit EN TEXTE, sous la recherche — chips, terme, critères avancés, « Mon portefeuille », et
 * les défauts posés d'office, marqués « par défaut ». Elle remplace le repli « Filtres N »,
 * qui cachait justement ce qu'il fallait lire (BIC Cristal : l'état réel visible).
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    clesARetirer,
    criteresApresRetrait,
    criteresDeLaBarreSimple,
    filtresActifs,
    formatterTexteFiltre,
} from '../../assets/controllers/search-bar-filtres.js';

/** Définitions de critères représentatives d'un searchCanvas d'Avenant. */
const DEFS = [
    { Nom: 'refPolice', Display: 'Réf. Police', Type: 'Text' },
    { Nom: 'client', Display: 'Client', Type: 'Relation' },
    { Nom: 'endingAt', Display: 'Échéance', Type: 'DateTimeRange' },
    { Nom: '__mon_portefeuille__', Display: 'Mon portefeuille', Type: 'Relation' },
    { Nom: 'actif', Display: 'Actif', Type: 'Boolean', Valeur: { 1: 'Oui', 0: 'Non' } },
    { Nom: 'primeTotale', Display: 'Prime totale', Type: 'Number' },
];

/** Les groupes de chips d'une rubrique, tels que la liste les déclare (titre, options). */
const GROUPES = [
    { cle: '__prime__', titre: 'Prime', options: { payee: 'Payée', impayee: 'Impayée', '': 'Toutes' } },
    { cle: '__echeance__', titre: 'Échéance', options: { retard: 'En retard', '': 'Toutes' } },
];

test('aucun filtre : aucune ligne', () => {
    for (const vide of [{}, null, undefined]) {
        assert.deepEqual(filtresActifs(vide, { definitions: DEFS, groupes: GROUPES }), []);
    }
});

test('un chip se lit « Titre du groupe : libellé court », et passe AVANT les autres filtres', () => {
    const filtres = filtresActifs(
        { client: { value: '42', label: 'KIN AVIA' }, __prime__: { operator: '=', value: 'impayee', label: 'La prime est impayée' } },
        { definitions: DEFS, groupes: GROUPES },
    );
    assert.deepEqual(filtres.map((f) => f.texte), ['Prime : Impayée', 'Client : KIN AVIA']);
    assert.deepEqual(filtres.map((f) => f.cle), ['__prime__', 'client']);
});

test('un critère égal au défaut du serveur porte « par défaut » — il masque des données, il se DIT', () => {
    const filtres = filtresActifs(
        { __prime__: { value: 'impayee' }, client: { value: '42', label: 'KIN AVIA' } },
        { definitions: DEFS, groupes: GROUPES, defauts: { __prime__: { value: 'impayee' } } },
    );
    assert.deepEqual(filtres.map((f) => [f.texte, f.parDefaut]), [['Prime : Impayée', true], ['Client : KIN AVIA', false]]);
});

test('un chip sur « Toutes » (valeur vide) ne filtre rien : il n’apparaît pas', () => {
    assert.deepEqual(filtresActifs({ __prime__: { value: '' } }, { definitions: DEFS, groupes: GROUPES }), []);
});

test('un groupe SANS « Toutes » a un défaut que le serveur applique toujours : montré, sans ×', () => {
    // Production intermédiaires : le statut retombe sur « Souscrites » quand rien n'est posé.
    const groupes = [{ cle: '__statut__', titre: 'Statut', defaut: 'souscrites', options: { souscrites: 'Souscrites', en_attente: 'En attente' } }];
    assert.deepEqual(filtresActifs({}, { definitions: DEFS, groupes }), [
        { cle: '__statut__', texte: 'Statut : Souscrites', parDefaut: true, retirable: false },
    ]);
    // Posé explicitement à une autre valeur : un filtre ordinaire, retirable.
    assert.deepEqual(filtresActifs({ __statut__: { value: 'en_attente' } }, { definitions: DEFS, groupes }), [
        { cle: '__statut__', texte: 'Statut : En attente', parDefaut: false, retirable: true },
    ]);
    // Posé explicitement À SA VALEUR PAR DÉFAUT : c'est le défaut — le retirer n'y changerait rien.
    assert.deepEqual(filtresActifs({ __statut__: { value: 'souscrites' } }, { definitions: DEFS, groupes }), [
        { cle: '__statut__', texte: 'Statut : Souscrites', parDefaut: true, retirable: false },
    ]);
});

test('un critère IMPOSÉ par le serveur (la visibilité des congés) se lit, mais ne se retire pas', () => {
    // Le serveur le repose à chaque recherche : un × promettrait une liste que l'on ne verra pas.
    const filtres = filtresActifs(
        { __visibilite_conge__: { value: 'miennes', label: 'Mes demandes' }, client: { value: '42', label: 'KIN AVIA' } },
        { definitions: DEFS, groupes: [], imposes: ['__visibilite_conge__'] },
    );
    assert.deepEqual(filtres.map((f) => [f.cle, f.parDefaut, f.retirable]), [['__visibilite_conge__', true, false], ['client', false, true]]);
});

test('un chip-sélecteur (bénéficiaire) se lit par le NOM porté par le critère', () => {
    const groupes = [{ cle: '__beneficiaire__', titre: 'Bénéficiaire', options: { '': 'Tous' } }];
    const filtres = filtresActifs({ __beneficiaire__: { value: 'agent:12', label: 'Alice' } }, { definitions: DEFS, groupes });
    assert.equal(filtres[0].texte, 'Bénéficiaire : Alice');
});

test('un groupe de PRÉSENTATION (la maille de lecture, pas un filtre) n’apparaît jamais', () => {
    const groupes = [{ cle: '__virement__', titre: 'Affichage', presentation: true, options: { groupe: 'Groupé', detail: 'Détail' } }];
    assert.deepEqual(filtresActifs({ __virement__: { value: 'detail' } }, { definitions: DEFS, groupes }), []);
});

test('les autres filtres gardent le libellé du canevas, dans l’ordre reçu ; une clé inconnue sert de libellé', () => {
    const filtres = filtresActifs(
        { endingAt: { from: '01/01/2026', to: '31/01/2026' }, __mon_portefeuille__: { value: '7', label: 'Portefeuille de Serge' }, champFantome: 'abc' },
        { definitions: DEFS, groupes: [] },
    );
    assert.deepEqual(filtres.map((f) => f.texte), [
        'Échéance : du 01/01/2026 au 31/01/2026',
        'Mon portefeuille : Portefeuille de Serge',
        'champFantome : "abc"',
    ]);
    assert.ok(filtres.every((f) => f.retirable), 'chaque filtre ordinaire se retire par ×');
});

test('définitions et groupes absents : dégradation propre', () => {
    for (const absent of [null, undefined, 'pas un tableau']) {
        const filtres = filtresActifs({ client: { value: 'x' } }, { definitions: absent, groupes: absent });
        assert.equal(filtres[0].texte, 'client : "x"');
    }
});

// ── « Tout retirer » : la règle du chantier 6 (CauseDeListeVide), du côté de l'écran ─────────
test('« Tout retirer » : ce que la ligne AFFICHE comme retirable, sauf « Mon portefeuille » pour qui n’administre pas le cabinet', () => {
    const filtres = filtresActifs(
        {
            client: { value: '42' },
            __prime__: { value: 'impayee' },
            __mon_portefeuille__: { value: '7' },
            __virement__: { value: 'detail' },         // maille de lecture : jamais retirée
            __visibilite_conge__: { value: 'miennes' }, // imposé par le serveur : jamais retiré
        },
        {
            definitions: DEFS,
            groupes: [...GROUPES, { cle: '__virement__', titre: 'Affichage', presentation: true, options: {} }],
            imposes: ['__visibilite_conge__'],
        },
    );
    assert.deepEqual(clesARetirer(filtres, false), ['__prime__', 'client']);
    assert.deepEqual(clesARetirer(filtres, true), ['__prime__', 'client', '__mon_portefeuille__']);
    assert.deepEqual(clesARetirer([], true), []);
    assert.deepEqual(clesARetirer(null, false), []);
});

test('après « réinitialiser » (×, recherche avancée, état vide) : ne RESTENT que le non-retirable, et le portefeuille du collaborateur', () => {
    const criteres = { client: { value: '42' }, __prime__: { value: 'impayee' }, __mon_portefeuille__: { value: '7' }, __virement__: { value: 'detail' } };
    const filtres = filtresActifs(criteres, {
        definitions: DEFS,
        groupes: [...GROUPES, { cle: '__virement__', titre: 'Affichage', presentation: true, options: {} }],
    });
    assert.deepEqual(Object.keys(criteresApresRetrait(criteres, filtres, false)), ['__mon_portefeuille__', '__virement__']);
    assert.deepEqual(Object.keys(criteresApresRetrait(criteres, filtres, true)), ['__virement__']);
    // Rien d'affiché (avant le premier contexte) : rien ne part.
    assert.deepEqual(criteresApresRetrait(criteres, undefined, true), criteres);
    assert.deepEqual(criteresApresRetrait(null, [], true), {});
});

test('formatterTexteFiltre — plages de dates partielles', () => {
    const def = { Nom: 'endingAt', Display: 'Échéance', Type: 'DateTimeRange' };
    assert.equal(
        formatterTexteFiltre('Échéance', { from: '01/01/2026', to: '31/01/2026' }, def),
        'Échéance : du 01/01/2026 au 31/01/2026',
    );
    assert.equal(
        formatterTexteFiltre('Échéance', { from: '01/01/2026' }, def),
        'Échéance : à partir du 01/01/2026',
    );
    assert.equal(
        formatterTexteFiltre('Échéance', { to: '31/01/2026' }, def),
        "Échéance : jusqu'au 31/01/2026",
    );
});

test('formatterTexteFiltre — relation : on affiche le libellé, jamais l’id', () => {
    const def = { Nom: 'client', Display: 'Client', Type: 'Relation' };
    assert.equal(formatterTexteFiltre('Client', { value: '42', label: 'KIN AVIA' }, def), 'Client : KIN AVIA');
    // Sans libellé, repli sur la valeur brute plutôt qu'un badge vide.
    assert.equal(formatterTexteFiltre('Client', { value: '42' }, def), 'Client : 42');
});

test('formatterTexteFiltre — booléen : libellé métier issu du canvas', () => {
    const def = { Nom: 'actif', Display: 'Actif', Type: 'Boolean', Valeur: { 1: 'Oui', 0: 'Non' } };
    assert.equal(formatterTexteFiltre('Actif', '1', def), 'Actif : Oui');
    assert.equal(formatterTexteFiltre('Actif', { value: '0' }, def), 'Actif : Non');
});

test('formatterTexteFiltre — nombre : opérateur conservé', () => {
    const def = { Nom: 'primeTotale', Display: 'Prime totale', Type: 'Number' };
    assert.equal(
        formatterTexteFiltre('Prime totale', { operator: '>=', value: 1000 }, def),
        'Prime totale >= 1000',
    );
});

test('formatterTexteFiltre — texte et repli sans définition', () => {
    const def = { Nom: 'refPolice', Display: 'Réf. Police', Type: 'Text' };
    assert.equal(
        formatterTexteFiltre('Réf. Police', { operator: 'LIKE', value: 'POL-2026' }, def),
        'Réf. Police : "POL-2026"',
    );
    assert.equal(formatterTexteFiltre('Réf. Police', 'POL-2026', null), 'Réf. Police : "POL-2026"');
});

// ── LES CRITÈRES DE LA BARRE SIMPLE (la police d'une tranche, 2026-10-26) ──
test('criteresDeLaBarreSimple — un critère RAPIDE vient en tête, même au bout d’un chemin', () => {
    const defs = [
        { Nom: 'nom', Display: 'Nom', Type: 'Text' },
        { Nom: 'cotation', Display: 'Cotation', Type: 'Relation' },
        { Nom: 'cotation.assureur', Display: 'Assureur', Type: 'Relation' },
        { Nom: 'cotation.avenants.referencePolice', Display: 'Police', Type: 'Text', Rapide: true },
        { Nom: 'echeanceAt', Display: 'Échéance le', Type: 'DateTimeRange' },
        { Nom: '__mon_portefeuille__', Display: 'Mon portefeuille', Type: 'Relation' },
    ];
    assert.deepEqual(criteresDeLaBarreSimple(defs).map((c) => c.Display), ['Police', 'Nom', 'Cotation']);
});

test('criteresDeLaBarreSimple — sans critère rapide, l’ordre du canevas, champs directs seulement', () => {
    const defs = [
        { Nom: 'nom', Display: 'Nom', Type: 'Text' },
        { Nom: 'client.portefeuille', Display: 'Portefeuille', Type: 'Relation' },
        { Nom: 'client', Display: 'Client', Type: 'Relation' },
    ];
    assert.deepEqual(criteresDeLaBarreSimple(defs).map((c) => c.Display), ['Nom', 'Client']);
    assert.deepEqual(criteresDeLaBarreSimple(null), []);
});
