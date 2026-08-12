/**
 * Tests fonctionnels du cœur PUR de l'armement de la boîte de confirmation
 * (assets/controllers/confirmation-armement.js) — aucun DOM, aucun Bootstrap.
 * Lancement : node --test tests/js/
 *
 * L'INCIDENT (2026-08-12). Sur « Nouvelle conversation », la boîte s'ouvrait et se
 * confirmait d'elle-même : le fil courant était remplacé sans décision de personne.
 * La trace du navigateur montre un seul geste produisant l'ouverture PUIS la
 * confirmation — et la garde d'alors, qui ne tenait qu'à « shown.bs.modal », était
 * déjà réarmée à cet instant.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    DELAI_ARMEMENT_MS,
    confirmationAutorisee,
} from '../../assets/controllers/confirmation-armement.js';

test('une modale non affichée ne confirme jamais', () => {
    assert.equal(confirmationAutorisee({ armed: false, ouvertA: 0, maintenant: 10_000 }), false);
    // Fail-closed : tout ce qui n'est pas exactement true refuse.
    assert.equal(confirmationAutorisee({ armed: undefined, ouvertA: 0, maintenant: 10_000 }), false);
    assert.equal(confirmationAutorisee({ armed: 1, ouvertA: 0, maintenant: 10_000 }), false);
    assert.equal(confirmationAutorisee({ armed: 'oui', ouvertA: 0, maintenant: 10_000 }), false);
});

test('LE CAS DE L’INCIDENT : armée mais dans le geste d’ouverture, la confirmation est refusée', () => {
    // « armed » vaut true — c'est précisément l'état observé chez l'utilisateur,
    // Bootstrap ayant émis shown.bs.modal sans animation à jouer. Seul le délai
    // peut encore protéger.
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 1_000, maintenant: 1_000 }), false);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 1_000, maintenant: 1_050 }), false);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 1_000, maintenant: 1_399 }), false);
});

test('passé le délai, l’utilisateur confirme normalement', () => {
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 1_000, maintenant: 1_400 }), true);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 1_000, maintenant: 5_000 }), true);
});

test('le seuil est celui déclaré, et il encadre le double-clic sans gêner la lecture', () => {
    assert.equal(DELAI_ARMEMENT_MS, 400);
    // Un double-clic système tient dans ~500 ms : le seuil doit être du même ordre,
    // sinon le second clic d'un double-clic confirmerait la boîte qu'il vient d'ouvrir.
    assert.ok(DELAI_ARMEMENT_MS >= 250, 'trop court : un double-clic passerait');
    // Et rester très en dessous du temps de lecture d'une question.
    assert.ok(DELAI_ARMEMENT_MS <= 800, 'trop long : la boîte semblerait bloquée');
});

test('un délai explicite est respecté (injection pour les tests)', () => {
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 0, maintenant: 100, delai: 50 }), true);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 0, maintenant: 40, delai: 50 }), false);
});

test('une horloge inexploitable refuse plutôt que de laisser passer', () => {
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: NaN, maintenant: 10_000 }), false);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 0, maintenant: NaN }), false);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: undefined, maintenant: 10_000 }), false);
    assert.equal(confirmationAutorisee({ armed: true, ouvertA: 0, maintenant: Infinity }), false);
});
