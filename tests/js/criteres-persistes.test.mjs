/**
 * Tests de la PERSISTANCE DES FILTRES D'ONGLET
 * (assets/controllers/criteres-persistes.js) — logique pure, ni DOM ni localStorage.
 *
 * Ce qui est protégé ici : qu'un F5 ne fasse pas mentir l'écran. Les onglets revenaient,
 * leurs filtres non — l'utilisateur retrouvait la liste ENTIÈRE là où il avait restreint,
 * sans qu'aucun badge ni chip ne le signale.
 *
 * Et la subtilité qui compte : un jeu de critères VIDE est une information, pas une
 * absence. L'utilisateur a peut-être retiré le badge « Mon portefeuille » posé par défaut ;
 * le lui rendre au rechargement serait re-filtrer à son insu.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    critereConservable,
    criteresARestaurer,
    memoriserCriteres,
} from '../../assets/controllers/criteres-persistes.js';

const AGENT = { operator: '=', value: 42, label: 'Alice' };

test('les critères d’un onglet sont rangés sur SON entrée, et sur elle seule', () => {
    const onglets = [{ id: 'a' }, { id: 'b' }];

    const apres = memoriserCriteres(onglets, 'b', { agent: AGENT });

    assert.equal(apres[0].criteres, undefined, 'L’onglet voisin ne doit pas être touché.');
    assert.deepEqual(apres[1].criteres, { agent: AGENT });
});

test('un onglet inconnu ne crée rien', () => {
    // La persistance décore des onglets existants ; elle n'en invente pas.
    const onglets = [{ id: 'a' }];

    assert.deepEqual(memoriserCriteres(onglets, 'fantome', { agent: AGENT }), onglets);
});

test('UN JEU VIDE EST CONSERVÉ : c’est un retrait, pas une absence', () => {
    // L'utilisateur a retiré le badge posé par défaut. Ne pas l'enregistrer reviendrait à
    // le lui remettre au rechargement — re-filtrer à son insu.
    const apres = memoriserCriteres([{ id: 'a' }], 'a', {});

    assert.deepEqual(apres[0].criteres, {});
    assert.equal(critereConservable({}), true);
});

test('ce qui n’est pas un objet de critères n’est pas conservable', () => {
    assert.equal(critereConservable(undefined), false);
    assert.equal(critereConservable(null), false);
    assert.equal(critereConservable([]), false, 'Un tableau n’est pas une carte de critères.');
    assert.equal(critereConservable('agent'), false);
});

test('à la restauration, seuls les filtres NON vides sont reposés', () => {
    const onglets = [
        { id: 'a', criteres: { agent: AGENT } },
        { id: 'b', criteres: {} },
        { id: 'c' },
    ];

    const enAttente = criteresARestaurer(onglets);

    assert.deepEqual(enAttente, { a: { agent: AGENT } });
    assert.equal('b' in enAttente, false, 'Reposer un jeu vide déclencherait une recherche sans objet.');
    assert.equal('c' in enAttente, false);
});

test('PLUSIEURS onglets retrouvent CHACUN le sien', () => {
    // C'est le cœur du défaut : la restauration en rétablit plusieurs d'un coup.
    const onglets = [
        { id: 'a', criteres: { agent: AGENT } },
        { id: 'b', criteres: { __justificatif_reversement__: { operator: '=', value: 'sans_piece' } } },
    ];

    const enAttente = criteresARestaurer(onglets);

    assert.equal(Object.keys(enAttente).length, 2);
    assert.deepEqual(enAttente.a, { agent: AGENT });
    assert.equal(enAttente.b.__justificatif_reversement__.value, 'sans_piece');
});

test('une liste absente ou malformée ne fait pas tomber la restauration', () => {
    // Le stockage vient du navigateur : il peut être vide, tronqué ou d'une version
    // antérieure. Une exception ici empêcherait l'espace de travail de s'ouvrir.
    assert.deepEqual(criteresARestaurer(undefined), {});
    assert.deepEqual(criteresARestaurer(null), {});
    assert.deepEqual(criteresARestaurer([null, { id: 'a', criteres: 'nope' }]), {});
});

test('les critères mémorisés ne partagent pas la référence de l’onglet d’origine', () => {
    // Les entrées d'onglet sont sérialisées puis relues : un partage de référence
    // laisserait une mutation ultérieure réécrire ce qui a déjà été enregistré.
    const onglets = [{ id: 'a', titre: 'Rétros agents' }];
    const apres = memoriserCriteres(onglets, 'a', { agent: AGENT });

    assert.notEqual(apres[0], onglets[0]);
    assert.equal(apres[0].titre, 'Rétros agents', 'Le reste de l’entrée doit survivre.');
});
