import test from 'node:test';
import assert from 'node:assert/strict';

import { cookieAEcrire, lireCookie, PREFIXE_HISTORIQUE, UN_AN } from '../../assets/controllers/cookies.js';

test('écrire : valeur encodée, tout le site, un an par défaut, SameSite=Lax', () => {
    assert.equal(cookieAEcrire('listeVue_4_Piste', 'cartes'), `listeVue_4_Piste=cartes; path=/; max-age=${UN_AN}; SameSite=Lax`);
    assert.equal(cookieAEcrire('a', 'x y;z', { maxAge: 60 }), 'a=x%20y%3Bz; path=/; max-age=60; SameSite=Lax', 'un « ; » ne coupe pas le cookie');
    assert.equal(cookieAEcrire('a', null), `a=; path=/; max-age=${UN_AN}; SameSite=Lax`);
});

test('lire : le bon cookie parmi d’autres, décodé — null s’il est absent', () => {
    const jar = 'autre=1; listeVue_4_Piste=liste; listeVue_40_Piste=cartes';
    assert.equal(lireCookie(jar, 'listeVue_4_Piste'), 'liste', 'pas de confusion avec un nom qui commence pareil');
    assert.equal(lireCookie(jar, 'listeVue_40_Piste'), 'cartes');
    assert.equal(lireCookie(jar, 'absent'), null);
    assert.equal(lireCookie('', 'a'), null);
    assert.equal(lireCookie(undefined, 'a'), null);
    assert.equal(lireCookie('a=x%20y%3Bz', 'a'), 'x y;z');
    assert.equal(lireCookie('a=100%', 'a'), '100%', 'un % orphelin est rendu tel quel');
});

test('repli : la valeur préfixée par l’ancien saveCookie est lue sans son préfixe', () => {
    assert.equal(lireCookie(`listeVue_4_Piste=${PREFIXE_HISTORIQUE}cartes`, 'listeVue_4_Piste'), 'cartes');
    assert.equal(lireCookie(`a=${PREFIXE_HISTORIQUE}`, 'a'), '');
});

test('aller-retour : ce qui est écrit se relit à l’identique', () => {
    for (const valeur of ['cartes', 'liste', 'a b', 'é;=%']) {
        const ecrit = cookieAEcrire('k', valeur).split(';')[0];
        assert.equal(lireCookie(`x=1; ${ecrit}`, 'k'), valeur);
    }
});
