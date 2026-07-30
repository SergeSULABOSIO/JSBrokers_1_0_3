/**
 * Tests de la copie presse-papiers d'une bulle
 * (assets/controllers/assistant-message-image.js).
 *
 * On ne teste PAS la rasterisation (elle exige un vrai navigateur et html2canvas),
 * mais la dégradation gracieuse : sur un navigateur sans écriture d'image dans le
 * presse-papiers — Firefox, ou toute page hors contexte sécurisé —, l'utilisateur
 * doit recevoir un message qui l'oriente vers « Exporter en image », jamais une
 * erreur muette.
 * Lancement : node --test tests/js/
 */
import { test, afterEach } from 'node:test';
import assert from 'node:assert/strict';

import { copierBulleDansPressePapier } from '../../assets/controllers/assistant-message-image.js';

/** Bulle factice : la capture n'est jamais atteinte dans ces scénarios. */
const BULLE = { closest: () => null };

afterEach(() => {
    delete globalThis.navigator;
    delete globalThis.window;
});

test('sans API presse-papiers, l\'erreur est identifiable pour l\'appelant', async () => {
    globalThis.navigator = {};
    globalThis.window = {};

    await assert.rejects(
        () => copierBulleDansPressePapier(BULLE),
        (erreur) => erreur.code === 'non-supporte',
    );
});

test('sans ClipboardItem (image non supportée), même dégradation', async () => {
    // Cas Firefox : navigator.clipboard.write existe, mais pas ClipboardItem.
    globalThis.navigator = { clipboard: { write: () => Promise.resolve() } };
    globalThis.window = {};

    await assert.rejects(
        () => copierBulleDansPressePapier(BULLE),
        (erreur) => erreur.code === 'non-supporte',
    );
});

test('sans bulle, la copie ne fait rien et ne lève pas', async () => {
    globalThis.navigator = {};
    globalThis.window = {};

    await assert.doesNotReject(() => copierBulleDansPressePapier(null));
});
