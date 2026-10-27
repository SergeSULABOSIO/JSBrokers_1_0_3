/**
 * Tests des LARGEURS FIGÉES PENDANT UN CHARGEMENT
 * (assets/controllers/colonnes-figees.js) — logique pure, aucun DOM.
 *
 * Les colonnes de montants prennent la largeur de leur contenu. Pendant une recherche,
 * les lignes laissent place à un squelette qui n'en a aucun : sans largeurs figées, les
 * colonnes s'effondraient puis revenaient — la liste sautait à chaque recherche, page,
 * rafraîchissement. On fige donc les largeurs AVANT le squelette, on les libère au retour
 * des lignes.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { largeursAFiger } from '../../assets/controllers/colonnes-figees.js';

test('les largeurs affichées sont figées, arrondies au pixel', () => {
    assert.deepEqual(largeursAFiger([80.4, 612.6, 134, 98.2], false), [80, 613, 134, 98]);
});

test('déjà figées (second chargement pendant le squelette) : rien — on garderait les largeurs du squelette', () => {
    assert.equal(largeursAFiger([80, 300, 40, 40], true), null);
});

test('liste masquée (état vide) : rien à figer — des largeurs nulles écraseraient le tableau', () => {
    assert.equal(largeursAFiger([0, 0, 0], false), null);
    assert.equal(largeursAFiger([], false), null);
});
