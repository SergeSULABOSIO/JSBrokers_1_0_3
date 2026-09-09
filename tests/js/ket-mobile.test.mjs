/**
 * Tests fonctionnels du cœur PUR de la coquille mobile « mode Ket »
 * (assets/controllers/ket-mobile-feuille.js) — aucun DOM.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    CHAT,
    FEUILLE,
    surfaceSuivante,
    indexDuProchainFocus,
} from '../../assets/controllers/ket-mobile-feuille.js';

/* ───────────────────── Quelle surface est visible ───────────────────── */

test('la feuille s\'ouvre depuis le chat', () => {
    assert.equal(surfaceSuivante(CHAT, 'ouvrir-feuille'), FEUILLE);
});

test('la croix et Échap referment la feuille de la même façon', () => {
    // Deux gestes, un seul effet : c'est ce qui rend la fermeture prévisible.
    assert.equal(surfaceSuivante(FEUILLE, 'fermer-feuille'), CHAT);
    assert.equal(surfaceSuivante(FEUILLE, 'echap'), CHAT);
});

test('ouvrir une conversation referme la feuille', () => {
    // LE piège de cette machine à états. Sans cette transition, le chat
    // s'installe DERRIÈRE le panneau qui vient de l'ouvrir : l'utilisateur voit
    // la même liste et croit que son geste n'a rien fait.
    assert.equal(surfaceSuivante(FEUILLE, 'chat-pose'), CHAT);
});

test('un chat posé alors que la feuille est déjà fermée ne change rien', () => {
    // Cas réel : le chat de la dernière conversation est posé au chargement,
    // avant tout geste.
    assert.equal(surfaceSuivante(CHAT, 'chat-pose'), CHAT);
});

test('Échap sur le chat ne fait rien', () => {
    // Échap y est déjà pris par les boîtes de dialogue que Ket ouvre : cette
    // machine n'a pas à s'en mêler.
    assert.equal(surfaceSuivante(CHAT, 'echap'), CHAT);
});

test('une demande de fermeture sur le chat est sans effet', () => {
    assert.equal(surfaceSuivante(CHAT, 'fermer-feuille'), CHAT);
});

test('un événement inconnu laisse la surface intacte', () => {
    // Aucun geste ne doit pouvoir faire disparaître les deux surfaces à la fois.
    assert.equal(surfaceSuivante(FEUILLE, 'geste-inconnu'), FEUILLE);
    assert.equal(surfaceSuivante(CHAT, undefined), CHAT);
});

test('rouvrir une feuille déjà ouverte la laisse ouverte', () => {
    // L'événement peut arriver deux fois (double appui sur une cible tactile).
    assert.equal(surfaceSuivante(FEUILLE, 'ouvrir-feuille'), FEUILLE);
});

/* ───────────────────── Piégeage du focus (WCAG 2.4.3) ───────────────────── */

test('la tabulation avance d\'un élément', () => {
    assert.equal(indexDuProchainFocus({ nombre: 4, indexActif: 1, versArriere: false }), 2);
});

test('la tabulation boucle à la fin', () => {
    // LE premier des deux bords où ce calcul se trompe : sans la boucle, la
    // tabulation s'échappe vers la conversation qui est visuellement recouverte.
    assert.equal(indexDuProchainFocus({ nombre: 4, indexActif: 3, versArriere: false }), 0);
});

test('Maj+Tab recule, et boucle au début', () => {
    assert.equal(indexDuProchainFocus({ nombre: 4, indexActif: 2, versArriere: true }), 1);
    // Le SECOND bord, celui qu'on oublie une fois sur deux.
    assert.equal(indexDuProchainFocus({ nombre: 4, indexActif: 0, versArriere: true }), 3);
});

test('un focus hors du cycle entre par le bord d\'où l\'on vient', () => {
    // Cas réel : le focus est sur le conteneur `role="dialog"` lui-même, ou
    // resté hors de la feuille. `indexOf` renvoie alors -1.
    assert.equal(indexDuProchainFocus({ nombre: 3, indexActif: -1, versArriere: false }), 0);
    assert.equal(indexDuProchainFocus({ nombre: 3, indexActif: -1, versArriere: true }), 2);
    // Index hors bornes (liste rétrécie entre-temps : un champ de renommage
    // inline qui vient de disparaître) — même traitement, jamais de plantage.
    assert.equal(indexDuProchainFocus({ nombre: 3, indexActif: 9, versArriere: false }), 0);
});

test('une feuille sans élément focalisable rend la main au navigateur', () => {
    // `null` et non 0 : empêcher une tabulation sans rien proposer à la place
    // piégerait l'utilisateur au clavier pour de bon.
    assert.equal(indexDuProchainFocus({ nombre: 0, indexActif: -1, versArriere: false }), null);
    assert.equal(indexDuProchainFocus({ nombre: -1, indexActif: 0, versArriere: false }), null);
    assert.equal(indexDuProchainFocus({ nombre: undefined, indexActif: 0, versArriere: false }), null);
});

test('un seul élément focalisable reste sur lui-même', () => {
    assert.equal(indexDuProchainFocus({ nombre: 1, indexActif: 0, versArriere: false }), 0);
    assert.equal(indexDuProchainFocus({ nombre: 1, indexActif: 0, versArriere: true }), 0);
});
