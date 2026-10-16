/**
 * LE CORPS D'UNE CONFIRMATION EST DU TEXTE, SAUF DEMANDE EXPLICITE.
 *
 * `confirm-action` transporte un corps écrit dans un attribut de gabarit — où Twig
 * place des noms saisis (cabinet, utilisateur, coupon…). Stimulus DÉCODE l'attribut :
 * injecté en HTML, `« <img onerror=…> »` s'exécutait chez l'agent de la console qui
 * ouvrait la confirmation. `body` et `corpsTexte` sont posés par textContent ; seul
 * `corpsHtml` porte du balisage — un appelant JS qui l'oublie obtient du texte.
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

test('body est du TEXTE : un appelant qui oublie corpsHtml n\'ouvre pas de faille', () => {
    assert.deepEqual(contenuDuCorps({ body: 'Fermer <img src=x onerror=alert(1)> ?' }), { html: false, valeur: 'Fermer <img src=x onerror=alert(1)> ?' });
});

test('corpsHtml, la seule porte du balisage, l\'emporte sur le texte', () => {
    assert.deepEqual(contenuDuCorps({ corpsHtml: 'Fermer <strong>X</strong> ?', body: 'x' }), { html: true, valeur: 'Fermer <strong>X</strong> ?' });
});

test('corpsTexte l\'emporte sur body', () => {
    assert.deepEqual(contenuDuCorps({ corpsTexte: 'texte', body: 'autre' }), { html: false, valeur: 'texte' });
});

// Les appelants qui composent un corps balisé passent par corpsHtml ET échappent leurs
// données avec la fonction partagée — plus de copie locale (safeNom, escapeHtml…).
test('chaque corps balisé passe par corpsHtml et par echapper', () => {
    for (const fichier of ['assistant-ia_controller.js', 'ket-mobile_controller.js', 'workspace-manager_controller.js', 'packs-editor_controller.js', 'weights-editor_controller.js']) {
        const code = source(fichier);
        assert.doesNotMatch(code, /\bbody:\s*`[^`]*<strong>/, `${fichier} : un body balisé serait désormais affiché en texte`);
        assert.match(code, /corpsHtml:\s*`[^`]*\$\{echapper\(/, `${fichier} : la donnée du corps balisé doit passer par echapper`);
        assert.match(code, /import \{ echapper \} from '\.\/echapper\.js'/, fichier);
    }
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

// LE TOAST : même garde, sur son branchement. Son texte cite des noms saisis et des
// refus du serveur ; il ne doit jamais rentrer dans la chaîne HTML du toast.
test('le toast pose son texte par textContent, jamais dans sa chaîne HTML', () => {
    const code = source('notification-manager_controller.js');
    assert.doesNotMatch(code, /\$\{\s*text\s*\}/);
    assert.match(code, /querySelector\('\.toast-body'\)\.textContent\s*=\s*text/);
});
