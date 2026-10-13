import test from 'node:test';
import assert from 'node:assert/strict';

import { estListePrincipale, doitAnnoncerLePremierRendu } from '../../assets/controllers/premier-rendu.js';

test('la liste principale porte l’id « principal » — le seul test d’autrefois ne la reconnaissait pas', () => {
    assert.equal(estListePrincipale('principal'), true);
    assert.equal(estListePrincipale('list-manager-42'), true);
});

test('une collection contextuelle ou embarquée n’est pas principale', () => {
    assert.equal(estListePrincipale('collection-cotations-for-12'), false);
    assert.equal(estListePrincipale('list-controller-0.53'), false);
    assert.equal(estListePrincipale(''), false);
    assert.equal(estListePrincipale(undefined), false);
});

test('le premier rendu de la liste principale s’annonce quand rien ne charge', () => {
    assert.equal(doitAnnoncerLePremierRendu('principal', false), true);
});

test('rien n’est annoncé si un chargement vient de démarrer (filtres restaurés)', () => {
    assert.equal(doitAnnoncerLePremierRendu('principal', true), false);
});

test('une collection ne s’annonce jamais au premier rendu', () => {
    assert.equal(doitAnnoncerLePremierRendu('collection-taches-for-3', false), false);
});
