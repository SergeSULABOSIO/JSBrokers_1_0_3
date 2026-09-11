/**
 * Tests de l'ANNONCE DE LA PORTÉE D'UNE SUPPRESSION
 * (assets/controllers/apercu-suppression.js) — logique pure, ni DOM ni réseau.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────────
 * La boîte annonçait « Vous êtes sur le point de supprimer définitivement 1 élément(s) »
 * alors qu'effacer une opportunité emporte ses propositions, ses polices, ses échéances,
 * ses commissions, sa facture et son règlement. On ne peut pas demander à quelqu'un de
 * valider ce qu'on lui cache.
 *
 * ⚠ LA FAUTE À CRAINDRE EST UNE ANNONCE FAUSSE, pas une absence d'annonce. Une portée
 * sous-estimée fait valider une destruction qu'on n'aurait pas acceptée ; un aperçu
 * indisponible, lui, ne coûte qu'une confirmation moins informée.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    APERCUS_MAX,
    annoncerLaPortee,
    resumerLaPortee,
    rubriqueDeSuppression,
} from '../../assets/controllers/apercu-suppression.js';

test('la rubrique se lit dans l\'URL de suppression du canevas', () => {
    assert.equal(rubriqueDeSuppression('/admin/piste/api/delete'), 'piste');
    assert.equal(rubriqueDeSuppression('/admin/chargementpourprime/api/delete'), 'chargementpourprime');
    assert.equal(rubriqueDeSuppression('/autre/chose'), null);
    assert.equal(rubriqueDeSuppression(undefined), null);
});

test('les portées se CUMULENT par nature', () => {
    const resume = resumerLaPortee([
        { portee: [{ entite: 'Cotation', libelle: 'Propositions', count: 2 }] },
        { portee: [{ entite: 'Cotation', libelle: 'Propositions', count: 4 }] },
    ]);

    assert.deepEqual(resume.lignes, ['6 Propositions seront supprimés avec.']);
    assert.equal(resume.bloquant, false);
});

test('ce qui est conservé est annoncé, et une seule fois', () => {
    const garde = 'Note ND-2026-014 conservée : 2 de ses 5 lignes se rattachent encore à d\'autres affaires.';
    const resume = resumerLaPortee([
        { portee: [], conservations: [garde] },
        { portee: [], conservations: [garde] },
    ]);

    assert.deepEqual(resume.lignes, [garde], 'Le même motif ne se répète pas.');
});

test('un refus connu d\'avance rend le résumé bloquant', () => {
    const resume = resumerLaPortee([{ refus: ['3 Dépenses en dépendent.'] }]);

    assert.equal(resume.bloquant, true);
    assert.deepEqual(resume.lignes, ['3 Dépenses en dépendent.']);
});

test('une nature à zéro n\'est pas annoncée', () => {
    const resume = resumerLaPortee([{ portee: [{ entite: 'Avenant', libelle: 'Avenants', count: 0 }] }]);

    assert.deepEqual(resume.lignes, []);
});

test('un aperçu indisponible ne casse rien et ne dit rien', async () => {
    const resume = await annoncerLaPortee({
        url: '/admin/piste/api/delete',
        ids: [1, 2],
        lire: async (adresse) => {
            if (adresse.endsWith('/1')) throw new Error('503');

            return { portee: [{ entite: 'Note', libelle: 'Notes', count: 1 }] };
        },
    });

    assert.deepEqual(resume.lignes, ['1 Notes seront supprimés avec.']);
});

test('au-delà du plafond, on renonce à l\'annonce plutôt que de faire attendre', async () => {
    let appels = 0;
    const resume = await annoncerLaPortee({
        url: '/admin/piste/api/delete',
        ids: Array.from({ length: APERCUS_MAX + 1 }, (_, i) => i + 1),
        lire: async () => { appels += 1; return {}; },
    });

    assert.equal(appels, 0, 'Aucune requête : la boîte s\'ouvre sans attendre.');
    assert.deepEqual(resume.lignes, []);
});

test('une URL qui n\'est pas une suppression ne déclenche aucune requête', async () => {
    let appels = 0;
    await annoncerLaPortee({ url: null, ids: [1], lire: async () => { appels += 1; return {}; } });

    assert.equal(appels, 0);
});
