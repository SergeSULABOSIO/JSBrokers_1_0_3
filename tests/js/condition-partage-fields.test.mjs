/**
 * Tests fonctionnels du cœur PUR de la visibilité conditionnelle des « Risques ciblés »
 * (assets/controllers/condition-partage-fields_controller.js) — aucun DOM, aucun Stimulus.
 * Lancement : node --test tests/js/
 *
 * L'INCIDENT (2026-08-18). Le bloc « Risques ciblés » restait visible quel que soit le
 * critère coché. La cause tenait à un seul caractère de trop : la règle exigeait des
 * CROCHETS autour du nom (`[critereRisque]`), comme dans un formulaire imbriqué. Or
 * ConditionPartageType rend `getBlockPrefix()` à la chaîne VIDE : dans le dialogue, les
 * radios s'appellent simplement « critereRisque ».
 *
 * Le sélecteur ne matchait donc jamais — et rien ne le disait. Pas d'erreur en console,
 * pas d'exception : un formulaire parfaitement fonctionnel, avec un champ qui refusait
 * seulement de disparaître. C'est la panne la plus coûteuse à diagnostiquer, et la seule
 * chose qui l'aurait attrapée est ce test.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    PAS_DE_RISQUES_CIBLES,
    estChampCritereRisque,
    risquesCiblesVisibles,
} from '../../assets/controllers/condition-partage-champs.js';

test('le champ du dialogue est reconnu SANS crochets — la cause de l’incident', () => {
    // Le nom réellement rendu par ConditionPartageType (getBlockPrefix() === '').
    assert.equal(estChampCritereRisque('critereRisque'), true);
});

test('le champ préfixé reste reconnu — si le formulaire est un jour imbriqué', () => {
    assert.equal(estChampCritereRisque('condition_partage[critereRisque]'), true);
    assert.equal(estChampCritereRisque('piste[conditions][0][critereRisque]'), true);
});

test('aucun autre champ ne déclenche le recalcul', () => {
    assert.equal(estChampCritereRisque('critereRisqueAutreChose'), false);
    assert.equal(estChampCritereRisque('uniteMesure'), false);
    assert.equal(estChampCritereRisque('formule'), false);
    // Fail-closed sur les entrées douteuses : rien ne doit lever.
    assert.equal(estChampCritereRisque(''), false);
    assert.equal(estChampCritereRisque(undefined), false);
    assert.equal(estChampCritereRisque(null), false);
    assert.equal(estChampCritereRisque(42), false);
});

test('le bloc ne disparaît QUE sur « il n’y a pas de risques ciblés »', () => {
    // 0 = exclure les risques ciblés, 1 = ne partager QUE sur eux : dans les deux cas,
    // l'utilisateur doit pouvoir désigner lesquels.
    assert.equal(risquesCiblesVisibles('0'), true);
    assert.equal(risquesCiblesVisibles('1'), true);
    assert.equal(risquesCiblesVisibles(PAS_DE_RISQUES_CIBLES), false);
});

test('sans choix coché, on montre plutôt que de cacher', () => {
    // Fail-open sur l'AFFICHAGE : cacher un champ dont on ignore encore s'il servira
    // priverait l'utilisateur d'une saisie sans rien lui dire.
    assert.equal(risquesCiblesVisibles(null), true);
    assert.equal(risquesCiblesVisibles(undefined), true);
});

test('la valeur du critère « aucun risque ciblé » est bien celle de l’entité PHP', () => {
    // ConditionPartage::CRITERE_PAS_RISQUES_CIBLES = 2, rendu en chaîne dans le HTML.
    assert.equal(PAS_DE_RISQUES_CIBLES, '2');
});
