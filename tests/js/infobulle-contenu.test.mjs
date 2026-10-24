/**
 * Tests du contenu de l'infobulle unique (infobulle-contenu.js) — chantier 3.
 * Lancement : node --test tests/js/
 *
 * Ce qu'ils protègent : le contenu est DÉCLARÉ par le serveur (JSON dans `data-infobulle`) et
 * devient des rangées de TEXTE — jamais du HTML. Une donnée piégée reste une chaîne ; une classe
 * hors de la famille `tip-*` est écartée ; un JSON cassé n'affiche rien plutôt que n'importe quoi.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { lireSections, normaliserSections, rangees } from '../../assets/controllers/infobulle-contenu.js';

test('des sections posées PAR LE CODE passent la même validation que celles du serveur', () => {
    assert.deepEqual(normaliserSections([
        { titre: 'Janvier 2026', lignes: [['Encaissé', 'USD 0,00', 'tip-montant d-none']] },
        { titre: 'Sans lignes' },
        { lignes: 'pas une liste' },
        null,
    ]), [
        { titre: 'Janvier 2026', lignes: [['Encaissé', 'USD 0,00', 'tip-montant']], texte: null, classe: null },
        { titre: 'Sans lignes', lignes: [], texte: null, classe: null },
    ]);
    assert.deepEqual(normaliserSections(undefined), []);
    assert.deepEqual(normaliserSections('pas des sections'), []);
});

test('une section complète : titre, lignes libellé-valeur, texte', () => {
    const sections = lireSections(JSON.stringify([
        { titre: 'Piste', lignes: [['Nom', 'Incendie 2026'], ['Prime', '1 200,00 $', 'tip-montant']], texte: 'En cours' },
    ]));
    assert.deepEqual(rangees(sections), [
        { cellules: [{ texte: 'Piste', classe: 'tip-section', colspan: 2 }] },
        { cellules: [{ texte: 'Nom', classe: null, colspan: 1 }, { texte: 'Incendie 2026', classe: null, colspan: 1 }] },
        { cellules: [{ texte: 'Prime', classe: null, colspan: 1 }, { texte: '1 200,00 $', classe: 'tip-montant', colspan: 1 }] },
        { cellules: [{ texte: 'En cours', classe: 'tip-para', colspan: 2 }] },
    ]);
});

test('une donnée piégée reste du TEXTE, à l’identique', () => {
    const piege = '"><img src=x onerror=alert(1)> \' & < >\nligne 2';
    const [section] = lireSections(JSON.stringify([{ titre: piege, lignes: [[piege, piege]], texte: piege }]));
    assert.equal(section.titre, piege);
    assert.deepEqual(section.lignes[0], [piege, piege, null]);
    assert.equal(section.texte, piege);
});

test('un texte simple (barre d’actions, attribut title) devient une section de texte', () => {
    assert.deepEqual(rangees(lireSections(JSON.stringify('Ajouter un client'))), [
        { cellules: [{ texte: 'Ajouter un client', classe: 'tip-para', colspan: 2 }] },
    ]);
});

test('un texte peut porter sa classe (mise en avant, sous-titre), dans la famille tip-*', () => {
    assert.deepEqual(rangees(lireSections(JSON.stringify([{ texte: 'Pour plus d’infos, voir la liste', classe: 'tip-fort' }]))), [
        { cellules: [{ texte: 'Pour plus d’infos, voir la liste', classe: 'tip-fort', colspan: 2 }] },
    ]);
});

test('une classe hors de la famille tip-* est écartée (aucune classe imposée de l’extérieur)', () => {
    const [section] = lireSections(JSON.stringify([
        { lignes: [['A', 'B', 'btn btn-danger']], texte: 'x', classe: 'd-none' },
    ]));
    assert.deepEqual(section.lignes[0], ['A', 'B', null]);
    assert.equal(section.classe, null);
});

test('plusieurs classes de la famille tip-* se combinent ; une intruse dans la liste est retirée', () => {
    const [section] = lireSections(JSON.stringify([
        { lignes: [['TTC', '12,00 $', 'tip-montant tip-alerte'], ['HT', '10,00 $', 'tip-montant d-none']] },
    ]));
    assert.deepEqual(section.lignes, [['TTC', '12,00 $', 'tip-montant tip-alerte'], ['HT', '10,00 $', 'tip-montant']]);
});

test('une valeur absente s’affiche « — », un nombre devient une chaîne', () => {
    const [section] = lireSections(JSON.stringify([{ lignes: [['Réserve', null], ['Pièces', 3]] }]));
    assert.deepEqual(section.lignes, [['Réserve', '—', null], ['Pièces', '3', null]]);
});

test('JSON cassé, vide, ou forme inattendue : aucune infobulle', () => {
    for (const brut of ['', '{', 'null', '[]', '{"titre":"x"}', '[1, "a", null]', '[{}]', undefined]) {
        assert.deepEqual(lireSections(brut), [], `entrée : ${String(brut)}`);
    }
});

test('les objets au lieu de chaînes ne fabriquent pas « [object Object] »', () => {
    const [section] = lireSections(JSON.stringify([{ titre: { a: 1 }, lignes: [[{ b: 2 }, ['c']]], texte: 'ok' }]));
    assert.equal(section.titre, null);
    assert.deepEqual(section.lignes, []);
    assert.equal(section.texte, 'ok');
});
