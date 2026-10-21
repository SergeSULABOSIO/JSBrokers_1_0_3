/**
 * LES MESSAGES (toasts) — chantier 2. Règles pures de assets/controllers/notification-regles.js,
 * plus un CONTRÔLE CROISÉ du gabarit et du contrôleur.
 * Lancement : node --test tests/js/
 *
 * Exigences de Serge (2026-10-20) : deux régions PERSISTANTES dès le chargement de la page
 * (`role="status"` pour une information, `role="alert"` réservée aux erreurs), où l'on INSÈRE
 * le message — une région créée en même temps que son message n'est pas annoncée de façon
 * fiable ; une erreur ne disparaît pas seule ; la temporisation se met en pause au survol et au
 * focus (Bootstrap le fait : vérifié au navigateur).
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { regionDuMessage, delaiDuMessage } from '../../assets/controllers/notification-regles.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (chemin) => readFileSync(join(RACINE, chemin), 'utf8');

test('une ERREUR va dans la région alert ; tout le reste dans la région status', () => {
    assert.equal(regionDuMessage('error'), 'alerte');
    for (const type of ['success', 'info', 'warning', undefined, 'inconnu']) {
        assert.equal(regionDuMessage(type), 'statut', String(type));
    }
});

test('une erreur ne disparaît pas seule ; les autres restent le temps de lire (et d’agir)', () => {
    assert.equal(delaiDuMessage('error', false), null);
    assert.equal(delaiDuMessage('error', true), null);
    assert.equal(delaiDuMessage('success', false), 10000);
    assert.equal(delaiDuMessage('warning', true), 20000);
});

test('base.html.twig : les deux régions existent dès le chargement, dans le gestionnaire', () => {
    const base = source('templates/base.html.twig');
    const bloc = base.slice(base.indexOf('data-controller="notification-manager"') - 400, base.indexOf('data-controller="notification-manager"') + 900);
    assert.match(bloc, /role="status"[^>]*data-notification-manager-target="statut"|data-notification-manager-target="statut"[^>]*role="status"/);
    assert.match(bloc, /role="alert"[^>]*data-notification-manager-target="alerte"|data-notification-manager-target="alerte"[^>]*role="alert"/);
});

test('le toast n’est plus lui-même une région d’annonce (pas de rôle imbriqué)', () => {
    const code = source('assets/controllers/notification-manager_controller.js');
    const gabarit = code.slice(code.indexOf('const toastHTML'), code.indexOf('`;', code.indexOf('const toastHTML')));
    assert.doesNotMatch(gabarit, /role="alert"|aria-live/);
    assert.match(code, /regionDuMessage\(/);
    assert.match(code, /delaiDuMessage\(/);
});

// ── Revue du chantier 2 : des erreurs qui restent, mais pas sans fin ──────────────

import { messageEnDouble, erreursARetirer, PLAFOND_DES_ERREURS } from '../../assets/controllers/notification-regles.js';

test('un message identique déjà affiché n’est pas répété (ex. réseau coupé, toutes les 5 min)', () => {
    const affiches = [{ type: 'error', texte: 'Impossible de charger le document demandé.' }];
    assert.equal(messageEnDouble({ type: 'error', texte: 'Impossible de charger le document demandé.' }, affiches), true);
    assert.equal(messageEnDouble({ type: 'info', texte: 'Impossible de charger le document demandé.' }, affiches), false);
    assert.equal(messageEnDouble({ type: 'error', texte: 'Autre chose' }, affiches), false);
});

test('au plus trois erreurs à l’écran : la plus ancienne cède la place', () => {
    assert.equal(PLAFOND_DES_ERREURS, 3);
    assert.equal(erreursARetirer(0), 0);
    assert.equal(erreursARetirer(2), 0);
    assert.equal(erreursARetirer(3), 1);
    assert.equal(erreursARetirer(5), 3);
});

test('une erreur de formulaire n’est pas doublée par un toast : le pied du dialogue l’annonce', () => {
    const cerveau = source('assets/controllers/cerveau_controller.js');
    const cas = cerveau.slice(cerveau.indexOf("case 'app:form.validation-error':"), cerveau.indexOf('break;', cerveau.indexOf("case 'app:form.validation-error':")));
    assert.doesNotMatch(cas, /_showNotification\(/);
    // … dans la zone `alert` du pied, présente dès l'ouverture (dialogue-suivi.test.mjs).
    assert.match(source('assets/controllers/dialog-instance_controller.js'), /const zone = type === 'error' \? zoneAlerte : zoneStatut;/);
});

test('les régions ne relisent pas tout à chaque message (aria-atomic="false")', () => {
    const base = source('templates/base.html.twig');
    assert.match(base, /role="alert"[^>]*aria-atomic="false"/);
    assert.match(base, /role="status"[^>]*aria-atomic="false"/);
});
