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

import { positionAuCurseur, positionSousLElement, positionCentreeSous, positionADroite } from '../../assets/controllers/infobulle-position.js';

const ECRAN = { largeur: 1366, hauteur: 768 };
const BULLE = { largeur: 280, hauteur: 300 };
const contenue = ({ left, top }, bulle = BULLE, ecran = ECRAN) =>
    left >= 8 && top >= 8 && left + bulle.largeur <= ecran.largeur - 8 && top + bulle.hauteur <= ecran.hauteur - 8;

test('au milieu de l’écran : en haut à gauche du pointeur, TOUT CONTRE lui (4 px : le chemin vers la bulle ne traverse rien)', () => {
    assert.deepEqual(positionAuCurseur({ x: 700, y: 500 }, BULLE, ECRAN), { left: 416, top: 196 });
});

test('près du bord gauche ou haut : de l’autre côté du pointeur', () => {
    const p = positionAuCurseur({ x: 100, y: 100 }, BULLE, ECRAN);
    assert.deepEqual(p, { left: 104, top: 104 });
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

test('sous un bouton de barre d’actions : CENTRÉE sous lui (les boutons voisins restent visibles)', () => {
    const bouton = { left: 500, top: 60, bottom: 92, width: 32 };
    assert.deepEqual(positionCentreeSous(bouton, { largeur: 120, hauteur: 30 }, ECRAN), { left: 456, top: 100 });
});

test('sous un bouton : au-dessus s’il manque de place, et jamais hors de l’écran', () => {
    const enBas = positionCentreeSous({ left: 500, top: 740, bottom: 764, width: 32 }, { largeur: 120, hauteur: 30 }, ECRAN);
    assert.equal(enBas.top, 702);
    for (const left of [0, 4, 1340, 1366]) {
        const p = positionCentreeSous({ left, top: 60, bottom: 92, width: 24 }, BULLE, ECRAN);
        assert.ok(contenue(p), `bouton à ${left} → ${JSON.stringify(p)}`);
    }
});

// La bulle est SURVOLABLE (pointer-events) : posée SUR le pointeur, elle prendrait le clic destiné
// à l'élément. Revue du chantier 3, commit 1.
const couvre = ({ left, top }, bulle, { x, y }) => x >= left && x <= left + bulle.largeur && y >= top && y <= top + bulle.hauteur;

test('jamais SUR le pointeur, où qu’il soit — tant qu’un côté a la place', () => {
    for (const x of [0, 5, 150, 683, 1200, 1361, 1366]) {
        for (const y of [0, 5, 150, 384, 600, 763, 768]) {
            const p = positionAuCurseur({ x, y }, BULLE, ECRAN);
            assert.ok(!couvre(p, BULLE, { x, y }), `pointeur (${x}, ${y}) couvert par ${JSON.stringify(p)}`);
            assert.ok(contenue(p), `pointeur (${x}, ${y}) → ${JSON.stringify(p)}`);
        }
    }
});

test('écran ÉTROIT (pas la place à gauche ni à droite) : la bulle passe au-dessus ou au-dessous, jamais sur lui', () => {
    const etroit = { largeur: 400, hauteur: 700 };
    const haute = { largeur: 280, hauteur: 330 }; // ni à gauche ni à droite ; au-dessus OU au-dessous selon le pointeur
    for (const x of [10, 120, 200, 300, 390]) {
        for (const y of [20, 200, 350, 500, 690]) {
            const p = positionAuCurseur({ x, y }, haute, etroit);
            assert.ok(!couvre(p, haute, { x, y }), `pointeur (${x}, ${y}) couvert par ${JSON.stringify(p)}`);
            assert.ok(contenue(p, haute, etroit), `pointeur (${x}, ${y}) → ${JSON.stringify(p)}`);
        }
    }
});

// LE RAIL D'OUTILS DE LA COLONNE 3 (décision de Serge, 2026-10-28) : la bulle à DROITE du bouton.
// Dessous, elle recouvrait le bouton suivant du rail.
test('à droite d’un bouton du rail : centrée sur sa hauteur, sans recouvrir le rail', () => {
    const bouton = { left: 134, right: 169, top: 250, bottom: 285, width: 35, height: 35 };
    assert.deepEqual(positionADroite(bouton, { largeur: 120, hauteur: 30 }, ECRAN), { left: 177, top: 252.5 });
});

test('à droite d’un bouton : à GAUCHE s’il manque de place, et jamais hors de l’écran', () => {
    const auBord = positionADroite({ left: 1300, right: 1335, top: 250, bottom: 285, width: 35, height: 35 }, { largeur: 120, hauteur: 30 }, ECRAN);
    assert.equal(auBord.left, 1300 - 120 - 8);
    for (const top of [0, 4, 400, 740, 768]) {
        const p = positionADroite({ left: 134, right: 169, top, bottom: top + 35, width: 35, height: 35 }, BULLE, ECRAN);
        assert.ok(contenue(p), `bouton à ${top} → ${JSON.stringify(p)}`);
    }
});
