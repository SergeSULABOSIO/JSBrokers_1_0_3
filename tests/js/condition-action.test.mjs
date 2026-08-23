/**
 * Tests de la RÈGLE des actions conditionnelles
 * (assets/controllers/condition-action.js) — logique pure, aucun DOM.
 *
 * Ce qui est protégé ici : la barre d'outils et le menu contextuel proposent les mêmes
 * actions. Ils portaient chacun leur copie de cette règle ; une action visible d'un côté
 * et masquée de l'autre est le genre d'incohérence qu'on ne remarque qu'en la subissant.
 *
 * Et surtout la PRÉSENCE : un drapeau qui n'est pas un booléen mais un libellé
 * (« Effort commercial : Alice ») ne peut pas se tester par `value: true` — en JavaScript,
 * `'Effort commercial : Alice' == true` vaut FALSE. L'action serait restée invisible sans
 * la moindre erreur, exactement comme lorsqu'un drapeau n'est pas sérialisé.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import { conditionRemplie } from '../../assets/controllers/condition-action.js';

test('sans condition, une action est toujours applicable (non-régression)', () => {
    assert.equal(conditionRemplie({}, null), true);
    assert.equal(conditionRemplie({}, undefined), true);
});

test('l’égalité souple est préservée pour les drapeaux booléens existants', () => {
    const ligne = { hasPortefeuille: true, hasPisteDerivee: false };

    assert.equal(conditionRemplie(ligne, { field: 'hasPortefeuille', value: true }), true);
    assert.equal(conditionRemplie(ligne, { field: 'hasPortefeuille', value: false }), false);
    assert.equal(conditionRemplie(ligne, { field: 'hasPisteDerivee', value: false }), true);
});

test('« false » rendu en chaîne par le data-entity reste égal à false', () => {
    // C'est pour cela que l'égalité est souple : la sérialisation d'une ligne ne garantit
    // pas le type. Durcir en `===` masquerait des actions qui marchent aujourd'hui.
    assert.equal(conditionRemplie({ drapeau: 'false' }, { field: 'drapeau', value: false }), false);
    assert.equal(conditionRemplie({ drapeau: 0 }, { field: 'drapeau', value: false }), true);
});

test('la PRÉSENCE reconnaît un libellé, là où l’égalité à true échouerait', () => {
    const ligne = { effortCommercialAgent: 'Effort commercial : Alice' };

    assert.equal(conditionRemplie(ligne, { field: 'effortCommercialAgent', present: true }), true);
    assert.equal(conditionRemplie(ligne, { field: 'effortCommercialAgent', present: false }), false);

    // La preuve du piège : c'est bien pour cela que `present` existe.
    assert.equal(conditionRemplie(ligne, { field: 'effortCommercialAgent', value: true }), false);
});

test('null, undefined et la chaîne vide comptent pour absents', () => {
    for (const valeur of [null, undefined, '']) {
        const ligne = { effortCommercialAgent: valeur };
        assert.equal(conditionRemplie(ligne, { field: 'effortCommercialAgent', present: false }), true);
        assert.equal(conditionRemplie(ligne, { field: 'effortCommercialAgent', present: true }), false);
    }
    // Champ carrément absent du data-entity : absent lui aussi.
    assert.equal(conditionRemplie({}, { field: 'effortCommercialAgent', present: false }), true);
});

test('zéro et false sont PRÉSENTS : ils ont une valeur, elle vaut ce qu’elle vaut', () => {
    // Une valeur renseignée reste renseignée. Confondre « absent » et « faux » ferait
    // disparaître une action sur une ligne qui porte bel et bien la donnée.
    assert.equal(conditionRemplie({ n: 0 }, { field: 'n', present: true }), true);
    assert.equal(conditionRemplie({ b: false }, { field: 'b', present: true }), true);
});

test('une ligne sans data-entity ne fait pas tomber la règle', () => {
    assert.equal(conditionRemplie(null, { field: 'x', present: false }), true);
    assert.equal(conditionRemplie(undefined, { field: 'x', value: true }), false);
});
