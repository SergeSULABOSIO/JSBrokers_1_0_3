/**
 * LA LIGNE DES FILTRES ACTIFS — moitié « écran » de la parité écran ↔ Ket (refonte de la
 * colonne 3, C2+). Le même fichier de cas est joué par PHPUnit contre
 * `App\Services\Search\LigneDesFiltres::calculer`, que Ket lit pour annoncer ce que montre
 * une rubrique qu'il ouvre : si les deux écritures divergent, l'une des deux suites rougit.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

import { filtresActifs } from '../../assets/controllers/search-bar-filtres.js';

const fixture = JSON.parse(readFileSync(new URL('./fixtures/ligne-des-filtres.json', import.meta.url), 'utf8'));

for (const cas of fixture.cas) {
    test(`ligne des filtres — ${cas.nom}`, () => {
        assert.deepEqual(
            filtresActifs(cas.criteres, {
                definitions: fixture.definitions,
                groupes: cas.groupes,
                defauts: cas.defauts,
                imposes: cas.imposes,
            }),
            cas.attendu,
        );
    });
}
