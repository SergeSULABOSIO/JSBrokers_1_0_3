/**
 * Le filtre de la veille des erreurs navigateur.
 *
 * Lancement : node --test tests/js/
 *
 * ── POURQUOI CE TEST COMPTE PLUS QUE LA MOYENNE ─────────────────────────────
 * Ce filtre décide de ce que l'équipe verra et de ce qu'elle ne verra jamais.
 * Trop strict, il masque un vrai défaut ; trop laxiste, il noie la liste sous
 * les erreurs d'extensions de navigateur et le « Script error. » d'origine
 * croisée, qui ne dit rien et qu'on ne peut pas corriger.
 *
 * Un filtre qu'on ne peut pas éprouver est un filtre dont on ignore ce qu'il
 * laisse passer — c'est pour cela que `meriteUnRapport` est une fonction pure,
 * séparée de tout ce qui touche au DOM.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { meriteUnRapport } from '../../assets/veille-erreurs.js';

const ORIGINE = 'https://www.joseara.com';

test('une vraie erreur de Joseara est remontée', () => {
    assert.equal(
        meriteUnRapport(
            {
                message: "Cannot read properties of null (reading 'dataset')",
                fichier: 'https://www.joseara.com/assets/controllers/collection_controller-a119f5.js',
            },
            ORIGINE,
        ),
        true,
    );
});

test('un chemin relatif est forcément le nôtre', () => {
    assert.equal(
        meriteUnRapport({ message: 'TypeError: x is not a function', fichier: '/assets/app-235df4.js' }, ORIGINE),
        true,
    );
});

test('une erreur sans fichier est gardée : le message reste utile', () => {
    assert.equal(meriteUnRapport({ message: 'Promesse rejetée sans motif', fichier: '' }, ORIGINE), true);
});

test('« Script error. » est écarté : il ne dit rien et ne se corrige pas', () => {
    assert.equal(meriteUnRapport({ message: 'Script error.', fichier: '' }, ORIGINE), false);
    assert.equal(meriteUnRapport({ message: 'Script error', fichier: '' }, ORIGINE), false);
});

test('le bruit de ResizeObserver est écarté : ce n\'est pas un défaut', () => {
    assert.equal(
        meriteUnRapport(
            { message: 'ResizeObserver loop completed with undelivered notifications.', fichier: '/assets/app.js' },
            ORIGINE,
        ),
        false,
    );
});

test('les extensions de navigateur sont écartées : ce n\'est pas notre code', () => {
    for (const prefixe of [
        'chrome-extension://abcdef/content.js',
        'moz-extension://abcdef/content.js',
        'safari-web-extension://abcdef/content.js',
    ]) {
        assert.equal(
            meriteUnRapport({ message: 'TypeError: undefined', fichier: prefixe }, ORIGINE),
            false,
            `${prefixe} ne devrait pas être remonté`,
        );
    }
});

test('un script d\'un autre domaine est écarté', () => {
    assert.equal(
        meriteUnRapport(
            { message: 'TypeError: t is undefined', fichier: 'https://cdn.jsdelivr.net/npm/bootstrap/dist/js/bootstrap.min.js' },
            ORIGINE,
        ),
        false,
    );
});

test('un message vide est écarté : il n\'y a rien à corriger', () => {
    assert.equal(meriteUnRapport({ message: '', fichier: '/assets/app.js' }, ORIGINE), false);
    assert.equal(meriteUnRapport({ message: '   ', fichier: '/assets/app.js' }, ORIGINE), false);
    assert.equal(meriteUnRapport({}, ORIGINE), false);
});

test('une erreur venue de la Console est remontée comme les autres', () => {
    // La Console est l'espace de l'équipe Joseara : ses erreurs comptent autant
    // que celles du portail, et c'est la BRANCHE — résolue côté serveur — qui
    // les distinguera ensuite.
    assert.equal(
        meriteUnRapport(
            { message: 'TypeError: kpi is undefined', fichier: 'https://www.joseara.com/assets/controllers/lazy_block-9ab6d.js' },
            ORIGINE,
        ),
        true,
    );
});
