/**
 * Tests du PILOTAGE DES PALIERS D'IMPORT
 * (assets/controllers/echange-paliers.js) — logique pure, ni DOM ni réseau.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────────
 * Un import de portefeuille avance par paliers, parce que le contrôle à blanc retient
 * plusieurs mégaoctets par ligne sans les rendre : chaque palier doit repartir d'un
 * processus neuf. C'est cette boucle qui décide quand pousser, quand se contenter de
 * regarder, et quand conclure que plus rien n'avance.
 *
 * ⚠ LA FAUTE À CRAINDRE N'EST PAS UNE ERREUR, C'EST UNE ATTENTE SANS FIN. Une boucle qui
 * sonde un import que personne ne traite ne casse rien : elle tourne, la barre reste
 * immobile, et l'utilisateur finit par redéposer son fichier — c'est-à-dire au pire
 * moment. Les deux seuils d'immobilité sont donc le cœur de ce fichier.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    MOTIF_ABANDON,
    MOTIF_IMMOBILE,
    MOTIF_SANS_WORKER,
    PALIERS_IMMOBILES_TOLERES,
    SCRUTINS_IMMOBILES_TOLERES,
    menerAuBout,
} from '../../assets/controllers/echange-paliers.js';

/** Un état de travail, tel que le serveur le rend. */
function etat(surcharges = {}) {
    return {
        idRun: 7,
        statut: 'CONTROLE',
        travaille: true,
        abandonne: false,
        curseur: 0,
        total: 6,
        pct: 0,
        async: false,
        ...surcharges,
    };
}

/** Des rouages qui ne touchent à rien : le test fournit les réponses. */
function rouages(reponses, journal = {}) {
    journal.avances = 0;
    journal.lectures = 0;
    journal.publies = [];
    journal.pauses = 0;

    const suivant = () => reponses.shift() ?? etat({ travaille: false });

    return {
        avancer: async () => { journal.avances += 1; return suivant(); },
        lire: async () => { journal.lectures += 1; return suivant(); },
        publier: (e) => { journal.publies.push(e); },
        patienter: async () => { journal.pauses += 1; },
    };
}

// ─────────────────────────────────────────────────────────────────────────────
// Le cas nominal
// ─────────────────────────────────────────────────────────────────────────────

test('un travail déjà terminé ne demande aucun palier', async () => {
    const journal = {};
    const final = await menerAuBout(
        etat({ travaille: false, statut: 'EN_ATTENTE_CONFIRMATION' }),
        rouages([], journal),
    );

    assert.equal(final.statut, 'EN_ATTENTE_CONFIRMATION');
    assert.equal(journal.avances, 0, 'Rien à pousser.');
    assert.equal(journal.publies.length, 1, "L'état initial est tout de même affiché.");
});

test('sans worker, c’est le navigateur qui pousse chaque palier', async () => {
    const journal = {};
    const final = await menerAuBout(etat(), rouages([
        etat({ curseur: 2, pct: 33.3 }),
        etat({ curseur: 4, pct: 66.7 }),
        etat({ curseur: 6, pct: 100, travaille: false, statut: 'EN_ATTENTE_CONFIRMATION' }),
    ], journal));

    assert.equal(final.statut, 'EN_ATTENTE_CONFIRMATION');
    assert.equal(journal.avances, 3);
    assert.equal(journal.lectures, 0, 'On ne sonde pas quand on pousse soi-même.');
    assert.equal(journal.pauses, 0, "Aucune attente : il n'y a personne à attendre.");
    assert.deepEqual(journal.publies.map((e) => e.curseur), [0, 2, 4, 6]);
});

test('avec un worker, on regarde sans pousser', async () => {
    const journal = {};
    const final = await menerAuBout(etat({ async: true }), rouages([
        etat({ async: true, curseur: 3 }),
        etat({ async: true, curseur: 6, travaille: false, statut: 'TERMINE' }),
    ], journal));

    assert.equal(final.statut, 'TERMINE');
    assert.equal(journal.avances, 0, '⚠ POUSSER PENDANT QU’UN WORKER TRAVAILLE, ce serait deux paliers sur les mêmes lignes.');
    assert.equal(journal.lectures, 2);
    assert.equal(journal.pauses, 2, 'On laisse au worker le temps de travailler.');
});

// ─────────────────────────────────────────────────────────────────────────────
// Ce qui doit s'arrêter, et le dire
// ─────────────────────────────────────────────────────────────────────────────

test('un palier abandonné arrête la boucle et nomme le remède', async () => {
    await assert.rejects(
        () => menerAuBout(etat({ abandonne: true }), rouages([])),
        (erreur) => {
            assert.equal(erreur.message, MOTIF_ABANDON);
            assert.match(erreur.message, /ne seront pas recréées/, 'Le message doit rassurer sur le redépôt.');

            return true;
        },
    );
});

test('sans worker, quelques paliers immobiles suffisent à conclure', async () => {
    const journal = {};
    const immobiles = Array.from({ length: 10 }, () => etat({ curseur: 2 }));

    await assert.rejects(
        () => menerAuBout(etat({ curseur: 2 }), rouages(immobiles, journal)),
        (erreur) => {
            assert.equal(erreur.message, MOTIF_IMMOBILE);

            return true;
        },
    );

    assert.equal(journal.avances, PALIERS_IMMOBILES_TOLERES, 'On n’insiste pas au-delà du seuil.');
});

test('avec un worker, on patiente bien plus longtemps avant de conclure', async () => {
    const journal = {};
    const immobiles = Array.from({ length: 100 }, () => etat({ async: true, curseur: 2 }));

    await assert.rejects(
        () => menerAuBout(etat({ async: true, curseur: 2 }), rouages(immobiles, journal)),
        (erreur) => {
            // ⚠ LE MESSAGE DÉSIGNE LA VRAIE CAUSE : un worker arrêté, pas un fichier fautif.
            assert.equal(erreur.message, MOTIF_SANS_WORKER);
            assert.match(erreur.message, /administrateur/);

            return true;
        },
    );

    assert.equal(journal.lectures, SCRUTINS_IMMOBILES_TOLERES);
    assert.ok(
        SCRUTINS_IMMOBILES_TOLERES > PALIERS_IMMOBILES_TOLERES,
        'Un sondage qui ne voit rien bouger est NORMAL en asynchrone : le palier est peut-être en cours.',
    );
});

test('un palier qui repart remet le compteur d’immobilité à zéro', async () => {
    const journal = {};

    // Deux paliers vides, un qui avance, puis la fin : le seuil ne doit jamais être
    // atteint, sans quoi un import simplement lent serait déclaré bloqué.
    const final = await menerAuBout(etat(), rouages([
        etat({ curseur: 0 }),
        etat({ curseur: 0 }),
        etat({ curseur: 2 }),
        etat({ curseur: 2 }),
        etat({ curseur: 4 }),
        etat({ curseur: 6, travaille: false, statut: 'TERMINE' }),
    ], journal));

    assert.equal(final.statut, 'TERMINE');
    assert.equal(journal.avances, 6);
});

// ─────────────────────────────────────────────────────────────────────────────
// Les détails qui ne se devinent pas
// ─────────────────────────────────────────────────────────────────────────────

test('le mode est relu à CHAQUE tour, pas décidé une fois pour toutes', async () => {
    const journal = {};

    // Un worker prend le relais en cours de route : l'écran doit cesser de pousser.
    await menerAuBout(etat(), rouages([
        etat({ curseur: 2, async: true }),
        etat({ curseur: 4, async: true }),
        etat({ curseur: 6, async: true, travaille: false, statut: 'TERMINE' }),
    ], journal));

    assert.equal(journal.avances, 1, 'Un seul palier poussé, avant la bascule.');
    assert.equal(journal.lectures, 2, 'Puis on se contente de regarder.');
});

test('l’affichage n’est pas obligatoire', async () => {
    const final = await menerAuBout(etat(), {
        avancer: async () => etat({ curseur: 6, travaille: false, statut: 'TERMINE' }),
        lire: async () => etat({ travaille: false }),
    });

    assert.equal(final.statut, 'TERMINE');
});
