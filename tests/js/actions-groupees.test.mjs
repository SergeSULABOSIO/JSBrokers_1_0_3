/**
 * Tests de la RÈGLE de regroupement des actions spécifiques par famille
 * (assets/controllers/actions-groupees.js) — logique pure, aucun DOM.
 *
 * Ce qui est protégé ici : la barre d'outils et le menu contextuel partagent cette
 * règle. Si elle divergeait, les deux surfaces proposeraient des regroupements
 * différents pour les mêmes actions — et l'utilisateur ne s'y retrouverait plus.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { grouperActions, urlAction, GROUPE_DEBORDEMENT } from '../../assets/controllers/actions-groupees.js';

const action = (label, groupe = null, extra = {}) => ({
    label,
    icon: 'action:edit',
    event: 'ui:test',
    url: '/x/%id%',
    ...(groupe ? { groupe } : {}),
    ...extra,
});

test('sans famille déclarée, rien ne change (non-régression)', () => {
    const entrees = grouperActions([action('A'), action('B'), action('C')]);

    assert.equal(entrees.length, 3);
    assert.deepEqual(entrees.map((e) => e.type), ['action', 'action', 'action']);
    assert.equal(entrees[0].action.label, 'A');
});

test('une famille de plusieurs actions devient UNE entrée qui les porte', () => {
    const entrees = grouperActions([
        action('Renouveler', 'Mouvements'),
        action('Proroger', 'Mouvements'),
        action('Résilier', 'Mouvements'),
        action('Voir les documents'),
    ]);

    assert.equal(entrees.length, 2, 'trois actions repliées + une action libre');
    assert.equal(entrees[0].type, 'groupe');
    assert.equal(entrees[0].label, 'Mouvements');
    assert.equal(entrees[0].actions.length, 3);
    assert.equal(entrees[1].type, 'action');
    assert.equal(entrees[1].action.label, 'Voir les documents');
});

test("l'ordre de première apparition est conservé", () => {
    const entrees = grouperActions([
        action('Libre'),
        action('M1', 'Mouvements'),
        action('Doc', 'Documents'),
        action('M2', 'Mouvements'),
        action('Doc2', 'Documents'),
    ]);

    assert.deepEqual(entrees.map((e) => (e.type === 'groupe' ? e.label : e.action.label)), [
        'Libre', 'Mouvements', 'Documents',
    ]);
    assert.equal(entrees[1].actions.length, 2);
});

test('une famille réduite à UN membre est remise à plat — un clic de plus pour rien', () => {
    // Cas réel : les conditions d'affichage ne laissent qu'un seul membre visible.
    const entrees = grouperActions([action('Éditer la piste dérivée', 'Mouvements'), action('Documents')]);

    assert.equal(entrees[0].type, 'action');
    assert.equal(entrees[0].action.label, 'Éditer la piste dérivée');
});

test("l'icône de famille vient de groupe_icone, sinon du premier membre", () => {
    const avec = grouperActions([
        action('A', 'Fam', { icon: 'action:add' }),
        action('B', 'Fam', { groupe_icone: 'action:renew' }),
    ]);
    assert.equal(avec[0].icon, 'action:renew', 'groupe_icone gagne, quel que soit le membre qui le porte');

    const sans = grouperActions([action('A', 'Fam', { icon: 'action:add' }), action('B', 'Fam')]);
    assert.equal(sans[0].icon, 'action:add', 'à défaut, celle du premier membre');
});

test('au-delà du plafond, le surplus est replié dans « Autres actions »', () => {
    const entrees = grouperActions(
        [action('A'), action('B'), action('C'), action('D'), action('E'), action('F')],
        { maxInline: 4 },
    );

    assert.equal(entrees.length, 4, 'la barre garde une largeur prévisible');
    assert.deepEqual(entrees.slice(0, 3).map((e) => e.action.label), ['A', 'B', 'C']);
    assert.equal(entrees[3].type, 'groupe');
    assert.equal(entrees[3].label, GROUPE_DEBORDEMENT);
    assert.deepEqual(entrees[3].actions.map((a) => a.label), ['D', 'E', 'F'], 'aucune action perdue');
});

test('une famille qui déborde garde son nom en préfixe', () => {
    const entrees = grouperActions(
        [action('A'), action('B'), action('C'), action('M1', 'Mouvements'), action('M2', 'Mouvements')],
        { maxInline: 3 },
    );

    // maxInline 3 → 2 entrées gardées (A, B), le reste déborde : « C » ET la famille.
    assert.equal(entrees.length, 3);
    const debordement = entrees[entrees.length - 1];
    assert.equal(debordement.label, GROUPE_DEBORDEMENT);
    assert.deepEqual(
        debordement.actions.map((a) => a.label),
        ['C', 'Mouvements · M1', 'Mouvements · M2'],
        'les membres d’une famille repliée restent identifiables par leur préfixe',
    );
});

test('sans plafond, aucune entrée n’est repliée (cas du menu contextuel qui défile)', () => {
    const actions = Array.from({ length: 9 }, (_, i) => action(`A${i}`));
    assert.equal(grouperActions(actions).length, 9);
});

test('grouperActions tolère une liste absente', () => {
    assert.deepEqual(grouperActions(undefined), []);
    assert.deepEqual(grouperActions(null), []);
    assert.deepEqual(grouperActions([]), []);
});

test('urlAction ne substitue %id% que s’il est présent', () => {
    assert.equal(urlAction({ url: '/a/%id%/b' }, 42), '/a/42/b');
    assert.equal(urlAction({ url: '/a/b' }, 42), '/a/b', 'URL sans jeton : inchangée');
});
