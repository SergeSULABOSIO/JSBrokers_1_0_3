/**
 * LA LISTE VIDE DIT POURQUOI (chantier 6) — contrôle croisé du branchement navigateur.
 * La DÉCISION vit côté serveur (App\Service\Liste\CauseDeListeVide, tests/Workspace/
 * ListeVideExpliqueeTest.php) ; le navigateur installe l'état vide rendu et retire EXACTEMENT
 * les critères que le serveur a mis sur le bouton.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { criteresSans } from '../../assets/controllers/fiche-hors-filtre.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (nom) => readFileSync(join(RACINE, 'assets', 'controllers', `${nom}_controller.js`), 'utf8');

test('retirer les critères désignés garde les autres intacts', () => {
    const criteres = { __mon_portefeuille__: { value: 7 }, nom: { value: 'x' }, __echeance_avenant__: { value: 'echus' } };
    assert.deepEqual(Object.keys(criteresSans(criteres, ['nom'])).sort(), ['__echeance_avenant__', '__mon_portefeuille__']);
    assert.deepEqual(Object.keys(criteresSans(criteres, ['__mon_portefeuille__'])).sort(), ['__echeance_avenant__', 'nom']);
});

test('le bouton de l’état vide envoie au cerveau les clés que le serveur y a posées', () => {
    const code = source('list-manager');
    assert.match(code, /retirerFiltres\(event\) \{[\s\S]*?event\?\.params\?\.cles[\s\S]*?'ui:liste-vide\.retirer-filtres'/);
});

test('le cerveau retire ces critères sur l’onglet demandeur et relance la recherche', () => {
    const code = source('cerveau');
    assert.match(code, /case 'ui:liste-vide\.retirer-filtres':\s*this\._retirerLesFiltresDeLaListeVide\(payload\);/);
    assert.match(code, /_retirerLesFiltresDeLaListeVide\(payload\) \{[\s\S]*?criteresSans\([\s\S]*?_handleSearchRequest\(/);
});

test('l’état vide rendu par le serveur voyage avec la réponse et s’installe dans la liste', () => {
    assert.match(source('cerveau'), /etatVide: data\.etatVide\?\.html \?\? null/);
    assert.match(source('list-manager'), /this\.emptyStateContainerTarget\.innerHTML = event\.detail\.etatVide;/);
});

test('le cerveau ne retire jamais les critères d’une AUTRE liste que la demandeuse', () => {
    assert.match(source('cerveau'), /if \(payload\.tabId && payload\.tabId !== cible\.listeId\) return;/);
});
