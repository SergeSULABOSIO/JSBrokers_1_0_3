/**
 * Tests de la PLACE DES OUTILS DE RUBRIQUE dans le rail (assets/controllers/rail-outils.js) —
 * logique pure, aucun DOM.
 *
 * Sélectionner tout, Actualiser, Paramètres et Quitter : AUSSITÔT qu'un espace se dégage dans le
 * rail, les outils cachés y reviennent, autant que possible, dans l'ordre ; ceux qui n'ont pas de
 * place restent dans « Plus », qui disparaît quand tous tiennent (décision de Serge, 2026-10-28 —
 * elle remplace le regroupement permanent du plan). Sans sélection, le rail ne portait
 * qu'« Ajouter » et un « ⋮ » : beaucoup de place vide, et quatre outils cachés pour rien.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { outilsAuRail } from '../../assets/controllers/rail-outils.js';

// Mesures à 1 358 × 595 : 357 px utiles ; un bouton 35 px + 2 px d'écart.
const BOUTON = 37;
const mesure = (disponible, base) => ({ disponible, base, bouton: BOUTON, nombre: 4 });

test('aucune sélection (« Ajouter » seul) : les quatre outils au rail, et plus de « Plus »', () => {
    assert.equal(outilsAuRail(mesure(357, 8 + 37 + 9)), 4);
});

test('il manque un peu de place : autant d’outils que possible au rail, le reste dans « Plus »', () => {
    // base 215 : les 4 outils demanderaient 215 + 148 = 363 > 357 (+1 de tolérance) ; avec
    // « Plus » (37), 3 outils = 363, trop ; 2 outils = 215 + 74 + 37 = 326, ils tiennent.
    assert.equal(outilsAuRail(mesure(357, 215)), 2);
});

test('sélection riche dans une fenêtre basse : aucun outil au rail, tous dans « Plus »', () => {
    assert.equal(outilsAuRail(mesure(357, 8 + 8 * 37 + 18)), 0);
});

test('grande fenêtre : même avec la sélection riche, les quatre tiennent', () => {
    assert.equal(outilsAuRail(mesure(731, 8 + 8 * 37 + 18)), 4);
});

test('« Plus » disparaît SI ET SEULEMENT SI les quatre tiennent sans lui ; sinon il a sa place', () => {
    for (let base = 0; base < 400; base += 1) {
        const n = outilsAuRail(mesure(357, base));
        const quatreTiennent = base + 4 * BOUTON <= 357 + 1;
        assert.equal(n === 4, quatreTiennent, `base ${base} → ${n}`);
        if (n > 0 && n < 4) {
            // Avec « Plus », ce qui est posé tient, et un outil de plus ne tiendrait pas.
            assert.ok(base + n * BOUTON + BOUTON <= 358, `base ${base} : ${n} outils + « Plus » débordent`);
            assert.ok(base + (n + 1) * BOUTON + BOUTON > 358, `base ${base} : un outil de plus tenait`);
        }
    }
});

test('juste à la limite : 1 px de tolérance (les hauteurs sont fractionnaires)', () => {
    assert.equal(outilsAuRail(mesure(300, 300 - 148 + 0.6)), 4);
});

test('rail pas encore affiché (onglet en arrière-plan) : aucune décision', () => {
    assert.equal(outilsAuRail(mesure(0, 0)), null);
    assert.equal(outilsAuRail({ ...mesure(357, 45), bouton: 0 }), null);
});
