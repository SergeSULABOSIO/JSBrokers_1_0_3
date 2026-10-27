/**
 * Tests du DÉFILEMENT DU RAIL D'OUTILS (assets/controllers/rail-defilement.js) —
 * logique pure, aucun DOM.
 *
 * Le rail de la colonne 3 tient dans le cas courant (mesuré : 9 boutons à 595 px de haut,
 * 6 px de marge). Au-delà — fenêtre plus basse, plus de 4 actions —, il défile en DERNIER
 * RECOURS (décision de Serge) : la molette, le doigt et le focus clavier le font nativement ;
 * il reste à DIRE qu'il y a autre chose au-dessus ou au-dessous (flèches et fondus), sans
 * jamais l'annoncer quand tout tient.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { etatDuDefilement } from '../../assets/controllers/rail-defilement.js';

test('tout tient : ni flèche ni fondu', () => {
    assert.deepEqual(etatDuDefilement({ haut: 0, contenu: 351, visible: 357 }), { avant: false, apres: false });
});

test('le contenu dépasse, en haut du rail : seulement « plus bas »', () => {
    assert.deepEqual(etatDuDefilement({ haut: 0, contenu: 420, visible: 300 }), { avant: false, apres: true });
});

test('au milieu : les deux', () => {
    assert.deepEqual(etatDuDefilement({ haut: 60, contenu: 420, visible: 300 }), { avant: true, apres: true });
});

test('au bout : seulement « plus haut »', () => {
    assert.deepEqual(etatDuDefilement({ haut: 120, contenu: 420, visible: 300 }), { avant: true, apres: false });
});

test('les fractions de pixel ne rallument pas une flèche au bout de la course', () => {
    assert.deepEqual(etatDuDefilement({ haut: 119.4, contenu: 420, visible: 300 }), { avant: true, apres: false });
    assert.deepEqual(etatDuDefilement({ haut: 0.6, contenu: 420, visible: 300 }), { avant: false, apres: true });
    assert.deepEqual(etatDuDefilement({ haut: 0, contenu: 300.8, visible: 300 }), { avant: false, apres: false });
});
