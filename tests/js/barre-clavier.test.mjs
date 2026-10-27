/**
 * Tests de la NAVIGATION AU CLAVIER d'une barre d'outils
 * (assets/controllers/barre-clavier.js) — logique pure, aucun DOM.
 *
 * Le rail d'outils de la colonne 3 se déclare vertical (`aria-orientation`) : un lecteur
 * d'écran l'annonce ainsi, et l'utilisateur appuie sur ↓. La barre ne répondait qu'à ← et →
 * (relevé par la revue de la refonte de la colonne 3).
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { indexVise } from '../../assets/controllers/barre-clavier.js';

test('barre verticale : ↓ et ↑ parcourent, en boucle', () => {
    assert.equal(indexVise('ArrowDown', 0, 3, true), 1);
    assert.equal(indexVise('ArrowDown', 2, 3, true), 0);
    assert.equal(indexVise('ArrowUp', 0, 3, true), 2);
});

test('barre verticale : ← et → ne la concernent pas', () => {
    assert.equal(indexVise('ArrowRight', 0, 3, true), null);
    assert.equal(indexVise('ArrowLeft', 1, 3, true), null);
});

test('barre horizontale (dialogue) : inchangée — ← et →, pas ↑ ni ↓', () => {
    assert.equal(indexVise('ArrowRight', 0, 3, false), 1);
    assert.equal(indexVise('ArrowLeft', 0, 3, false), 2);
    assert.equal(indexVise('ArrowDown', 0, 3, false), null);
});

test('Début et Fin vont aux extrémités, dans les deux sens', () => {
    for (const vertical of [true, false]) {
        assert.equal(indexVise('Home', 2, 4, vertical), 0);
        assert.equal(indexVise('End', 0, 4, vertical), 3);
    }
});

test('aucun arrêt, ou focus hors de la barre : rien', () => {
    assert.equal(indexVise('ArrowDown', 0, 0, true), null);
    assert.equal(indexVise('ArrowDown', -1, 3, true), null);
});
