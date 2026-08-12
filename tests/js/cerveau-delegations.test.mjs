/**
 * Tests fonctionnels de la carte des délégations du bus `cerveau:event`
 * (assets/controllers/cerveau-delegations.js) — cœur pur, plus un CONTRÔLE CROISÉ
 * avec le code réel des contrôleurs.
 * Lancement : node --test tests/js/
 *
 * POURQUOI LE CONTRÔLE CROISÉ. Une liste de types recopiée à la main pourrit : le
 * jour où un contrôleur cesse de traiter son événement, la carte le tait encore et
 * l'avertissement — dont c'est précisément le rôle — ne se rallume jamais. On
 * vérifie donc que chaque délégation déclarée correspond à du code qui existe.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
    TYPES_DELEGUES,
    PREFIXES_DELEGUES,
    estDelegue,
    proprietaireDe,
} from '../../assets/controllers/cerveau-delegations.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const controleur = (nom) => join(RACINE, 'assets', 'controllers', `${nom}_controller.js`);

test('les types réellement délégués sont reconnus', () => {
    // Celui de l'incident du 2026-08-12 : chaque nouvelle conversation produisait
    // un avertissement rouge qui ne signalait rien.
    assert.equal(estDelegue('ket:conversation.new'), true);
    assert.equal(estDelegue('ket:mutation.execute'), true);
    assert.equal(estDelegue('ia:conversation.delete-execute'), true);
    assert.equal(estDelegue('weights:delete'), true);
});

test('un type que PERSONNE ne traite reste signalé', () => {
    // C'est tout l'intérêt de l'avertissement : il ne doit pas s'éteindre en grand.
    assert.equal(estDelegue('ui:quelque-chose.inconnu'), false);
    assert.equal(estDelegue('ket:invente'), false);
    assert.equal(estDelegue(''), false);
    assert.equal(estDelegue(undefined), false);
    assert.equal(estDelegue(null), false);
    assert.equal(estDelegue(42), false);
});

test('les types engendrés à l’exécution sont reconnus par leur préfixe', () => {
    // confirm-action numérote ses instances : seul le préfixe est stable.
    assert.equal(estDelegue('confirm-action:1'), true);
    assert.equal(estDelegue('confirm-action:37'), true);
    assert.equal(proprietaireDe('confirm-action:37'), 'confirm-action');
    // Le préfixe ne doit pas devenir un passe-droit sur un autre nom.
    assert.equal(estDelegue('confirm-actionnaire'), false);
});

test('chaque délégation nomme son propriétaire', () => {
    for (const [type, proprietaire] of Object.entries(TYPES_DELEGUES)) {
        assert.equal(proprietaireDe(type), proprietaire, `propriétaire de ${type}`);
        assert.ok(proprietaire.length > 0, `${type} doit nommer un contrôleur`);
    }
    assert.equal(proprietaireDe('inconnu'), null);
});

test('CONTRÔLE CROISÉ : le contrôleur déclaré propriétaire existe et cite le type', () => {
    for (const [type, proprietaire] of Object.entries(TYPES_DELEGUES)) {
        const chemin = controleur(proprietaire);
        assert.ok(existsSync(chemin), `le contrôleur « ${proprietaire} » (propriétaire de ${type}) doit exister`);

        const source = readFileSync(chemin, 'utf8');
        assert.ok(
            source.includes(type),
            `« ${proprietaire} » est déclaré propriétaire de « ${type} » mais ne le cite plus : `
            + 'la délégation est périmée, l’avertissement du Cerveau ne se rallumera jamais.',
        );
    }
});

test('CONTRÔLE CROISÉ : un propriétaire écoute bien le bus', () => {
    const proprietaires = new Set(Object.values(TYPES_DELEGUES));
    for (const proprietaire of proprietaires) {
        const source = readFileSync(controleur(proprietaire), 'utf8');
        assert.ok(
            source.includes("addEventListener('cerveau:event'"),
            `« ${proprietaire} » doit écouter cerveau:event pour recevoir ce qu'on lui délègue`,
        );
    }
});

test('CONTRÔLE CROISÉ : le Cerveau ne traite pas lui-même un type déclaré délégué', () => {
    const cerveau = readFileSync(controleur('cerveau'), 'utf8');
    for (const type of Object.keys(TYPES_DELEGUES)) {
        assert.ok(
            !cerveau.includes(`case '${type}'`),
            `« ${type} » est déclaré délégué ET traité par le Cerveau : une seule des deux doit rester.`,
        );
    }
});

test('les préfixes déclarés se terminent par un séparateur', () => {
    for (const prefixe of PREFIXES_DELEGUES) {
        assert.ok(prefixe.endsWith(':'), `« ${prefixe} » doit finir par « : » pour ne pas capturer un voisin`);
    }
});
