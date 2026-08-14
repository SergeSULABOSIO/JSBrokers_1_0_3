/**
 * Tests du cœur PUR du panneau de téléchargement
 * (assets/controllers/assistant-files-download.js) — aucun DOM, aucun Stimulus.
 * Lancement : node --test tests/js/
 *
 * CE QUI EST EN JEU. Le panneau ne se contente plus d'aligner des boutons : il CHOISIT
 * entre une carte et un tableau numéroté selon le nombre de fichiers, et il met en forme
 * des tailles et des dates. Trois endroits où une régression ne casse rien, ne lève
 * rien, et ne se voit que sur une capture d'écran — celle où un fichier chargé le 14
 * s'affiche « 13/08/2026 » parce que `new Date('2026-08-14')` s'interprète en UTC.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    COLONNES,
    fichiersValides,
    formatDate,
    formatTaille,
    ligneFichier,
    modeAffichage,
    titrePanneau,
} from '../../assets/controllers/assistant-files-download.js';

const fichier = (extra = {}) => ({
    id: 1,
    nom: 'Contrat.pdf',
    format: 'PDF',
    taille: 2048,
    chargeLe: '2026-08-14',
    rattacheA: 'Avenant POL-130',
    url: '/admin/assistant-ia/api/documents/1/7/download',
    ...extra,
});

test('un seul fichier se présente en carte, plusieurs en tableau', () => {
    assert.equal(modeAffichage([fichier()]), 'carte');
    assert.equal(modeAffichage([fichier(), fichier({ id: 2 })]), 'tableau');
});

test('sans fichier affichable, le panneau ne s’affiche pas du tout', () => {
    assert.equal(modeAffichage([]), 'aucun');
    assert.equal(modeAffichage(null), 'aucun');
    assert.equal(modeAffichage(undefined), 'aucun');
});

test('une entrée sans URL est écartée : un bouton mort promet un fichier qui ne viendra pas', () => {
    const entrees = [fichier(), { id: 2, nom: 'Orphelin.pdf' }, fichier({ id: 3, url: '' })];

    assert.equal(fichiersValides(entrees).length, 1);
    assert.equal(modeAffichage(entrees), 'carte', 'Une seule entrée valide : carte, pas tableau.');
});

test('la date est rendue en jj/mm/aaaa, jamais en ISO, et sans décalage de fuseau', () => {
    assert.equal(formatDate('2026-08-14'), '14/08/2026');
    assert.equal(formatDate('2026-01-01'), '01/01/2026', 'Le 1er janvier ne doit pas reculer au 31 décembre.');
    assert.equal(formatDate(''), '');
    assert.equal(formatDate(null), '');
});

test('les tailles restent lisibles à chaque palier', () => {
    assert.equal(formatTaille(512), '512 o');
    assert.equal(formatTaille(2048), '2.0 Ko');
    assert.equal(formatTaille(3 * 1024 * 1024), '3.0 Mo');
    assert.equal(formatTaille(undefined), '', 'Une taille absente ne s’invente pas.');
});

test('une ligne numérote à partir de 1 et ne laisse aucune cellule vide', () => {
    const l = ligneFichier(fichier(), 0);

    assert.equal(l.n, '1', 'L’utilisateur désigne ensuite « le 1 », pas « le 0 ».');
    assert.equal(l.nom, 'Contrat.pdf');
    assert.equal(l.format, 'PDF');
    assert.equal(l.taille, '2.0 Ko');
    assert.equal(l.chargeLe, '14/08/2026');
    assert.equal(l.rattacheA, 'Avenant POL-130');
});

test('une valeur manquante devient un tiret : une cellule vide se lit comme un oubli', () => {
    const l = ligneFichier({ url: '/x' }, 4);

    assert.equal(l.n, '5');
    assert.equal(l.format, '—');
    assert.equal(l.taille, '—');
    assert.equal(l.chargeLe, '—');
    assert.equal(l.rattacheA, '—');
});

test('chaque colonne déclarée correspond à une clé réellement produite', () => {
    const l = ligneFichier(fichier(), 0);

    for (const colonne of COLONNES) {
        assert.ok(
            Object.prototype.hasOwnProperty.call(l, colonne.cle),
            `La colonne « ${colonne.libelle} » n’a aucune valeur : elle s’afficherait vide sur chaque ligne.`,
        );
    }
});

test('le titre annonce le nombre, qui est l’information utile', () => {
    assert.equal(titrePanneau(1), 'Document à télécharger');
    assert.equal(titrePanneau(6), '6 documents à télécharger');
});
