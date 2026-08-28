/**
 * Tests de l'ÉTAT D'UN CHIP DE FILTRE RAPIDE
 * (assets/controllers/chip-preset-etat.js) — logique pure, aucun DOM.
 *
 * Ce qui est protégé ici : qu'on VOIE le filtre posé. Le chip-sélecteur du bénéficiaire
 * gardait son libellé « Choisir un agent… » quel que soit l'agent retenu, et se marquait
 * actif quand RIEN n'était filtré — en même temps que « Tous les agents ». Deux chips
 * allumés d'un côté, un nom introuvable de l'autre : un filtre qu'on ne peut pas lire est
 * un filtre qu'on croit absent.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { etatChipPreset } from '../../assets/controllers/chip-preset-etat.js';

const aValeur = (valeurAttendue) => ({ valeurAttendue, estSelecteur: false, libelleDefaut: '' });
const selecteur = { valeurAttendue: '', estSelecteur: true, libelleDefaut: 'Choisir un agent…' };

test('un chip à valeur est actif quand le critère porte SA valeur (non-régression)', () => {
    const critere = { operator: '=', value: 'sans_piece', label: 'Sans pièce' };

    assert.deepEqual(etatChipPreset(aValeur('sans_piece'), critere), { actif: true, libelle: null });
    assert.deepEqual(etatChipPreset(aValeur('avec_piece'), critere), { actif: false, libelle: null });
});

test('l’option « Tous » est active quand le critère est absent (non-régression)', () => {
    assert.equal(etatChipPreset(aValeur(''), undefined).actif, true);
    assert.equal(etatChipPreset(aValeur(''), null).actif, true);
    assert.equal(etatChipPreset(aValeur('groupe'), undefined).actif, false);
});

test('un critère rendu en valeur nue reste comparable', () => {
    // Le cerveau stocke un objet, mais rien ne garantit la forme d'un critère venu
    // d'ailleurs : durcir la comparaison masquerait des chips qui marchent aujourd'hui.
    assert.equal(etatChipPreset(aValeur('groupe'), 'groupe').actif, true);
    assert.equal(etatChipPreset(aValeur('12'), 12).actif, true);
});

test('LE SÉLECTEUR N’EST PAS ACTIF QUAND RIEN N’EST FILTRÉ — c’était le défaut', () => {
    const etat = etatChipPreset(selecteur, undefined);

    assert.equal(etat.actif, false, 'Sans agent choisi, il ne doit pas se marquer actif.');
    assert.equal(etat.libelle, 'Choisir un agent…', 'Et il retrouve son libellé d’invitation.');
});

test('le sélecteur NOMME l’agent retenu, et se marque actif', () => {
    const etat = etatChipPreset(selecteur, { operator: '=', value: 42, label: 'Alice' });

    assert.equal(etat.actif, true);
    assert.equal(etat.libelle, 'Alice');
});

test('sans libellé, le sélecteur montre la valeur brute plutôt que de mentir', () => {
    // Mieux vaut un identifiant qu'un intitulé qui prétend qu'aucun filtre n'est posé.
    const etat = etatChipPreset(selecteur, { operator: '=', value: 42 });

    assert.equal(etat.actif, true);
    assert.equal(etat.libelle, '42');
});

test('après « Tous les agents », le sélecteur redevient une invitation', () => {
    // Le retrait passe par une valeur vide : c'est ce que le cerveau lit comme « retire ce
    // critère », et le chip doit suivre du même geste.
    const etat = etatChipPreset(selecteur, { operator: '=', value: '', label: '' });

    assert.equal(etat.actif, false);
    assert.equal(etat.libelle, 'Choisir un agent…');
});

test('un chip à valeur ne touche jamais à son libellé', () => {
    // `libelle: null` = « n'y touche pas ». Sans cette distinction, les chips ordinaires
    // se réécriraient à chaque synchronisation.
    assert.equal(etatChipPreset(aValeur('ce_mois'), { value: 'ce_mois', label: 'Ce mois' }).libelle, null);
});

// ── DEUX SÉLECTEURS SOUS UNE MÊME CLÉ ───────────────────────────────────────────────
//
// Le bénéficiaire d'un reversement est un agent OU un partenaire : deux colonnes, un seul
// filtre, donc deux chips côte à côte sur la même clé de critère. Sans le préfixe, choisir
// un agent allumait AUSSI « Choisir un partenaire… » — et le faisait porter le nom de
// l'agent, un filtre qui mentait sur ce qu'il filtrait.

const selAgent = {
    valeurAttendue: '', estSelecteur: true, prefixe: 'agent',
    libelleDefaut: 'Choisir un agent…',
};
const selPartenaire = {
    valeurAttendue: '', estSelecteur: true, prefixe: 'partenaire',
    libelleDefaut: 'Choisir un partenaire…',
};

test('seul le sélecteur de la BONNE famille s’allume', () => {
    const critere = { operator: '=', value: 'agent:12', label: 'Alice' };

    assert.deepEqual(etatChipPreset(selAgent, critere), { actif: true, libelle: 'Alice' });
    assert.deepEqual(
        etatChipPreset(selPartenaire, critere),
        { actif: false, libelle: 'Choisir un partenaire…' },
    );
});

test('et réciproquement pour un partenaire', () => {
    const critere = { operator: '=', value: 'partenaire:12', label: 'SUNU Courtage' };

    assert.equal(etatChipPreset(selPartenaire, critere).actif, true);
    assert.equal(etatChipPreset(selPartenaire, critere).libelle, 'SUNU Courtage');
    // Même identifiant, autre famille : l'agent #12 n'est pas le partenaire #12.
    assert.equal(etatChipPreset(selAgent, critere).actif, false);
});

test('un préfixe qui n’est pas un préfixe ne compte pas', () => {
    // « agentX:1 » ne doit pas passer pour la famille « agent » : le séparateur fait
    // partie du test, sinon deux familles homographes se confondraient.
    assert.equal(etatChipPreset(selAgent, { value: 'agentX:1' }).actif, false);
});

test('un sélecteur SANS préfixe garde son comportement (non-régression)', () => {
    // Les autres rubriques n'ont qu'un sélecteur par critère : leur exiger un préfixe
    // les aurait toutes éteintes.
    assert.equal(etatChipPreset(selecteur, { value: 'agent:12', label: 'Alice' }).actif, true);
    assert.equal(etatChipPreset(selecteur, { value: 12, label: 'Alice' }).actif, true);
});
