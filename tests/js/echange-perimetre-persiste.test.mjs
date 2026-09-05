/**
 * Tests de la PERSISTANCE DU PÉRIMÈTRE D'ÉCHANGE
 * (assets/controllers/echange-perimetre-persiste.js) — logique pure, ni DOM ni stockage.
 *
 * Ce qui est protégé ici : qu'un F5 ne fasse pas refaire quarante-deux cases. Et
 * surtout la décision qui rend cette mémoire sûre — on retient ce que l'utilisateur a
 * ÉCARTÉ, jamais ce qu'il a gardé. Une donnée qui s'ouvre à l'échange après coup arrive
 * ainsi cochée ; l'inverse l'aurait exclue des fichiers sans que rien ne le dise.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    cleDuPerimetre,
    exclusionsARestaurer,
    exclusionsDe,
    meriteMemorisation,
} from '../../assets/controllers/echange-perimetre-persiste.js';

// ─────────────────────────────────────────────────────────────────────────────
// La clé
// ─────────────────────────────────────────────────────────────────────────────

test('la clé sépare les cabinets ET les onglets', () => {
    assert.notEqual(cleDuPerimetre(1, 'exporter'), cleDuPerimetre(2, 'exporter'));

    // « Exporter ma production » et « réimporter tout » sont deux intentions : les
    // confondre ferait qu'un choix d'export restreindrait un import, en silence.
    assert.notEqual(cleDuPerimetre(1, 'exporter'), cleDuPerimetre(1, 'importer'));
});

// ─────────────────────────────────────────────────────────────────────────────
// Ce qu'on retient
// ─────────────────────────────────────────────────────────────────────────────

test('on retient les données ÉCARTÉES, pas celles qui sont gardées', () => {
    const exclusions = exclusionsDe([
        { code: 'Client', retenu: true },
        { code: 'Taxe', retenu: false },
        { code: 'Avenant', retenu: true },
        { code: 'Monnaie', retenu: false },
    ]);

    assert.deepEqual(exclusions, ['Monnaie', 'Taxe']);
});

test('la valeur retenue est triée, donc stable d’une exécution à l’autre', () => {
    const a = exclusionsDe([
        { code: 'Taxe', retenu: false },
        { code: 'Monnaie', retenu: false },
    ]);
    const b = exclusionsDe([
        { code: 'Monnaie', retenu: false },
        { code: 'Taxe', retenu: false },
    ]);

    assert.deepEqual(a, b);
});

test('une entrée sans code n’encombre pas la mémoire', () => {
    assert.deepEqual(exclusionsDe([{ code: '', retenu: false }, null, { retenu: false }]), []);
});

test('une saisie qui n’est pas une liste ne fait pas tomber l’écran', () => {
    assert.deepEqual(exclusionsDe(null), []);
    assert.deepEqual(exclusionsDe('Taxe'), []);
});

// ─────────────────────────────────────────────────────────────────────────────
// Ce qu'on repose
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ⚠ LA DÉCISION CENTRALE, dite à l'envers pour qu'elle se voie.
 *
 * Si l'on avait mémorisé les INCLUSIONS, une donnée ouverte à l'échange depuis le
 * dernier passage — ou rendue lisible par un droit ajouté — reviendrait décochée.
 * Elle manquerait au fichier, et personne ne le saurait avant de chercher pourquoi
 * l'import d'en face est incomplet.
 */
test('une donnée apparue depuis le dernier passage arrive RETENUE', () => {
    const memorise = ['Taxe'];
    const codesConnus = ['Client', 'Taxe', 'Sinistre'];

    // Seule « Taxe » est réputée écartée : « Sinistre », inconnue au moment du choix,
    // n'est pas dans la liste, donc reste cochée.
    assert.deepEqual(exclusionsARestaurer(memorise, codesConnus), ['Taxe']);
});

test('un code disparu du périmètre est ignoré', () => {
    assert.deepEqual(
        exclusionsARestaurer(['Taxe', 'EntiteRetiree'], ['Client', 'Taxe']),
        ['Taxe'],
    );
});

test('un stockage corrompu se lit comme une absence de choix', () => {
    assert.deepEqual(exclusionsARestaurer('n’importe quoi', ['Client']), []);
    assert.deepEqual(exclusionsARestaurer(null, ['Client']), []);
    assert.deepEqual(exclusionsARestaurer([42, { code: 'Client' }], ['Client']), []);
});

// ─────────────────────────────────────────────────────────────────────────────
// Écrire ou effacer
// ─────────────────────────────────────────────────────────────────────────────

test('rien d’écarté n’écrit rien : l’entrée est effacée', () => {
    // Ici, contrairement aux filtres d'onglet, un choix vide n'est PAS une information :
    // aucune donnée n'est écartée par défaut, donc « rien d'exclu » est l'état de départ.
    assert.equal(meriteMemorisation([]), false);
    assert.equal(meriteMemorisation(['Taxe']), true);
    assert.equal(meriteMemorisation(null), false);
});
