/**
 * Tests du cœur PUR du menu flottant (assets/controllers/menu-flottant.js) —
 * aucun DOM, aucun viewport réel.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { MARGE_VIEWPORT, positionnerMenu, indexApresTouche } from '../../assets/controllers/menu-flottant.js';

/** Viewport de référence : colonne 4 du workspace sur un écran courant. */
const VIEWPORT = { largeur: 1440, hauteur: 900 };
const MENU = { largeur: 200, hauteur: 180 };

test('positionnerMenu pose le menu sous l\'ancre, aligné à droite', () => {
    const ancre = { left: 1000, right: 1026, top: 300, bottom: 326 };
    const { left, top } = positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT });

    assert.equal(left, 1026 - 200); // bord droit du menu = bord droit de l'ancre
    assert.equal(top, 330); // juste dessous (4 px de respiration)
});

test('positionnerMenu bascule au-dessus quand il déborderait en bas', () => {
    // Bulle en bas du fil : 800 + 4 + 180 = 984 > 900 - marge.
    const ancre = { left: 1000, right: 1026, top: 774, bottom: 800 };
    const { top } = positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT });

    assert.equal(top, 774 - 180 - 4);
    assert.ok(top >= MARGE_VIEWPORT);
});

test('positionnerMenu écrête au bord droit du viewport', () => {
    // Ancre collée au bord droit : l'alignement seul sortirait de l'écran.
    const ancre = { left: 1438, right: 1440, top: 300, bottom: 326 };
    const { left } = positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT });

    assert.equal(left, 1440 - 200 - MARGE_VIEWPORT);
});

test('positionnerMenu écrête au bord gauche du viewport', () => {
    // Colonne étroite (≈450 px) et menu large : l'alignement à droite donnerait
    // une valeur négative.
    const ancre = { left: 20, right: 46, top: 300, bottom: 326 };
    const { left } = positionnerMenu({ ancre, menu: { largeur: 300, hauteur: 180 }, viewport: { largeur: 450, hauteur: 900 } });

    assert.equal(left, MARGE_VIEWPORT);
});

test('positionnerMenu colle en haut à gauche si le menu dépasse le viewport', () => {
    // Cas dégénéré : menu plus grand que l'écran → on garde le DÉBUT visible.
    const ancre = { left: 100, right: 126, top: 400, bottom: 426 };
    const { left, top } = positionnerMenu({
        ancre,
        menu: { largeur: 800, hauteur: 1200 },
        viewport: { largeur: 600, hauteur: 500 },
    });

    assert.equal(left, MARGE_VIEWPORT);
    assert.equal(top, MARGE_VIEWPORT);
});

test('positionnerMenu accepte une ancre 0×0 : le clic droit au curseur', () => {
    // C'est ce qui permet au kebab et au clic droit de partager UNE géométrie.
    const curseur = { left: 700, right: 700, top: 400, bottom: 400 };
    const { left, top } = positionnerMenu({ ancre: curseur, menu: MENU, viewport: VIEWPORT });

    assert.equal(left, 700 - 200);
    assert.equal(top, 404);
});

test('positionnerMenu honore une marge personnalisée', () => {
    const ancre = { left: 1438, right: 1440, top: 300, bottom: 326 };
    const { left } = positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT, marge: 24 });

    assert.equal(left, 1440 - 200 - 24);
});

test('indexApresTouche descend et boucle', () => {
    assert.equal(indexApresTouche('ArrowDown', 0, 4), 1);
    assert.equal(indexApresTouche('ArrowDown', 3, 4), 0);
});

test('indexApresTouche remonte et boucle', () => {
    assert.equal(indexApresTouche('ArrowUp', 3, 4), 2);
    assert.equal(indexApresTouche('ArrowUp', 0, 4), 3);
});

test('indexApresTouche entre par le premier item en bas, par le dernier en haut', () => {
    // -1 = aucun item focalisé (ouverture à la souris : le focus est resté sur
    // le déclencheur).
    assert.equal(indexApresTouche('ArrowDown', -1, 4), 0);
    assert.equal(indexApresTouche('ArrowUp', -1, 4), 3);
});

test('indexApresTouche gère Home et End', () => {
    assert.equal(indexApresTouche('Home', 2, 4), 0);
    assert.equal(indexApresTouche('End', 2, 4), 3);
    assert.equal(indexApresTouche('Home', -1, 4), 0);
});

test('indexApresTouche sur un seul item reste dessus', () => {
    for (const touche of ['ArrowDown', 'ArrowUp', 'Home', 'End']) {
        assert.equal(indexApresTouche(touche, 0, 1), 0);
    }
});

test('indexApresTouche renvoie null sans item navigable', () => {
    // Menu entièrement filtré (data-menu-roles) : l'événement doit filer.
    assert.equal(indexApresTouche('ArrowDown', -1, 0), null);
    assert.equal(indexApresTouche('ArrowDown', -1, -3), null);
    assert.equal(indexApresTouche('ArrowDown', -1, 2.5), null);
});

test('indexApresTouche renvoie null pour les touches non gérées', () => {
    // Tab, Escape et Enter sont traités ailleurs : ne pas les capter ici.
    for (const touche of ['a', 'Tab', 'Escape', 'Enter', ' ', 'ArrowLeft', 'PageDown']) {
        assert.equal(indexApresTouche(touche, 1, 4), null);
    }
});

/**
 * ALIGNEMENT À GAUCHE — pour le chip-sélecteur d'un filtre.
 *
 * Le menu de bulle est ancré à un déclencheur posé en haut à DROITE de sa zone ; une chip
 * de filtre, elle, commence à gauche. Aligner son panneau à droite le ferait partir en
 * arrière du geste. L'option existe pour cela, et le défaut reste « droite » : aucun
 * appelant existant ne doit bouger.
 */
test('positionnerMenu aligne à gauche sur demande, et à droite par défaut', () => {
    const ancre = { left: 1000, right: 1026, top: 300, bottom: 326 };

    assert.equal(positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT, alignement: 'gauche' }).left, 1000);
    assert.equal(positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT }).left, 1026 - 200);
});

test('l\'écrêtage l\'emporte aussi sur l\'alignement à gauche', () => {
    // Une chip tout à droite : aligné à gauche, le panneau sortirait du viewport. Il doit
    // être ramené dans les marges plutôt que de déborder — sinon la moitié des agents est
    // hors de l'écran, ce que le défaut d'empilement avait déjà fait subir à cette liste.
    const ancre = { left: 1400, right: 1430, top: 300, bottom: 326 };
    const { left } = positionnerMenu({ ancre, menu: MENU, viewport: VIEWPORT, alignement: 'gauche' });

    assert.equal(left, VIEWPORT.largeur - MENU.largeur - MARGE_VIEWPORT);
    assert.ok(left + MENU.largeur <= VIEWPORT.largeur);
});
