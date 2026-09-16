/**
 * Tests du cœur PUR de la dictée vocale de Ket
 * (assets/controllers/dictee-transcript.js) — aucun micro, aucun DOM.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { fusionnerTranscripts } from '../../assets/controllers/dictee-transcript.js';

/**
 * Séquence réellement reçue d'un smartphone Android (capture du chat du
 * 2026-09-16) : chaque résultat reprend la phrase depuis le début, avec
 * doublons et un retour en arrière final.
 */
const SEQUENCE_ANDROID = [
    'ok', 'ok merci', 'ok merci', 'ok merci', 'ok merci', 'ok merci', 'ok merci',
    'ok merci est-ce', 'ok merci est-ce que', 'ok merci est-ce que tu',
    'ok merci est-ce que tu peux', 'ok merci est-ce que tu peux',
    'ok merci est-ce que tu peux me', 'ok merci est-ce que tu peux me',
    'ok merci est-ce que tu peux me faire', 'ok merci est-ce que tu peux me faire un',
    'ok merci est-ce que tu peux me faire un exemple',
    'ok merci est-ce que tu peux me faire un exemple un exemple toi-même tu imagines les informations pour un client particulier et puis tu me',
    'ok merci est-ce que tu peux me faire un exemple un exemple toi-même tu imagines les informations pour un client particulier et puis tu me tu me',
    'ok merci est-ce que tu peux me faire un exemple un exemple',
];

test('la séquence Android réelle donne la phrase une seule fois', () => {
    assert.equal(
        fusionnerTranscripts(SEQUENCE_ANDROID),
        'ok merci est-ce que tu peux me faire un exemple un exemple toi-même tu imagines les informations pour un client particulier et puis tu me tu me',
    );
});

test('« bonjour Cathy » dicté sur Android n\'est plus triplé', () => {
    assert.equal(fusionnerTranscripts(['bonjour', 'bonjour Cathy', 'bonjour Cathy']), 'bonjour Cathy');
});

test('sur ordinateur, les morceaux distincts sont mis bout à bout avec un espace', () => {
    assert.equal(
        fusionnerTranscripts(['bonjour Cathy', ' comment vas-tu']),
        'bonjour Cathy comment vas-tu',
    );
});

test('casse et espaces superflus ne créent pas de doublon', () => {
    assert.equal(fusionnerTranscripts(['Ok  merci ', 'ok merci est-ce que']), 'ok merci est-ce que');
});

test('liste vide, textes vides ou absents donnent une chaîne vide', () => {
    assert.equal(fusionnerTranscripts([]), '');
    assert.equal(fusionnerTranscripts(['', '   ', null, undefined]), '');
    assert.equal(fusionnerTranscripts(undefined), '');
});
