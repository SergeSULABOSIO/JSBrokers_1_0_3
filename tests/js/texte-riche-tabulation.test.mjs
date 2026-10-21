/**
 * PAS DE PIÈGE CLAVIER DANS L'ÉDITEUR RICHE (WCAG 2.1.2 — décision de Serge, 2026-10-21).
 *
 * Quill attache Tab à l'insertion d'une tabulation (et à l'indentation dans une liste) : un
 * utilisateur au clavier entrait dans l'éditeur et ne pouvait plus en sortir. Désormais Tab et
 * Maj+Tab sortent TOUJOURS vers le champ suivant ou précédent, liste comprise ; l'indentation
 * ne passerait que par des boutons de la barre d'outils (il n'y en a pas aujourd'hui).
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { SANS_TABULATION } from '../../assets/controllers/texte-riche-regles.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (chemin) => readFileSync(join(RACINE, chemin), 'utf8');

test('chaque raccourci Tab de Quill (copie du dépôt) est neutralisé', () => {
    const quill = source('assets/vendor/quill/quill.index.js');
    // `nom:{key:"Tab"` ou `"nom composé":{key:"Tab"` — tous, pour résister à une mise à jour.
    const noms = [...quill.matchAll(/(?:"([^"]+)"|([A-Za-z]+)):\{key:"Tab"/g)].map((m) => m[1] ?? m[2]);
    assert.ok(noms.length >= 3, `raccourcis Tab trouvés : ${noms.join(', ')}`);
    for (const nom of noms) {
        assert.ok(nom in SANS_TABULATION, `raccourci Tab non neutralisé : « ${nom} »`);
        assert.equal(SANS_TABULATION[nom], null, nom);
    }
});

test('l’éditeur riche est créé SANS ces raccourcis', () => {
    assert.match(source('assets/controllers/rich-text_controller.js'), /keyboard:\s*\{\s*bindings:\s*SANS_TABULATION\s*\}/);
});
