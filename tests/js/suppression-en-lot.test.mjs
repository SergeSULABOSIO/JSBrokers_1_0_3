/**
 * Tests de la SUPPRESSION EN LOT
 * (assets/controllers/suppression-en-lot.js) — logique pure, ni DOM ni réseau.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────────
 * Les suppressions partaient en parallèle et l'attente s'arrêtait au PREMIER rejet. Sur
 * dix éléments dont trois refusaient de partir, l'utilisateur voyait un seul motif, les
 * sept autres suppressions partaient quand même, et la liste ne se rafraîchissait pas.
 *
 * ⚠ LA FAUTE À CRAINDRE N'EST PAS UNE ERREUR TECHNIQUE, C'EST UN SILENCE. Un refus non
 * dit se lit comme une panne : l'utilisateur reclique, obtient le même constat, et
 * conclut que l'application est cassée. Chaque échec doit donc être NOMMÉ, et la barre ne
 * doit jamais reculer.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { supprimerEnLot, verdictDuLot } from '../../assets/controllers/suppression-en-lot.js';

/** Un serveur simulé : `refus` nomme les identifiants qui doivent échouer. */
function serveur({ refus = {}, conservations = {} } = {}) {
    const vues = [];

    return {
        vues,
        supprimer: async (id, surProgres) => {
            vues.push(id);
            surProgres(50, 'Suppression : Propositions');
            if (refus[id]) {
                // Le serveur diffuse : le refus voyage dans la dernière ligne, pas en
                // code HTTP — la réponse a déjà envoyé son 200.
                return { ok: false, statut: 409, message: refus[id] };
            }

            return { ok: true, rapport: { conservations: conservations[id] ?? [] } };
        },
    };
}

test('les trois requêtes partent, même si la deuxième échoue', async () => {
    const s = serveur({ refus: { 2: 'une facture s\'y rattache encore.' } });

    const issue = await supprimerEnLot({
        ids: [1, 2, 3],
        descriptions: ['Affaire A', 'Affaire B', 'Affaire C'],
        supprimer: s.supprimer,
    });

    assert.deepEqual(s.vues, [1, 2, 3], 'Un échec n\'interrompt pas le lot.');
    assert.equal(issue.reussites, 2);
    assert.equal(issue.echecs.length, 1);
});

test('chaque refus est NOMMÉ, avec le motif du serveur', async () => {
    const s = serveur({ refus: { 2: 'une facture s\'y rattache encore.' } });

    const issue = await supprimerEnLot({
        ids: [1, 2, 3],
        descriptions: ['Affaire A', 'Renouvellement — POL-2026-14', 'Affaire C'],
        supprimer: s.supprimer,
    });

    assert.equal(issue.echecs[0], 'Renouvellement — POL-2026-14 : une facture s\'y rattache encore.');
});

test('sans libellé, le refus nomme au moins l\'identifiant', async () => {
    const s = serveur({ refus: { 117: 'cet élément est encore utilisé.' } });

    const issue = await supprimerEnLot({ ids: [117], supprimer: s.supprimer });

    assert.match(issue.echecs[0], /^Élément #117 : /);
});

test('la progression ne recule jamais et finit à 100', async () => {
    const s = serveur();
    const pourcentages = [];

    await supprimerEnLot({
        ids: [1, 2, 3],
        supprimer: s.supprimer,
        publier: (pct) => pourcentages.push(pct),
    });

    for (let i = 1; i < pourcentages.length; i += 1) {
        assert.ok(
            pourcentages[i] >= pourcentages[i - 1],
            `La barre a reculé : ${pourcentages[i - 1]} puis ${pourcentages[i]}.`,
        );
    }
    assert.equal(pourcentages.at(-1), 100);
    assert.ok(pourcentages.length > 3, 'L\'avancement DANS un élément est publié, pas seulement entre deux.');
});

test('l\'avancement d\'un élément est ramené à sa part du lot', async () => {
    const s = serveur();
    const etapes = [];

    await supprimerEnLot({
        ids: [1, 2],
        descriptions: ['Affaire A', 'Affaire B'],
        supprimer: s.supprimer,
        publier: (pct, libelle) => etapes.push({ pct, libelle }),
    });

    // 50 % du PREMIER élément d'un lot de deux, c'est 25 % du lot — jamais 50.
    const milieuDuPremier = etapes.find((e) => e.libelle?.startsWith('Affaire A —'));
    assert.equal(milieuDuPremier.pct, 25);
    assert.match(milieuDuPremier.libelle, /Suppression : Propositions/);
});

test('ce que le serveur conserve remonte jusqu\'au message final', async () => {
    const s = serveur({ conservations: { 1: ['Note ND-2026-014 conservée : 2 de ses 5 lignes se rattachent encore à d\'autres affaires.'] } });

    const issue = await supprimerEnLot({ ids: [1], supprimer: s.supprimer });
    const verdict = verdictDuLot(issue);

    assert.ok(verdict.succes);
    assert.match(verdict.message, /ND-2026-014 conservée/);
});

test('un succès partiel garde la boîte ouverte et liste les refus', () => {
    const verdict = verdictDuLot({
        reussites: 2,
        echecs: ['Affaire B : une facture s\'y rattache.', 'Affaire C : un règlement s\'y rattache.'],
        conservations: [],
    });

    assert.equal(verdict.succes, false);
    assert.equal(verdict.fermer, true, 'Le bouton ne doit plus inviter à recommencer un geste déjà à moitié fait.');
    assert.equal(verdict.motifs.length, 2, 'Les DEUX motifs sont affichés, pas seulement le premier.');
    assert.match(verdict.message, /2 élément\(s\) supprimé\(s\), 2 refusé\(s\)/);
});

test('un refus unique se lit en une phrase, sans liste d\'un seul élément', () => {
    const verdict = verdictDuLot({ reussites: 0, echecs: ['Affaire A : une facture s\'y rattache.'], conservations: [] });

    assert.equal(verdict.message, 'Affaire A : une facture s\'y rattache.');
    assert.deepEqual(verdict.motifs, []);
});

test('un lot vide ne déclenche aucune requête', async () => {
    const s = serveur();

    const issue = await supprimerEnLot({ ids: [], supprimer: s.supprimer });

    assert.deepEqual(s.vues, []);
    assert.equal(issue.reussites, 0);
});

test('une panne réseau devient un motif nommé, pas un lot interrompu', async () => {
    const vues = [];
    const issue = await supprimerEnLot({
        ids: [1, 2],
        descriptions: ['Affaire A', 'Affaire B'],
        supprimer: async (id) => {
            vues.push(id);
            if (id === 1) throw new Error('Le serveur a répondu 500.');

            return { ok: true };
        },
    });

    assert.deepEqual(vues, [1, 2], 'La panne du premier ne doit pas priver le second de sa chance.');
    assert.equal(issue.echecs[0], 'Affaire A : Le serveur a répondu 500.');
    assert.equal(issue.reussites, 1);
});
