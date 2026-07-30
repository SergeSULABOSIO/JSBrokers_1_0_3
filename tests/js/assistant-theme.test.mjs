/**
 * Tests fonctionnels du cœur PUR du thème du chat de l'assistant IA
 * (assets/controllers/assistant-theme.js) — aucun DOM, aucun localStorage,
 * aucun matchMedia.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    THEME_CLAIR,
    THEME_SOMBRE,
    EVENEMENT_THEME,
    normaliserTheme,
    resoudreTheme,
    themeOppose,
} from '../../assets/controllers/assistant-theme.js';

test('normaliserTheme ne retient que les thèmes explicites', () => {
    assert.equal(normaliserTheme('light'), THEME_CLAIR);
    assert.equal(normaliserTheme('dark'), THEME_SOMBRE);
    // 'auto' = le serveur signale « aucun choix » → pas un thème explicite.
    assert.equal(normaliserTheme('auto'), null);
    assert.equal(normaliserTheme(''), null);
    assert.equal(normaliserTheme(undefined), null);
    assert.equal(normaliserTheme(null), null);
    assert.equal(normaliserTheme('Dark'), null); // sensible à la casse, pas de devinette
    assert.equal(normaliserTheme(42), null);
});

test('resoudreTheme retombe sur le clair sans aucune indication', () => {
    assert.equal(resoudreTheme(), THEME_CLAIR);
    assert.equal(resoudreTheme({}), THEME_CLAIR);
});

test('resoudreTheme suit la préférence système en l\'absence de choix', () => {
    assert.equal(resoudreTheme({ prefereSombre: true }), THEME_SOMBRE);
    assert.equal(resoudreTheme({ prefereSombre: false }), THEME_CLAIR);
    assert.equal(resoudreTheme({ stocke: 'auto', prefereSombre: true }), THEME_SOMBRE);
});

test('resoudreTheme fait PRIMER le choix explicite sur la préférence système', () => {
    // Le cas qui compte : poste en sombre, mais l'utilisateur a choisi le clair.
    assert.equal(resoudreTheme({ stocke: 'light', prefereSombre: true }), THEME_CLAIR);
    assert.equal(resoudreTheme({ stocke: 'dark', prefereSombre: false }), THEME_SOMBRE);
});

test('resoudreTheme ignore une valeur stockée inexploitable et retombe sur le système', () => {
    assert.equal(resoudreTheme({ stocke: 'bidon', prefereSombre: true }), THEME_SOMBRE);
    assert.equal(resoudreTheme({ stocke: 'bidon', prefereSombre: false }), THEME_CLAIR);
});

test('themeOppose est involutif et ne renvoie jamais « auto »', () => {
    assert.equal(themeOppose(THEME_CLAIR), THEME_SOMBRE);
    assert.equal(themeOppose(THEME_SOMBRE), THEME_CLAIR);
    assert.equal(themeOppose(themeOppose(THEME_SOMBRE)), THEME_SOMBRE);
    // Depuis un état non résolu, la bascule produit toujours un choix explicite.
    assert.equal(themeOppose('auto'), THEME_SOMBRE);
    assert.equal(themeOppose(undefined), THEME_SOMBRE);
});

test('le nom de l\'événement de bascule est stable (contrat inter-instances)', () => {
    // Plusieurs conversations ouvertes se synchronisent par cet événement :
    // le renommer casserait la cohérence sans qu\'aucun test ne le voie.
    assert.equal(EVENEMENT_THEME, 'assistant:theme.changed');
});
