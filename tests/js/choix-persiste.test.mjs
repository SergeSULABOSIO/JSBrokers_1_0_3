/**
 * Tests du module GÉNÉRIQUE de persistance des chips à choix unique
 * (assets/controllers/choix-persiste.js) — logique pure, ni DOM ni stockage.
 *
 * LA RÈGLE : tout chip qu'un utilisateur peut cliquer survit au F5. Sans exception.
 *
 * Ces règles vivaient dans la rubrique Importation ; le tableau de bord a exactement le
 * même besoin — mémoriser un exercice comptable —, alors elles ont déménagé ici plutôt que
 * d'être réécrites. L'ancien point d'entrée les ré-exporte, et ses tests le prouvent.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    choixARestaurer,
    cleDuTableauDeBord,
} from '../../assets/controllers/choix-persiste.js';

test('la clé du tableau de bord sépare les cabinets ET les réglages', () => {
    assert.notEqual(cleDuTableauDeBord(1, 'exercice'), cleDuTableauDeBord(2, 'exercice'));
    assert.notEqual(cleDuTableauDeBord(1, 'exercice'), cleDuTableauDeBord(1, 'devise'));
});

/**
 * ⚠ ELLE NE PORTE PAS L'ONGLET, ET C'EST DÉLIBÉRÉ. L'identifiant d'un onglet de travail est
 * fabriqué à la volée (`ws-tab-<horodatage>-<aléa>`) : il change à chaque ouverture. S'y
 * indexer reviendrait à ne jamais rien retrouver — le réglage semblerait ne pas survivre au
 * F5 alors qu'il aurait bien été écrit.
 */
test('la clé est stable d’une visite à l’autre pour un même cabinet', () => {
    assert.equal(cleDuTableauDeBord(7, 'exercice'), cleDuTableauDeBord(7, 'exercice'));
    assert.equal(cleDuTableauDeBord('7', 'exercice'), cleDuTableauDeBord(7, 'exercice'));
});

test('un exercice encore proposé est reposé tel quel', () => {
    assert.equal(choixARestaurer('2025', ['2026', '2025', '2024']), '2025');
});

/**
 * ⚠ LE CAS QUI COMPTE : un exercice peut DISPARAÎTRE entre deux visites — la dernière
 * police de 2024 supprimée, et le chip s'en va. Le reposer laisserait un réglage actif que
 * rien à l'écran ne montre, et un tableau de bord vide sans la moindre explication.
 */
test('un exercice qui n’est plus proposé retombe sur le défaut', () => {
    assert.equal(choixARestaurer('2019', ['2026', '2025']), null);
});

test('un stockage vide ou corrompu se lit comme une absence de choix', () => {
    assert.equal(choixARestaurer(null, ['2026']), null);
    assert.equal(choixARestaurer(2026, ['2026']), null, 'Un nombre n’est pas une chaîne : le dataset rend toujours du texte.');
    assert.equal(choixARestaurer('2026', null), null);
});
