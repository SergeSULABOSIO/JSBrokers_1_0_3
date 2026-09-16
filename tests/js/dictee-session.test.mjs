/**
 * Tests du cœur PUR d'une session de dictée de Ket
 * (assets/controllers/dictee-session.js) — aucun micro, aucun DOM.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    DICTEE_DUREE_MAX_MS,
    FINS_ECLAIR_MAX,
    assemblerTexte,
    decisionApresFin,
    formaterDuree,
    remplacerSegment,
    segmentDicte,
} from '../../assets/controllers/dictee-session.js';

test('un silence ne clôt pas une dictée voulue : la reconnaissance est relancée', () => {
    const d = decisionApresFin({ voulue: true, dureeTotaleMs: 8000, dureeSessionMs: 7000, finsEclair: 0 });
    assert.deepEqual(d, { relancer: true, raison: null, finsEclair: 0 });
});

test("l'arrêt demandé par l'utilisateur n'est jamais relancé", () => {
    const d = decisionApresFin({ voulue: false, dureeTotaleMs: 5000, dureeSessionMs: 5000, finsEclair: 0 });
    assert.equal(d.relancer, false);
    assert.equal(d.raison, 'utilisateur');
});

test('le plafond de trois minutes arrête la dictée', () => {
    assert.equal(DICTEE_DUREE_MAX_MS, 180000);
    const d = decisionApresFin({ voulue: true, dureeTotaleMs: DICTEE_DUREE_MAX_MS, dureeSessionMs: 9000, finsEclair: 0 });
    assert.deepEqual([d.relancer, d.raison], [false, 'plafond']);
});

test('des fins éclair répétées arrêtent la boucle, une session normale remet le compte à zéro', () => {
    let finsEclair = 0;
    let d;
    for (let i = 0; i < FINS_ECLAIR_MAX; i++) {
        d = decisionApresFin({ voulue: true, dureeTotaleMs: 2000 + i, dureeSessionMs: 100, finsEclair });
        finsEclair = d.finsEclair;
    }
    assert.deepEqual([d.relancer, d.raison], [false, 'boucle']);

    const normale = decisionApresFin({ voulue: true, dureeTotaleMs: 9000, dureeSessionMs: 4000, finsEclair: 2 });
    assert.deepEqual([normale.relancer, normale.finsEclair], [true, 0]);
});

test('le compteur s’affiche en m:ss', () => {
    assert.equal(formaterDuree(0), '0:00');
    assert.equal(formaterDuree(65400), '1:05');
    assert.equal(formaterDuree(180000), '3:00');
});

test('une relance repart du texte déjà dicté au lieu de l’écraser', () => {
    // 1re session : « bonjour Ket » ; le navigateur coupe ; le socle devient le texte courant.
    const apresSession1 = assemblerTexte('Note :', 'bonjour Ket', 4000);
    const apresSession2 = assemblerTexte(apresSession1, 'liste mes clients', 4000);
    assert.equal(apresSession2, 'Note : bonjour Ket liste mes clients');
    assert.equal(assemblerTexte('', 'abc', 2), 'ab', 'la limite du champ est respectée');
});

test('la finition ne remplace que le segment dicté, jamais le texte tapé avant', () => {
    const socle = 'Pour le client Kibali';
    const valeur = 'Pour le client Kibali euh quels quels risques on peut lui proposer';
    assert.equal(segmentDicte(valeur, socle), 'euh quels quels risques on peut lui proposer');
    assert.equal(
        remplacerSegment(socle, 'Quels risques peut-on lui proposer ?', 4000),
        'Pour le client Kibali Quels risques peut-on lui proposer ?',
    );
});

test('un socle retouché pendant la dictée désactive la finition', () => {
    assert.equal(segmentDicte('Pour un client euh', 'Pour le client'), null);
});
