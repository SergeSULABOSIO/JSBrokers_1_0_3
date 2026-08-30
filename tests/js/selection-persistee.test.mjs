/**
 * Tests de la PERSISTANCE DE LA SÉLECTION D'UN ONGLET
 * (assets/controllers/criteres-persistes.js) — logique pure, ni DOM ni localStorage.
 *
 * Ce qui est protégé ici : qu'un F5 ne fasse pas perdre le travail commencé. Les onglets
 * revenaient, leurs filtres aussi depuis peu — mais les lignes COCHÉES, non. On retrouvait
 * son onglet, sa recherche, ses chips, et une sélection vide, sans que rien ne le dise.
 *
 * Et la discipline qui compte : on ne garde que les IDENTIFIANTS. L'état complet d'une
 * sélection porte l'entité sérialisée et son canevas — plusieurs kilo-octets par ligne, et
 * périmés dès le rechargement. Ce qu'on veut retrouver, c'est QUELLES lignes étaient
 * cochées ; elles seront relues du serveur.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    memoriserSelection,
    selectionARestaurer,
} from '../../assets/controllers/criteres-persistes.js';

test('la sélection est rangée sur le bon onglet, et sur lui seul', () => {
    const onglets = [{ id: 'a' }, { id: 'b' }];

    const apres = memoriserSelection(onglets, 'b', [{ id: 12 }, { id: 34 }]);

    assert.deepEqual(apres[1].selection, [12, 34]);
    assert.equal(apres[0].selection, undefined, "L'onglet voisin n'est pas décoré.");
});

test('seuls les identifiants sont conservés', () => {
    // Ce que le cerveau transporte : l'entité entière, son canevas, son type. Tout cela
    // sera relu du serveur — le garder alourdirait le stockage pour une donnée périmée.
    const selection = [
        { id: 7, entity: { nom: 'Kibali', montant: 3195.16 }, entityCanvas: { parametres: {} } },
    ];

    const apres = memoriserSelection([{ id: 'a' }], 'a', selection);

    assert.deepEqual(apres[0].selection, [7]);
});

test('un onglet inconnu ne crée rien', () => {
    const onglets = [{ id: 'a' }];

    // La persistance décore ce qui existe ; elle n'invente pas d'onglet.
    assert.equal(memoriserSelection(onglets, 'inconnu', [{ id: 1 }]), onglets);
});

test('une sélection absente ou mal formée laisse la liste intacte', () => {
    const onglets = [{ id: 'a', selection: [5] }];

    for (const valeur of [undefined, null, 'trois', { id: 1 }]) {
        assert.equal(memoriserSelection(onglets, 'a', valeur), onglets);
    }
});

test('les lignes sans identifiant sont écartées', () => {
    // Une ligne dont le payload a été refusé n'a pas d'identifiant utilisable : la garder
    // ferait chercher au rechargement une ligne qu'on ne saurait pas désigner.
    const apres = memoriserSelection([{ id: 'a' }], 'a', [{ id: 3 }, {}, { id: '' }, { id: 9 }]);

    assert.deepEqual(apres[0].selection, [3, 9]);
});

test('seuls les onglets qui avaient une sélection sont à reposer', () => {
    const onglets = [
        { id: 'a', selection: [1, 2] },
        { id: 'b', selection: [] },
        { id: 'c' },
    ];

    // Un onglet dont la sélection était VIDE n'a rien à recocher : la liste s'ouvre déjà
    // sans sélection, et un geste sans effet vaut moins que pas de geste du tout.
    assert.deepEqual(selectionARestaurer(onglets), { a: [1, 2] });
});

test('une liste d’onglets absente ne fait rien planter', () => {
    for (const valeur of [undefined, null, 'rien', 42]) {
        assert.deepEqual(selectionARestaurer(valeur), {});
    }
});
