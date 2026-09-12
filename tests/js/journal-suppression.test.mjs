/**
 * LE JOURNAL D'UNE SUPPRESSION.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────
 * Un échec n'interrompt pas la file : les lots suivants partent quand même. L'utilisateur
 * doit donc pouvoir, une fois la boîte refermée, expliquer à un tiers ce qui est parti et
 * pourquoi le reste est resté. C'est ce journal qui porte cette preuve.
 *
 * ⚠ LA FAUTE À CRAINDRE EST UN BILAN QUI ARRONDIT : « tout a été supprimé » alors que deux
 * lots ont résisté, ou trois motifs noyés dans un compteur. Et « 1 éléments », qui suffit
 * à faire douter de tout le reste du message.
 *
 * Lancement : node --test tests/js/
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    creerJournal, ouvrirLot, terminerLot, echouerLot, lignesVisibles, bilan, phraseDuBilan, lotsARejouer,
} from '../../assets/controllers/journal-suppression.js';

test('un lot échoué laisse la file ouverte et GARDE son motif', () => {
    const j = creerJournal();
    ouvrirLot(j, 'Cotation#10', 'SUNU');
    terminerLot(j, 'Cotation#10', { detruits: 12, detaches: 1 });
    ouvrirLot(j, 'Cotation#11', 'AXA');
    echouerLot(j, 'Cotation#11', 'AXA', '3 Dépenses en dépendent.');
    ouvrirLot(j, 'Cotation#12', 'SANLAM');
    terminerLot(j, 'Cotation#12', { detruits: 4 });

    const b = bilan(j);
    assert.equal(b.detruits, 16, 'Les lots qui ont abouti comptent, malgré l’échec du deuxième.');
    assert.equal(b.echecs.length, 1);
    assert.equal(b.echecs[0].motif, '3 Dépenses en dépendent.', 'Le motif du serveur est conservé mot pour mot.');
    assert.equal(b.succes, false);
});

test('un lot en échec ne rentre jamais dans le compte des objets supprimés', () => {
    const j = creerJournal();
    ouvrirLot(j, 'Cotation#11', 'AXA');
    echouerLot(j, 'Cotation#11', 'AXA', 'Refusé.');

    assert.equal(bilan(j).detruits, 0);
});

test('les échecs passent DEVANT dans la liste : on ne les tronque pas', () => {
    const j = creerJournal();
    for (let i = 0; i < 260; i += 1) {
        ouvrirLot(j, `Cotation#${i}`, `Proposition ${i}`);
        terminerLot(j, `Cotation#${i}`, { detruits: 1 });
    }
    ouvrirLot(j, 'Cotation#999', 'Celle qui résiste');
    echouerLot(j, 'Cotation#999', 'Celle qui résiste', 'Une facture s’y rattache.');

    const { lignes, caches } = lignesVisibles(j);
    assert.equal(lignes.length, 200, 'Le plafond tient : un aria-live qui reçoit mille lignes est inutilisable.');
    assert.equal(lignes[0].cle, 'Cotation#999', 'L’échec est en tête, jamais noyé par la troncature.');
    assert.ok(caches > 0, 'Et on DIT combien de lignes ne sont pas montrées.');
});

test('le bilan ne dit jamais « 1 éléments »', () => {
    const un = creerJournal();
    ouvrirLot(un, 'Piste#1', 'Affaire');
    terminerLot(un, 'Piste#1', { detruits: 1, detaches: 1 });
    assert.equal(phraseDuBilan(un), '1 objet supprimé · 1 lien coupé.');

    const plusieurs = creerJournal();
    ouvrirLot(plusieurs, 'Piste#2', 'Affaire');
    terminerLot(plusieurs, 'Piste#2', { detruits: 12, detaches: 3 });
    assert.equal(phraseDuBilan(plusieurs), '12 objets supprimés · 3 liens coupés.');
});

test('le bilan annonce les parties qui ont résisté, sans les fondre dans le total', () => {
    const j = creerJournal();
    ouvrirLot(j, 'Cotation#10', 'SUNU');
    terminerLot(j, 'Cotation#10', { detruits: 5 });
    ouvrirLot(j, 'Cotation#11', 'AXA');
    echouerLot(j, 'Cotation#11', 'AXA', 'Refusé.');

    assert.equal(phraseDuBilan(j), '5 objets supprimés · 1 partie a résisté.');
});

test('la reprise groupée ne rejoue QUE ce qui a échoué', () => {
    const j = creerJournal();
    ouvrirLot(j, 'Cotation#10', 'SUNU');
    terminerLot(j, 'Cotation#10', { detruits: 5 });
    ouvrirLot(j, 'Cotation#11', 'AXA');
    echouerLot(j, 'Cotation#11', 'AXA', 'Refusé.');

    assert.deepEqual(lotsARejouer(j), ['Cotation#11'], 'Rejouer un lot réussi le détruirait deux fois — ou ne ferait rien, sans le dire.');
});

test('un motif vide reçoit quand même une phrase : une ligne muette n’explique rien', () => {
    const j = creerJournal();
    ouvrirLot(j, 'Cotation#11', 'AXA');
    echouerLot(j, 'Cotation#11', 'AXA', '   ');

    assert.ok(bilan(j).echecs[0].motif.length > 10);
});
