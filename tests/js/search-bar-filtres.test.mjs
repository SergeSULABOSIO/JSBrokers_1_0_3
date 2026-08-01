/**
 * Tests du cœur PUR du résumé des filtres de la barre de recherche
 * (assets/controllers/search-bar-filtres.js) — aucun DOM.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    construireResumeFiltres,
    formatterTexteFiltre,
    SEUIL_COMPTEUR,
} from '../../assets/controllers/search-bar-filtres.js';

/** Définitions de critères représentatives d'un searchCanvas d'Avenant. */
const DEFS = [
    { Nom: 'refPolice', Display: 'Réf. Police', Type: 'Text' },
    { Nom: 'client', Display: 'Client', Type: 'Relation' },
    { Nom: 'endingAt', Display: 'Échéance', Type: 'DateTimeRange' },
    { Nom: '__mon_portefeuille__', Display: 'Mon portefeuille', Type: 'Relation' },
    { Nom: 'actif', Display: 'Actif', Type: 'Boolean', Valeur: { 1: 'Oui', 0: 'Non' } },
    { Nom: 'primeTotale', Display: 'Prime totale', Type: 'Number' },
];

test('aucun filtre → mode « vide » (la zone reste masquée)', () => {
    for (const vide of [{}, null, undefined]) {
        const resume = construireResumeFiltres(vide, DEFS);
        assert.equal(resume.mode, 'vide');
        assert.equal(resume.nombre, 0);
        assert.deepEqual(resume.filtres, []);
    }
});

test('un seul filtre → mode « badge » : il reste lisible en clair', () => {
    const resume = construireResumeFiltres(
        { client: { operator: 'LIKE', value: '42', label: 'KIN AVIA' } },
        DEFS,
    );
    assert.equal(resume.mode, 'badge');
    assert.equal(resume.nombre, 1);
    assert.deepEqual(resume.filtres, [
        { cle: 'client', libelle: 'Client', texte: 'Client : KIN AVIA' },
    ]);
});

test('deux filtres ou plus → mode « compteur » : la ligne ne grandit pas', () => {
    const resume = construireResumeFiltres(
        {
            endingAt: { from: '01/01/2026', to: '31/01/2026' },
            client: { value: '42', label: 'KIN AVIA' },
            __mon_portefeuille__: { value: '7', label: 'Portefeuille de Serge' },
        },
        DEFS,
    );
    assert.equal(resume.mode, 'compteur');
    assert.equal(resume.nombre, 3);
    assert.deepEqual(resume.filtres.map(f => f.texte), [
        'Échéance : du 01/01/2026 au 31/01/2026',
        'Client : KIN AVIA',
        'Mon portefeuille : Portefeuille de Serge',
    ]);
});

test('le seuil de bascule badge → compteur est exactement SEUIL_COMPTEUR', () => {
    const criteres = {};
    for (let i = 0; i < SEUIL_COMPTEUR - 1; i++) criteres[`c${i}`] = 'x';
    assert.equal(construireResumeFiltres(criteres, DEFS).mode, 'badge');

    criteres[`c${SEUIL_COMPTEUR - 1}`] = 'x';
    assert.equal(construireResumeFiltres(criteres, DEFS).mode, 'compteur');
});

test('critère inconnu du canvas : la clé sert de libellé, jamais de plantage', () => {
    const resume = construireResumeFiltres({ champFantome: 'abc' }, DEFS);
    assert.equal(resume.mode, 'badge');
    assert.deepEqual(resume.filtres[0], {
        cle: 'champFantome',
        libelle: 'champFantome',
        texte: 'champFantome : "abc"',
    });
});

test('définitions absentes ou non tableau : dégradation propre', () => {
    for (const defs of [null, undefined, 'pas un tableau']) {
        const resume = construireResumeFiltres({ client: { value: 'x' } }, defs);
        assert.equal(resume.mode, 'badge');
        assert.equal(resume.filtres[0].libelle, 'client');
    }
});

test('la clé de chaque filtre est conservée telle quelle (retrait ciblé)', () => {
    // Le bouton × renvoie cette clé au cerveau : elle doit être intacte, y compris
    // pour les critères synthétiques préfixés « __ ».
    const resume = construireResumeFiltres(
        { __mon_portefeuille__: { value: '7', label: 'Serge' }, actif: '1' },
        DEFS,
    );
    assert.deepEqual(resume.filtres.map(f => f.cle), ['__mon_portefeuille__', 'actif']);
});

test('formatterTexteFiltre — plages de dates partielles', () => {
    const def = { Nom: 'endingAt', Display: 'Échéance', Type: 'DateTimeRange' };
    assert.equal(
        formatterTexteFiltre('Échéance', { from: '01/01/2026', to: '31/01/2026' }, def),
        'Échéance : du 01/01/2026 au 31/01/2026',
    );
    assert.equal(
        formatterTexteFiltre('Échéance', { from: '01/01/2026' }, def),
        'Échéance : à partir du 01/01/2026',
    );
    assert.equal(
        formatterTexteFiltre('Échéance', { to: '31/01/2026' }, def),
        "Échéance : jusqu'au 31/01/2026",
    );
});

test('formatterTexteFiltre — relation : on affiche le libellé, jamais l’id', () => {
    const def = { Nom: 'client', Display: 'Client', Type: 'Relation' };
    assert.equal(formatterTexteFiltre('Client', { value: '42', label: 'KIN AVIA' }, def), 'Client : KIN AVIA');
    // Sans libellé, repli sur la valeur brute plutôt qu'un badge vide.
    assert.equal(formatterTexteFiltre('Client', { value: '42' }, def), 'Client : 42');
});

test('formatterTexteFiltre — booléen : libellé métier issu du canvas', () => {
    const def = { Nom: 'actif', Display: 'Actif', Type: 'Boolean', Valeur: { 1: 'Oui', 0: 'Non' } };
    assert.equal(formatterTexteFiltre('Actif', '1', def), 'Actif : Oui');
    assert.equal(formatterTexteFiltre('Actif', { value: '0' }, def), 'Actif : Non');
});

test('formatterTexteFiltre — nombre : opérateur conservé', () => {
    const def = { Nom: 'primeTotale', Display: 'Prime totale', Type: 'Number' };
    assert.equal(
        formatterTexteFiltre('Prime totale', { operator: '>=', value: 1000 }, def),
        'Prime totale >= 1000',
    );
});

test('formatterTexteFiltre — texte et repli sans définition', () => {
    const def = { Nom: 'refPolice', Display: 'Réf. Police', Type: 'Text' };
    assert.equal(
        formatterTexteFiltre('Réf. Police', { operator: 'LIKE', value: 'POL-2026' }, def),
        'Réf. Police : "POL-2026"',
    );
    assert.equal(formatterTexteFiltre('Réf. Police', 'POL-2026', null), 'Réf. Police : "POL-2026"');
});
