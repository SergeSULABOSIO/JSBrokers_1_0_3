/**
 * UNE SEULE FONCTION D'ÉCHAPPEMENT DANS LE NAVIGATEUR : assets/controllers/echapper.js.
 *
 * Avant le chantier d'échappement (2026-10-16), treize copies locales coexistaient — dont
 * plusieurs oubliaient `"` ou `'`, et laissaient une donnée sortir d'un attribut. Ce test
 * refuse qu'une copie renaisse : on importe `echapper`, ou mieux, on pose par textContent.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ASSETS = join(dirname(fileURLToPath(import.meta.url)), '../../assets');

function fichiersJs(dossier) {
    return readdirSync(dossier).flatMap((nom) => {
        const chemin = join(dossier, nom);
        if (statSync(chemin).isDirectory()) {
            return nom === 'vendor' ? [] : fichiersJs(chemin);
        }
        return nom.endsWith('.js') ? [chemin] : [];
    });
}

test('aucune copie locale d\'échappement hors d\'echapper.js', () => {
    const copies = [];
    for (const fichier of fichiersJs(ASSETS)) {
        const nom = relative(ASSETS, fichier).replaceAll('\\', '/');
        if (nom === 'controllers/echapper.js') continue;
        readFileSync(fichier, 'utf8').split('\n').forEach((ligne, i) => {
            if (/\.replace\(\s*\/&\/g|\.replace\(\s*\/<\/g|&amp;'\)|function\s+(escapeHtml|_escapeHtml|esc|_esc|escape|echapperHtml)\s*\(/.test(ligne)) {
                copies.push(`${nom}:${i + 1}  ${ligne.trim()}`);
            }
        });
    }
    assert.deepEqual(copies, [], `Échappement réécrit à la main — importer echapper (./echapper.js) :\n${copies.join('\n')}`);
});
