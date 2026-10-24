/**
 * L'INFOBULLE DES PAGES PUBLIQUES RESTE EN ES2017 (chantier 3, commit 3b).
 * Lancement : node --test tests/js/
 *
 * Le relevé de compte public (`/soa/{token}`) est ouvert par les CLIENTS, sur des navigateurs
 * parfois anciens : comme `gestes-autonomes.js`, rien de plus récent qu'ES2017 dans ce qui y est
 * chargé. Une syntaxe trop récente ne se dégrade pas : le module entier échoue au chargement.
 *
 * Les modules contrôlés sont DÉCOUVERTS : le point d'entrée des pages autonomes, puis tout ce
 * qu'il importe, de proche en proche — un nouvel import dans le moteur n'échappe pas au test.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const ENTREE = join(RACINE, 'assets/infobulles-autonomes.js');

/** Le module et tout ce qu'il importe par chemin relatif, de proche en proche. */
function modulesCharges(entree) {
    const vus = new Set();
    const aVoir = [entree];
    while (aVoir.length) {
        const chemin = aVoir.pop();
        if (vus.has(chemin)) continue;
        vus.add(chemin);
        for (const [, cible] of readFileSync(chemin, 'utf8').matchAll(/^\s*import\s[^'"]*['"](\.{1,2}\/[^'"]+)['"]/gm)) {
            aVoir.push(resolve(dirname(chemin), cible));
        }
    }

    return [...vus];
}

/** Le code sans commentaires ni chaînes : on ne juge que la syntaxe. */
function codeSeul(source) {
    let sortie = '';
    for (let i = 0; i < source.length; i++) {
        const c = source[i];
        const suivant = source[i + 1];
        if (c === '/' && suivant === '/') { while (i < source.length && source[i] !== '\n') i++; sortie += '\n'; continue; }
        if (c === '/' && suivant === '*') { i += 2; while (i < source.length && !(source[i] === '*' && source[i + 1] === '/')) i++; i++; continue; }
        if (c === '"' || c === "'" || c === '`') {
            const fin = c;
            i++;
            while (i < source.length && source[i] !== fin) { if (source[i] === '\\') i++; i++; }
            sortie += '""';
            continue;
        }
        sortie += c;
    }

    return sortie;
}

const INTERDITS = [
    [/\?\.(?!\d)/, 'chaînage optionnel `?.` (ES2020)'],
    [/\?\?/, 'coalescence `??` (ES2020)'],
    [/catch\s*\{/, '`catch` sans variable (ES2019)'],
    [/\{\s*\.\.\.|,\s*\.\.\.\w+\s*\}/, 'décomposition d’objet `{...x}` (ES2018)'],
    [/\.flat(Map)?\(/, '`flat` / `flatMap` (ES2019)'],
    [/\.matchAll\(/, '`matchAll` (ES2020)'],
    [/\.finally\(/, '`Promise.finally` (ES2018)'],
    [/for\s+await\b/, 'itération asynchrone (ES2018)'],
    [/Object\.fromEntries\b/, '`Object.fromEntries` (ES2019)'],
    [/\(\?<[=!a-zA-Z]/, 'groupe nommé ou regard arrière dans une regex (ES2018)'],
    [/\.at\(-?\d/, '`Array.at` (ES2022)'],
    [/\breplaceAll\(/, '`replaceAll` (ES2021)'],
];

const MODULES = modulesCharges(ENTREE);

test('la découverte suit les imports : le moteur et ses deux modules en font partie', () => {
    const noms = MODULES.map((m) => m.replace(/\\/g, '/').split('/').pop());
    for (const attendu of ['infobulles-autonomes.js', 'infobulle-moteur.js', 'infobulle-contenu.js', 'infobulle-position.js']) {
        assert.ok(noms.includes(attendu), `${attendu} manque : ${noms.join(', ')}`);
    }
});

for (const chemin of MODULES) {
    test(`${chemin.slice(RACINE.length + 1)} : rien de plus récent qu’ES2017`, () => {
        const code = codeSeul(readFileSync(chemin, 'utf8'));
        for (const [motif, quoi] of INTERDITS) {
            assert.doesNotMatch(code, motif, quoi);
        }
    });
}

test('le contrôleur Stimulus n’est qu’une coquille : le moteur est partagé avec les pages publiques', () => {
    const controleur = readFileSync(join(RACINE, 'assets/controllers/infobulle_controller.js'), 'utf8');
    assert.match(controleur, /from '\.\/infobulle-moteur\.js'/);
    assert.ok(controleur.split('\n').length < 60, 'le comportement vit dans infobulle-moteur.js, pas dans le contrôleur');
    assert.match(readFileSync(ENTREE, 'utf8'), /from '\.\/controllers\/infobulle-moteur\.js'/);
});

test('les pages autonomes : l’importmap AVANT tout module (sinon ignorée, plus de bulle)', () => {
    for (const page of ['templates/admin/soa/soa_client_standalone.html.twig', 'templates/public/soa/soa_client_public.html.twig']) {
        const source = readFileSync(join(RACINE, page), 'utf8');
        const importmap = source.indexOf("importmap('infobulles-autonomes'");
        const module = source.indexOf('type="module"');
        assert.ok(importmap !== -1 && module !== -1 && importmap < module, `${page} : l’importmap doit précéder le premier module`);
    }
});
