/**
 * Tests de deux règles nées le 2026-10-13 :
 *  — LA FICHE ENREGISTRÉE QUE LE FILTRE ÉCARTE se signale, avec le filtre en cause et le
 *    geste qui la montre (une piste créée sous « Mon portefeuille » pour un client hors de
 *    mes portefeuilles semblait perdue) ;
 *  — LE NOM D'UNE COLLECTION se lit dans l'URL de sa liste : il annonce au serveur ce qui
 *    attend dans le tampon (une tranche saisie à la main ne doit pas recevoir un défaut).
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { criteresSans, messageFicheHorsFiltre } from '../../assets/controllers/fiche-hors-filtre.js';
import { codeDeCollection } from '../../assets/controllers/collection-tampon.js';

test('visible, ou étrangère à la liste : rien à dire', () => {
    assert.equal(messageFicheHorsFiltre({ id: 1, visible: true, masqueePar: [] }, 'X'), null);
    assert.equal(messageFicheHorsFiltre({ id: 1, visible: null, masqueePar: [] }, 'X'), null);
    assert.equal(messageFicheHorsFiltre(null, 'X'), null);
});

test('un filtre en cause : il est nommé, et le geste le retire', () => {
    const m = messageFicheHorsFiltre(
        { id: 198, visible: false, masqueePar: [{ cle: '__mon_portefeuille__', libelle: 'Mon portefeuille' }] },
        'Assurance Auto pour Marlette SULA',
    );
    assert.equal(m.texte, '« Assurance Auto pour Marlette SULA » est bien enregistrée, mais le filtre « Mon portefeuille » la masque dans cette liste.');
    assert.equal(m.action, 'Retirer ce filtre');
    assert.deepEqual(m.cles, ['__mon_portefeuille__']);
});

test('plusieurs filtres : pluriel, liste en français', () => {
    const m = messageFicheHorsFiltre({ id: 1, visible: false, masqueePar: [
        { cle: 'a', libelle: 'Mon portefeuille' }, { cle: 'b', libelle: 'Statut' }, { cle: 'c', libelle: 'Nom' },
    ] }, null);
    assert.equal(m.texte, 'La fiche est bien enregistrée, mais les filtres « Mon portefeuille », « Statut » et « Nom » la masquent dans cette liste.');
    assert.equal(m.action, 'Retirer ces filtres');
});

test('aucun filtre isolable : on le dit, sans proposer un geste qui ne suffirait pas', () => {
    const m = messageFicheHorsFiltre({ id: 1, visible: false, masqueePar: [] }, 'P');
    assert.match(m.texte, /le filtre actif de la liste ne la montre pas/);
    assert.equal(m.action, null);
});

test('le nom de la fiche reste du TEXTE : le toast le pose par textContent, sans double échappement', () => {
    const m = messageFicheHorsFiltre({ id: 1, visible: false, masqueePar: [{ cle: 'a', libelle: 'R&D' }] }, '<b>X</b>');
    assert.ok(m.texte.startsWith('« <b>X</b> »'), 'le nom tel qu\'il a été saisi');
    assert.ok(m.texte.includes('« R&D »'));
    assert.ok(!m.texte.includes('&lt;') && !m.texte.includes('&amp;'));
});

test('retirer les filtres en cause garde les autres', () => {
    const criteres = { a: 1, b: 2, c: 3 };
    assert.deepEqual(criteresSans(criteres, ['a', 'c']), { b: 2 });
    assert.deepEqual(criteres, { a: 1, b: 2, c: 3 }, 'les critères de l’onglet ne sont pas modifiés');
    assert.deepEqual(criteresSans(null, ['a']), {});
});

test('le nom d’une collection se lit dans l’URL de sa liste', () => {
    // La forme RÉELLE du widget de dialogue : sans usage (il l'ajoute au chargement).
    assert.equal(codeDeCollection('/admin/cotation/api/0/tranches'), 'tranches');
    assert.equal(codeDeCollection('/admin/cotation/api/0/tranches/dialog'), 'tranches');
    assert.equal(codeDeCollection('/admin/piste/api/0/cotations/dialog'), 'cotations');
    assert.equal(codeDeCollection('/admin/piste/api/12/conditionsPartageExceptionnelles/dialog?en_attente=1'), 'conditionsPartageExceptionnelles');
    assert.equal(codeDeCollection(''), null);
    assert.equal(codeDeCollection(undefined), null);
});
