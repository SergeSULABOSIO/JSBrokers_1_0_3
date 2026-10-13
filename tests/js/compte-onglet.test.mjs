/**
 * Tests de la pastille de compte des onglets (compte-onglet.js) — sa règle de défilement.
 * Lancement : node --test tests/js/
 *
 * Le chiffre défile de la valeur affichée à la nouvelle ; il doit être ENTIER à chaque image,
 * exact aux deux bouts, et ne jamais dépasser sa cible (un compte de 2 qui afficherait 3 un
 * instant mentirait).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { valeurAuTemps } from '../../assets/controllers/compte-onglet.js';

test('exact aux deux bouts', () => {
    assert.equal(valeurAuTemps(0, 12, 0), 0);
    assert.equal(valeurAuTemps(0, 12, 1), 12);
    assert.equal(valeurAuTemps(0, 12, -0.5), 0);
    assert.equal(valeurAuTemps(0, 12, 1.5), 12);
});

test('entier, monotone, sans dépasser la cible — à la hausse comme à la baisse', () => {
    for (const [de, a] of [[0, 2], [0, 137], [9, 3], [5, 0]]) {
        let precedent = de;
        for (let i = 0; i <= 20; i++) {
            const v = valeurAuTemps(de, a, i / 20);
            assert.ok(Number.isInteger(v), `${de}→${a} : ${v} n'est pas entier`);
            assert.ok(a >= de ? v >= precedent && v <= a : v <= precedent && v >= a, `${de}→${a} : ${v} après ${precedent}`);
            precedent = v;
        }
    }
});

test('décéléré : l’essentiel du chemin est fait à mi-course', () => {
    assert.ok(valeurAuTemps(0, 100, 0.5) >= 80);
});
