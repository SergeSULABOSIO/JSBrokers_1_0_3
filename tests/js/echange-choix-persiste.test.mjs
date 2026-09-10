/**
 * Tests des CLÉS DE RANGEMENT des chips de la rubrique Importation
 * (assets/controllers/echange-perimetre-persiste.js) — logique pure, ni DOM ni stockage.
 *
 * LA RÈGLE : tout chip qu'un utilisateur peut cliquer survit au F5. Sans exception.
 * Un chip est un CHOIX, et refaire un choix à chaque rechargement finit par dissuader
 * d'en faire — on reprend alors le réglage par défaut faute de courage, ce qui revient à
 * ne pas offrir le réglage du tout.
 *
 * ⚠ LA RESTAURATION ELLE-MÊME EST TESTÉE AILLEURS. `choixARestaurer()` a déménagé dans
 * `choix-persiste.js` le jour où le tableau de bord a eu le même besoin ; ses cas vivent
 * donc dans `choix-persiste.test.mjs`. Les répéter ici ferait deux endroits à tenir en
 * accord pour une seule règle.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { cleDuChoix } from '../../assets/controllers/echange-perimetre-persiste.js';

test('la clé sépare les cabinets, les onglets ET les réglages', () => {
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(2, 'exporter', 'validite'));
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(1, 'importer', 'validite'));

    // Deux réglages du même écran ne doivent pas se marcher dessus : choisir un exercice
    // n'a jamais voulu dire choisir une validité.
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(1, 'exporter', 'exercice'));
});

test('la clé est stable d’une visite à l’autre', () => {
    assert.equal(cleDuChoix(3, 'importer', 'exercice'), cleDuChoix(3, 'importer', 'exercice'));
});
