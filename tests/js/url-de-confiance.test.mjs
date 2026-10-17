/**
 * UNE URL RELUE DU STOCKAGE NE SORT PAS DE CHEZ NOUS.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { urlDeMemeOrigine } from '../../assets/controllers/url-de-confiance.js';

const ICI = 'https://joseara.com';

test('un chemin de l\'application passe, requête comprise', () => {
    assert.equal(urlDeMemeOrigine('/admin/soa/client/12?onglet=2', ICI), '/admin/soa/client/12?onglet=2');
    assert.equal(urlDeMemeOrigine('https://joseara.com/assistant/conversation/4', ICI), '/assistant/conversation/4');
});

test('une autre origine, un protocole exotique ou du vide sont refusés', () => {
    for (const piege of [
        'https://evil.example/charge.html',
        '//evil.example/charge.html',
        'http://joseara.com/x',          // autre protocole = autre origine
        'https://joseara.com.evil.example/x',
        'javascript:alert(1)',
        'data:text/html,<img src=x onerror=1>',
        '',
        null,
        42,
    ]) {
        assert.equal(urlDeMemeOrigine(piege, ICI), null, String(piege));
    }
});

test('sans origine connue (hors navigateur), rien ne passe', () => {
    assert.equal(urlDeMemeOrigine('/admin', undefined), null);
});
