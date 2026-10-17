/**
 * LE CACHE DES ICÔNES NE REND QU'UN SVG INERTE — une entrée réécrite est effacée.
 * Lancement : node --test tests/js/
 */
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { estIconeSvg, lireIconeMemorisee, memoriserIcone } from '../../assets/controllers/icone-memorisee.js';

const SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="18px" height="18px" viewBox="0 0 24 24"><path fill="currentColor" d="M3 3h18v18H3z"/></svg>';

beforeEach(() => {
    const donnees = new Map();
    globalThis.localStorage = {
        getItem: (k) => (donnees.has(k) ? donnees.get(k) : null),
        setItem: (k, v) => donnees.set(k, String(v)),
        removeItem: (k) => donnees.delete(k),
        _donnees: donnees,
    };
});

test('un SVG de ux_icon est une icône, précédé ou non d\'un saut de ligne', () => {
    assert.equal(estIconeSvg(SVG), true);
    assert.equal(estIconeSvg('\n' + SVG), true);
});

test('ce qui n\'est pas un SVG inerte est refusé', () => {
    for (const piege of [
        '<img src=x onerror="window.__xss=1">',
        '<!-- error loading icon x -->',
        '<svg onload="window.__xss=1"></svg>',
        '<svg><script>window.__xss=1</script></svg>',
        '<svg><foreignObject><img src=x onerror=1></foreignObject></svg>',
        '<svg><a href="javascript:alert(1)"><path/></a></svg>',
        '<svgx></svgx>',
        null,
        undefined,
    ]) {
        assert.equal(estIconeSvg(piege), false, String(piege));
    }
});

test('une entrée réécrite est effacée, et l\'icône redemandée (null)', () => {
    localStorage.setItem('jsb-icon-v1::client::18', '<img src=x onerror="window.__xss=1">');
    assert.equal(lireIconeMemorisee('client', 18), null);
    assert.equal(localStorage.getItem('jsb-icon-v1::client::18'), null, 'l\'entrée douteuse doit disparaître');
});

test('aller-retour : un SVG mémorisé se relit tel quel ; un commentaire d\'erreur n\'est pas gardé', () => {
    memoriserIcone('client', 18, SVG);
    assert.equal(lireIconeMemorisee('client', 18), SVG);
    memoriserIcone('inconnue', 18, '<!-- error -->');
    assert.equal(localStorage.getItem('jsb-icon-v1::inconnue::18'), null);
});

test('stockage indisponible : ni exception, ni icône', () => {
    globalThis.localStorage = { getItem() { throw new Error('quota'); }, setItem() { throw new Error('quota'); } };
    assert.equal(lireIconeMemorisee('client', 18), null);
    assert.doesNotThrow(() => memoriserIcone('client', 18, SVG));
});
