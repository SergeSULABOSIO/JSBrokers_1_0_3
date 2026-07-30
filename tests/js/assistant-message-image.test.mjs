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

import {
    MAX_LARGEUR,
    MAX_HAUTEUR,
    echelleAdaptee,
    copierBulleDansPressePapier,
} from '../../assets/controllers/assistant-message-image.js';

/** Bulle factice : la capture n'est jamais atteinte dans ces scénarios. */
const BULLE = { closest: () => null };

afterEach(() => {
    delete globalThis.navigator;
    delete globalThis.window;
});

/** Marge appliquée autour de la bulle par la capture (MARGE, 12 px de chaque côté). */
const MARGE_TOTALE = 24;

test('echelleAdaptee garde l\'échelle souhaitée sur une bulle ordinaire', () => {
    // Colonne de chat étroite, réponse de taille normale : pleine netteté.
    assert.equal(echelleAdaptee({ largeur: 430, hauteur: 600 }), 2);
    assert.equal(echelleAdaptee({ largeur: 430, hauteur: 600 }, 3), 3);
});

test('echelleAdaptee réduit l\'échelle sur une réponse très longue', () => {
    // 6500 px × 2 dépasserait MAX_HAUTEUR : mieux vaut perdre en finesse que
    // faire refuser l'envoi par le serveur.
    const echelle = echelleAdaptee({ largeur: 430, hauteur: 6500 });

    assert.ok(echelle < 2, `échelle attendue < 2, obtenue ${echelle}`);
    assert.ok((6500 + MARGE_TOTALE) * echelle <= MAX_HAUTEUR);
});

test('echelleAdaptee respecte aussi la borne de largeur', () => {
    const echelle = echelleAdaptee({ largeur: 3000, hauteur: 400 });

    assert.ok((3000 + MARGE_TOTALE) * echelle <= MAX_LARGEUR);
});

test('echelleAdaptee ne descend jamais sous 1', () => {
    // Au-delà, l'image serait illisible ; on laisse le serveur trancher.
    assert.equal(echelleAdaptee({ largeur: 430, hauteur: 40000 }), 1);
    assert.equal(echelleAdaptee({ largeur: 20000, hauteur: 400 }), 1);
});

test('echelleAdaptee tolère des tailles absentes ou nulles', () => {
    // getBoundingClientRect sur un élément masqué renvoie 0.
    for (const taille of [{ largeur: 0, hauteur: 0 }, { largeur: NaN, hauteur: 600 }, {}]) {
        const echelle = echelleAdaptee(taille);
        assert.ok(echelle >= 1 && echelle <= 2, JSON.stringify(taille));
    }
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
