/**
 * Tests du placement de l'infobulle sombre (infobulle-position.js).
 * Lancement : node --test tests/js/
 *
 * Ce qu'ils protègent : l'infobulle se lit EN ENTIER, où que soit le pointeur. La règle
 * reprise du tableau de bord ne regardait que les bords haut et gauche ; appelée près du
 * bas de l'écran, une infobulle haute débordait (incident du 2026-10-13, carte de piste).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { positionAuCurseur, positionSousLElement } from '../../assets/controllers/infobulle-position.js';

const ECRAN = { largeur: 1366, hauteur: 768 };
const BULLE = { largeur: 280, hauteur: 300 };
const contenue = ({ left, top }, bulle = BULLE, ecran = ECRAN) =>
    left >= 8 && top >= 8 && left + bulle.largeur <= ecran.largeur - 8 && top + bulle.hauteur <= ecran.hauteur - 8;

test('au milieu de l’écran : en haut à gauche du pointeur, à 10 px (la règle du tableau de bord)', () => {
    assert.deepEqual(positionAuCurseur({ x: 700, y: 500 }, BULLE, ECRAN), { left: 410, top: 190 });
});

test('près du bord gauche ou haut : de l’autre côté du pointeur', () => {
    const p = positionAuCurseur({ x: 100, y: 100 }, BULLE, ECRAN);
    assert.deepEqual(p, { left: 110, top: 110 });
    assert.ok(contenue(p));
});

test('l’incident : bulle haute, pointeur vers le bas au milieu — elle reste DANS l’écran', () => {
    // Trop haute pour tenir au-dessus (y − h − 10 < 8) : elle passait dessous et débordait.
    const p = positionAuCurseur({ x: 600, y: 250 }, { largeur: 280, hauteur: 600 }, ECRAN);
    assert.ok(contenue(p, { largeur: 280, hauteur: 600 }), JSON.stringify(p));
});

test('dans chaque coin de l’écran, la bulle se lit en entier', () => {
    for (const x of [0, 5, 683, 1361, 1366]) {
        for (const y of [0, 5, 384, 763, 768]) {
            const p = positionAuCurseur({ x, y }, BULLE, ECRAN);
            assert.ok(contenue(p), `pointeur (${x}, ${y}) → ${JSON.stringify(p)}`);
        }
    }
});

test('plus haute que l’écran : c’est son DÉBUT qui reste visible', () => {
    assert.equal(positionAuCurseur({ x: 700, y: 700 }, { largeur: 280, hauteur: 900 }, ECRAN).top, 8);
});

test('au clavier : sous l’élément, au-dessus s’il manque de place, toujours dans l’écran', () => {
    assert.deepEqual(positionSousLElement({ left: 100, top: 100, bottom: 140 }, BULLE, ECRAN), { left: 100, top: 148 });
    const enBas = positionSousLElement({ left: 100, top: 600, bottom: 640 }, BULLE, ECRAN);
    assert.equal(enBas.top, 292);
    const aDroite = positionSousLElement({ left: 1300, top: 100, bottom: 140 }, BULLE, ECRAN);
    assert.ok(contenue(aDroite), JSON.stringify(aDroite));
});
