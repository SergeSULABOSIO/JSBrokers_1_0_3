/**
 * LE CORPS D'UNE CONFIRMATION EST DU TEXTE, SAUF DEMANDE EXPLICITE.
 *
 * `confirm-action` transporte un corps écrit dans un attribut de gabarit — où Twig
 * place des noms saisis (cabinet, utilisateur, coupon…). Stimulus DÉCODE l'attribut :
 * injecté en HTML, `« <img onerror=…> »` s'exécutait chez l'agent de la console qui
 * ouvrait la confirmation. `corpsTexte` est posé par textContent ; `body` (HTML) reste
 * réservé aux appelants JS qui composent eux-mêmes un balisage échappé.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { contenuDuCorps } from '../../assets/controllers/confirmation-corps.js';

const CONTROLEURS = join(dirname(fileURLToPath(import.meta.url)), '../../assets/controllers');
const source = (fichier) => readFileSync(join(CONTROLEURS, fichier), 'utf8');

test('un corps texte reste du texte, même s\'il ressemble à du HTML', () => {
    assert.deepEqual(
        contenuDuCorps({ corpsTexte: 'Supprimer « <img src=x onerror=alert(1)> » ?' }),
        { html: false, valeur: 'Supprimer « <img src=x onerror=alert(1)> » ?' },
    );
});

test('le texte l\'emporte sur un body HTML transmis en même temps', () => {
    assert.deepEqual(contenuDuCorps({ corpsTexte: 'texte', body: '<b>html</b>' }), { html: false, valeur: 'texte' });
});

test('un body HTML venu d\'un appelant JS reste du HTML', () => {
    assert.deepEqual(contenuDuCorps({ body: 'Fermer <strong>X</strong> ?' }), { html: true, valeur: 'Fermer <strong>X</strong> ?' });
});

test('sans corps, la question par défaut, en texte', () => {
    assert.deepEqual(contenuDuCorps({}), { html: false, valeur: 'Êtes-vous sûr ?' });
    assert.deepEqual(contenuDuCorps(undefined), { html: false, valeur: 'Êtes-vous sûr ?' });
    assert.deepEqual(contenuDuCorps({ corpsTexte: '', body: '' }), { html: false, valeur: 'Êtes-vous sûr ?' });
});

// LE BRANCHEMENT, PAS SEULEMENT LA RÈGLE : remettre `body: this.bodyValue` dans
// confirm-action, ou `innerHTML` sur le titre, rouvrirait la faille sans qu'aucun test
// de la fonction pure ne bouge.
test('confirm-action transporte son corps en texte, jamais en HTML', () => {
    const code = source('confirm-action_controller.js');
    assert.match(code, /corpsTexte:\s*this\.bodyValue/);
    assert.doesNotMatch(code, /\bbody:\s*this\.bodyValue/);
});

test('la confirmation pose son titre en texte et son corps par contenuDuCorps', () => {
    const code = source('confirmation-dialog_controller.js');
    assert.match(code, /titleTarget\.textContent\s*=/);
    assert.doesNotMatch(code, /titleTarget\.innerHTML\s*=/);
    assert.match(code, /contenuDuCorps\(payload\)/);
    assert.doesNotMatch(code, /bodyTarget\.innerHTML\s*=\s*body\b/);
});
