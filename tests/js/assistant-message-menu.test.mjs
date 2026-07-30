/**
 * Tests du cœur PUR des actions de bulle du chat Ket
 * (assets/controllers/assistant-message-menu.js) — aucun DOM, aucun réseau.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    FORMAT_PDF,
    FORMAT_WORD,
    FORMAT_MARKDOWN,
    FORMAT_IMAGE,
    FORMATS_SERVEUR,
    CLE_REPONDRE,
    CLE_EMAIL,
    CLE_EXPORT,
    urlExportMessage,
    urlDestinatairesMessage,
    nomFichierImage,
} from '../../assets/controllers/assistant-message-menu.js';

/** Valeur réelle de `sendUrlValue` : préfixe des trois routes de message. */
const SEND_URL = '/admin/assistant-ia/api/messages/7/42';

test('urlExportMessage suffixe la route d\'export au préfixe des messages', () => {
    assert.equal(urlExportMessage(SEND_URL, 99, FORMAT_PDF), '/admin/assistant-ia/api/messages/7/42/99/export/pdf');
    assert.equal(urlExportMessage(SEND_URL, 99, FORMAT_WORD), '/admin/assistant-ia/api/messages/7/42/99/export/word');
    assert.equal(urlExportMessage(SEND_URL, 99, FORMAT_MARKDOWN), '/admin/assistant-ia/api/messages/7/42/99/export/markdown');
});

test('urlDestinatairesMessage cible le picker du message', () => {
    assert.equal(urlDestinatairesMessage(SEND_URL, 99), '/admin/assistant-ia/api/messages/7/42/99/destinataires');
});

test('les constructeurs d\'URL absorbent un slash final', () => {
    // Selon la génération d'URL côté Twig, sendUrl peut arriver suffixé : pas de
    // « // » dans le chemin, qui ne matcherait aucune route.
    assert.equal(urlExportMessage(`${SEND_URL}/`, 99, FORMAT_PDF), '/admin/assistant-ia/api/messages/7/42/99/export/pdf');
    assert.equal(urlDestinatairesMessage(`${SEND_URL}///`, 99), '/admin/assistant-ia/api/messages/7/42/99/destinataires');
});

test('les constructeurs d\'URL acceptent un id numérique comme textuel', () => {
    assert.equal(urlExportMessage(SEND_URL, '99', FORMAT_PDF), urlExportMessage(SEND_URL, 99, FORMAT_PDF));
});

test('FORMATS_SERVEUR correspond à MessageExporter::FORMATS et exclut l\'image', () => {
    // L'image est capturée par le navigateur : aucune route serveur ne la sert.
    assert.deepEqual(FORMATS_SERVEUR, ['pdf', 'word', 'markdown']);
    assert.ok(!FORMATS_SERVEUR.includes(FORMAT_IMAGE));
});

test('les clés de menu sont uniques', () => {
    const cles = [CLE_REPONDRE, CLE_EMAIL, ...Object.values(CLE_EXPORT)];
    assert.equal(new Set(cles).size, cles.length);
});

test('chaque format dispose d\'une clé de menu, image incluse', () => {
    for (const format of [...FORMATS_SERVEUR, FORMAT_IMAGE]) {
        assert.equal(typeof CLE_EXPORT[format], 'string');
        assert.ok(CLE_EXPORT[format].length > 0);
    }
});

test('nomFichierImage produit un nom stable et sans donnée utilisateur', () => {
    assert.equal(nomFichierImage(99, new Date(2026, 6, 30)), 'message-ia-99-20260730.png');
    // Mois et jour sur deux chiffres.
    assert.equal(nomFichierImage(7, new Date(2026, 0, 5)), 'message-ia-7-20260105.png');
});
