/**
 * TOUT FICHIER D'assets/controllers DOIT SE CHARGER.
 *
 * ── LE DÉFAUT QUI L'A FAIT NAÎTRE ───────────────────────────────────────────────────
 * Un commentaire HTML écrit DANS le gabarit de la boîte de dialogue (une chaîne entre
 * accents graves) citait `p-0` entre accents graves : la chaîne se refermait au milieu
 * du commentaire, et `dialog-manager_controller.js` cessait de se charger. Plus AUCUN
 * dialogue d'entité ne s'ouvrait — et aucun test ne le voyait, faute d'en importer un
 * seul de ces contrôleurs (ils dépendent de Stimulus et d'un DOM).
 *
 * `node --check` ne fait qu'analyser la syntaxe, sans rien exécuter : il convient donc à
 * tous les fichiers, contrôleurs Stimulus compris.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');

test('chaque fichier d’assets/controllers est syntaxiquement valide', () => {
    const fichiers = readdirSync(RACINE).filter((f) => f.endsWith('.js'));
    assert.ok(fichiers.length > 0, 'Aucun fichier trouvé : le chemin a dû changer.');

    const fautifs = fichiers
        .map((f) => ({ f, r: spawnSync(process.execPath, ['--check', join(RACINE, f)], { encoding: 'utf8' }) }))
        .filter(({ r }) => r.status !== 0)
        .map(({ f, r }) => `${f} : ${(r.stderr || '').split('\n').find((l) => l.includes('Error')) || 'erreur'}`);

    assert.deepEqual(fautifs, [], `Fichiers qui ne se chargent pas :\n  - ${fautifs.join('\n  - ')}`);
});
