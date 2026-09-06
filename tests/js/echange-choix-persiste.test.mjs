/**
 * Tests de la PERSISTANCE DES CHIPS À CHOIX UNIQUE
 * (assets/controllers/echange-perimetre-persiste.js) — logique pure, ni DOM ni stockage.
 *
 * LA RÈGLE : tout chip qu'un utilisateur peut cliquer survit au F5. Sans exception.
 * Un chip est un CHOIX, et refaire un choix à chaque rechargement finit par dissuader
 * d'en faire — on reprend alors le réglage par défaut faute de courage, ce qui revient à
 * ne pas offrir le réglage du tout.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    choixARestaurer,
    cleDuChoix,
} from '../../assets/controllers/echange-perimetre-persiste.js';

test('la clé sépare les cabinets, les onglets ET les réglages', () => {
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(2, 'exporter', 'validite'));
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(1, 'importer', 'validite'));

    // Deux réglages du même écran ne doivent pas se marcher dessus : choisir un exercice
    // n'a jamais voulu dire choisir une validité.
    assert.notEqual(cleDuChoix(1, 'exporter', 'validite'), cleDuChoix(1, 'exporter', 'exercice'));
});

test('un choix encore proposé est reposé tel quel', () => {
    assert.equal(choixARestaurer('souscrites', ['toutes', 'souscrites', 'caduques']), 'souscrites');
});

/**
 * ⚠ LE CAS QUI COMPTE : un choix peut DISPARAÎTRE entre deux visites.
 *
 * L'exercice 2025 mémorisé n'a plus de chip le jour où la dernière police de 2025 est
 * supprimée. Le reposer laisserait un réglage actif que rien à l'écran ne montre — et un
 * fichier vide sans la moindre explication.
 */
test('un choix qui n’est plus proposé retombe sur le défaut', () => {
    assert.equal(choixARestaurer('2019', ['tous', '2025', '2026']), null);
});

test('un stockage vide ou corrompu se lit comme une absence de choix', () => {
    assert.equal(choixARestaurer(null, ['tous', '2026']), null);
    assert.equal(choixARestaurer(42, ['tous', '2026']), null);
    assert.equal(choixARestaurer('2026', null), null);
});
