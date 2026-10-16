/**
 * LA SEULE FONCTION D'ÉCHAPPEMENT : les cinq caractères, toute valeur, jamais d'exception.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { echapper } from '../../assets/controllers/echapper.js';

test('les cinq caractères, y compris les deux guillemets', () => {
    assert.equal(
        echapper('<img src=x onerror=alert(1)> & "q" \'r\''),
        '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot; &#39;r&#39;',
    );
});

test('une donnée ne sort plus d\'un attribut', () => {
    const attribut = `title="${echapper('x" onmouseover="alert(1)')}"`;
    assert.equal(attribut, 'title="x&quot; onmouseover=&quot;alert(1)"');
});

test('toute valeur : nombre, vide, absente — jamais d\'exception', () => {
    assert.equal(echapper(42), '42');
    assert.equal(echapper(''), '');
    assert.equal(echapper(null), '');
    assert.equal(echapper(undefined), '');
});
