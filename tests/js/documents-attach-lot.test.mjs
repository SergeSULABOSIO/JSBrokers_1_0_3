/**
 * Tests du TRI D'UN LOT de fichiers avant attachement
 * (assets/controllers/documents-attach-lot.js) — logique pure, aucun DOM.
 *
 * Ce qui est protégé ici : ce que l'utilisateur voit AVANT d'envoyer. Un fichier écarté
 * doit être NOMMÉ avec son motif — un fichier qui disparaît de la liste sans un mot
 * laisse croire qu'il est parti avec les autres, et c'est précisément celui qui
 * manquera au dossier.
 *
 * Le serveur reste seul juge (DocumentController::motifDeRefus) : ce tri lui est
 * volontairement identique, mais il ne le remplace pas.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { trierLot, motifDeRefus, extensionDe, tailleLisible } from '../../assets/controllers/documents-attach-lot.js';

const LIMITES = { maxSize: 10 * 1024 * 1024, extensions: ['pdf', 'png', 'txt', 'docx'] };
const fichier = (name, size = 1024) => ({ name, size });

test('un lot valide passe entièrement', () => {
    const { retenus, refuses } = trierLot(
        [fichier('contrat.pdf'), fichier('photo.png')],
        [],
        LIMITES,
    );

    assert.equal(retenus.length, 2);
    assert.deepEqual(refuses, []);
});

test('un format hors liste est refusé, et le motif le NOMME', () => {
    const { retenus, refuses } = trierLot([fichier('script.exe')], [], LIMITES);

    assert.equal(retenus.length, 0);
    assert.equal(refuses.length, 1);
    assert.equal(refuses[0].nom, 'script.exe');
    assert.match(refuses[0].motif, /exe/, 'le motif doit citer le format en cause');
});

test('un fichier trop lourd est refusé avec la borne, pas un « trop gros » sec', () => {
    const { refuses } = trierLot([fichier('enorme.pdf', 11 * 1024 * 1024)], [], LIMITES);

    assert.equal(refuses.length, 1);
    assert.match(refuses[0].motif, /10,0 Mo/, 'la borne doit être lisible dans le motif');
});

test('les fichiers valides du lot passent malgré un intrus', () => {
    const { retenus, refuses } = trierLot(
        [fichier('valide.pdf'), fichier('intrus.exe'), fichier('autre.txt')],
        [],
        LIMITES,
    );

    assert.deepEqual(retenus.map((f) => f.name), ['valide.pdf', 'autre.txt']);
    assert.equal(refuses.length, 1);
});

test('un doublon de nom est écarté : on ne verse pas deux fois la même pièce', () => {
    const { retenus, refuses } = trierLot(
        [fichier('contrat.pdf')],
        [fichier('contrat.pdf')],
        LIMITES,
    );

    assert.equal(retenus.length, 0);
    assert.equal(refuses[0].motif, 'déjà dans la liste');
});

test('un doublon DANS le même lot est écarté aussi', () => {
    const { retenus, refuses } = trierLot(
        [fichier('contrat.pdf'), fichier('contrat.pdf')],
        [],
        LIMITES,
    );

    assert.equal(retenus.length, 1);
    assert.equal(refuses.length, 1);
});

test('un fichier vide est refusé (dossier glissé, lecture impossible)', () => {
    assert.equal(motifDeRefus(fichier('dossier', 0), LIMITES), 'fichier vide');
});

test("sans bornes déclarées, rien n'est refusé pour le format", () => {
    assert.equal(motifDeRefus(fichier('inconnu.zip'), {}), null);
});

test('extensionDe ignore la casse et les noms sans point', () => {
    assert.equal(extensionDe('Contrat.PDF'), 'pdf');
    assert.equal(extensionDe('sansextension'), '');
    assert.equal(extensionDe('.cache'), '', 'un nom qui COMMENCE par un point n’a pas d’extension');
});

test('tailleLisible suit l’échelle du reste de l’application', () => {
    assert.equal(tailleLisible(512), '512 o');
    assert.equal(tailleLisible(2048), '2,0 Ko');
    assert.equal(tailleLisible(10 * 1024 * 1024), '10,0 Mo');
});
