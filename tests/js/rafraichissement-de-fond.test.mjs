/**
 * LES MISES À JOUR AUTOMATIQUES SE TAISENT QUAND ELLES NE PEUVENT RIEN APPORTER.
 *
 * Signalé par Serge (2026-10-25, console de production) : connexion coupée, le tableau de
 * bord continuait de se mettre à jour en arrière-plan — chaque requête échouait, était
 * retentée, échouait encore, et les blocs affichaient « Erreur de mise à jour ».
 *
 * La règle : pas de mise à jour quand le navigateur se sait HORS LIGNE, ni quand la page est
 * CACHÉE (fenêtre réduite, autre onglet du navigateur) ; une mise à jour sautée se RATTRAPE,
 * une fois, au retour du réseau ou de la page.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pauseApresEchecs, rafraichissementPermis } from '../../assets/controllers/rafraichissement-regles.js';

test('en ligne et page visible : la mise à jour part', () => {
    assert.equal(rafraichissementPermis({ enLigne: true, visible: true }), true);
});

test('hors ligne, ou page cachée : la mise à jour attend', () => {
    assert.equal(rafraichissementPermis({ enLigne: false, visible: true }), false);
    assert.equal(rafraichissementPermis({ enLigne: true, visible: false }), false);
    assert.equal(rafraichissementPermis({ enLigne: false, visible: false }), false);
});

test('un état inconnu ne bloque rien (navigateur ancien)', () => {
    assert.equal(rafraichissementPermis({}), true);
});

test('garde-fou : chaque minuterie du tableau de bord passe par la règle', () => {
    const app = readFileSync(new URL('../../assets/app.js', import.meta.url), 'utf8');
    const minuteries = app.match(/setInterval\(/g) || [];
    // Une fonction NOMMÉE du module (toujours la même) : l'ensemble des retards reste borné.
    const parLaRegle = app.match(/setInterval\(aChaqueIntervalle\(refresh\w+\)/g) || [];
    assert.ok(minuteries.length >= 10, `au moins dix minuteries (${minuteries.length})`);
    assert.equal(parLaRegle.length, minuteries.length, 'une minuterie hors de la règle relancerait des requêtes hors ligne');
    const bloc = readFileSync(new URL('../../assets/controllers/lazy_block_controller.js', import.meta.url), 'utf8');
    assert.match(bloc, /rafraichissementPermis\(etatDuPoste\(\)\)/, 'les blocs paresseux aussi');
    for (const autre of ['document-comptable_controller.js', 'token-balance_controller.js', 'dashboard-list_controller.js']) {
        const source = readFileSync(new URL(`../../assets/controllers/${autre}`, import.meta.url), 'utf8');
        assert.match(source, /rafraichissementPermis\(etatDuPoste\(\)\)/, `${autre} : sa minuterie suit la règle`);
    }
});

test('Wi-Fi connecté mais Internet muet : après une coupure confirmée, une pause qui double', () => {
    assert.equal(pauseApresEchecs(0), 0);
    assert.equal(pauseApresEchecs(1), 60000);
    assert.equal(pauseApresEchecs(2), 120000);
    assert.equal(pauseApresEchecs(3), 240000);
    assert.equal(pauseApresEchecs(9), 600000, 'dix minutes au plus');
});

test('pendant la pause, la mise à jour attend', () => {
    assert.equal(rafraichissementPermis({ enLigne: true, visible: true, enPause: true }), false);
    assert.equal(rafraichissementPermis({ enLigne: true, visible: true, enPause: false }), true);
});
