/**
 * Tests du cœur PUR des tableaux de l'assistant IA
 * (assets/controllers/assistant-markdown-table.js) — aucun DOM, aucun marked.
 * Lancement : node --test tests/js/
 *
 * CE QUI EST EN JEU. L'alignement GFM (`|---:|`) était écrit par personne, et de toute
 * façon jeté : marked le rend en attribut `align`, que l'allowlist de sanitisation du
 * chat (ALLOWED_ATTR = ['class']) supprime. Résultat mesuré sur la capture du
 * 2026-08-10 : des montants alignés comme du texte, et une ligne de totaux
 * indiscernable d'une ligne de données. Ces tests verrouillent la traduction en CLASSE,
 * seule forme qui survit à la sanitisation sans élargir l'allowlist.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    CLASSE_ALIGNEMENT,
    classeAlignement,
    estLigneDeTotaux,
    celluleTableau,
    ligneTableau,
} from '../../assets/controllers/assistant-markdown-table.js';

test('une colonne alignée à droite devient une classe, jamais un attribut align', () => {
    const cellule = celluleTableau('3 195,16 $', { align: 'right' });

    assert.equal(cellule, '<td class="aic-md-num">3 195,16 $</td>\n');
    assert.ok(!cellule.includes('align='), 'L’attribut align ne survivrait pas à DOMPurify.');
});

test('le centrage a sa classe, et l’alignement à gauche n’en reçoit aucune (c’est le défaut)', () => {
    assert.equal(classeAlignement('center'), 'aic-md-center');
    assert.equal(classeAlignement('left'), '');
    assert.equal(classeAlignement(null), '');
    assert.equal(classeAlignement(undefined), '');
    assert.equal(celluleTableau('CHEMAF SA'), '<td>CHEMAF SA</td>\n');
});

test('un en-tête suit l’alignement de sa colonne', () => {
    assert.equal(celluleTableau('Montant', { header: true, align: 'right' }), '<th class="aic-md-num">Montant</th>\n');
    assert.equal(celluleTableau('Client', { header: true }), '<th>Client</th>\n');
});

test('la ligne de totaux est marquée, une ligne de données ne l’est pas', () => {
    const totaux = '<td><strong>TOTAL</strong></td>\n<td class="aic-md-num"><strong>1 911 633,28 $</strong></td>\n';
    const donnees = '<td>Fracht Trading Mauritius</td>\n<td class="aic-md-num">3 195,16 $</td>\n';

    assert.ok(estLigneDeTotaux(totaux));
    assert.equal(ligneTableau(totaux), `<tr class="aic-md-total">\n${totaux}</tr>\n`);

    assert.ok(!estLigneDeTotaux(donnees));
    assert.equal(ligneTableau(donnees), `<tr>\n${donnees}</tr>\n`);
});

test('la ligne de totaux reste reconnue quand sa première cellule porte une classe', () => {
    // Cas réel : tableau d'une seule colonne de montants — la cellule « TOTAL » est
    // elle-même alignée à droite, donc porte aic-md-num.
    assert.ok(estLigneDeTotaux('<td class="aic-md-num"><strong>TOTAL</strong></td>\n'));
});

test('un simple mot « total » dans une cellule de données ne marque pas la ligne', () => {
    assert.ok(!estLigneDeTotaux('<td>Prime totale de la police</td>\n'));
    assert.ok(!estLigneDeTotaux('<td><strong>Totalité du portefeuille</strong></td>\n'));
});

test('la table d’alignement est figée : elle est la spec partagée avec le PHP', () => {
    assert.deepEqual({ ...CLASSE_ALIGNEMENT }, { right: 'aic-md-num', center: 'aic-md-center' });
    assert.ok(Object.isFrozen(CLASSE_ALIGNEMENT));
});
