/**
 * Tests du cœur PUR de la recherche des pickers
 * (assets/controllers/texte-recherche.js) — aucun DOM.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { normaliserRecherche, ligneCorrespond } from '../../assets/controllers/texte-recherche.js';

test('normaliserRecherche met en minuscules et retire les accents', () => {
    assert.equal(normaliserRecherche('Échéance'), 'echeance');
    assert.equal(normaliserRecherche('SONAS'), 'sonas');
    assert.equal(normaliserRecherche('Crédit Agricole'), 'credit agricole');
    assert.equal(normaliserRecherche('Noël à Kinshasa'), 'noel a kinshasa');
    assert.equal(normaliserRecherche('Ça coûte cher'), 'ca coute cher');
});

test('normaliserRecherche PRÉSERVE la longueur — le surlignage en dépend', () => {
    // Une normalisation NFD globale donnerait 'e'+accent (2 caractères) et
    // décalerait tous les index de découpage du texte d'origine.
    for (const mot of ['Échéance', 'Noël', 'Ça', 'crédit', 'SONAS', 'a@b.cd']) {
        assert.equal(normaliserRecherche(mot).length, mot.length, mot);
    }
});

test('normaliserRecherche tolère les entrées vides ou non textuelles', () => {
    assert.equal(normaliserRecherche(''), '');
    assert.equal(normaliserRecherche(null), '');
    assert.equal(normaliserRecherche(undefined), '');
    assert.equal(normaliserRecherche(42), '42');
});

test('normaliserRecherche laisse intacts les caractères non décomposables', () => {
    // Longueur préservée même quand le caractère ne se réduit pas à un signe.
    for (const mot of ['œuvre', 'straße', '日本']) {
        assert.equal(normaliserRecherche(mot).length, mot.length, mot);
    }
});

test('ligneCorrespond ignore les accents dans les deux sens', () => {
    assert.ok(ligneCorrespond('Échéance SONAS', 'echeance'));
    assert.ok(ligneCorrespond('Echeance SONAS', 'échéance'));
    assert.ok(ligneCorrespond('Contact sinistres — SONAS', 'SINISTRES'));
});

test('ligneCorrespond exige TOUS les termes, dans n\'importe quel ordre', () => {
    const ligne = 'marie kabila — contact sinistres — sonas — m.kabila@sonas.cd';

    assert.ok(ligneCorrespond(ligne, 'sinistre sonas'));
    assert.ok(ligneCorrespond(ligne, 'sonas sinistre'));
    assert.ok(ligneCorrespond(ligne, '@sonas.cd'));
    assert.ok(!ligneCorrespond(ligne, 'sinistre rawbank'));
});

test('ligneCorrespond accepte tout quand la recherche est vide', () => {
    assert.ok(ligneCorrespond('quoi que ce soit', ''));
    assert.ok(ligneCorrespond('quoi que ce soit', '   '));
    assert.ok(ligneCorrespond('', ''));
});

test('ligneCorrespond ne trouve rien dans une ligne vide', () => {
    assert.ok(!ligneCorrespond('', 'sonas'));
});
