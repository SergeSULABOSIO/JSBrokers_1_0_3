/**
 * Tests du MOTEUR DE VISIBILITÉ des champs de dialogue.
 * Lancement : node --test tests/js/
 *
 * Ce moteur décide si un champ paraît. Quand il se trompe, il ne lève rien et n'écrit rien
 * en console : le champ reste simplement affiché ou masqué à tort. C'est la panne la plus
 * coûteuse à diagnostiquer — celle de « Risques ciblés », dont le sélecteur exigeait des
 * crochets que le nom du champ ne portait pas, a été signalée trois fois avant d'être vue.
 *
 * Ces tests existent pour que le prochain manque se voie ici, et pas sur un écran.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    champsSources,
    conteneurVisible,
    estRenseignee,
    evaluerCondition,
    rangeeVisible,
    trouverChamp,
    valeurObservee,
} from '../../assets/controllers/visibilite-conditions.js';

/** Un lecteur de valeurs, tel que le contrôleur en fournit un au moteur. */
const lecteur = (valeurs) => (nom) => (nom in valeurs ? valeurs[nom] : undefined);

test('le OU rend vrai dès qu’UNE sous-condition passe', () => {
    const regle = {
        operator: 'any',
        conditions: [
            { field: 'partenaire', operator: 'not_empty' },
            { field: 'conditionsPartageAgent', operator: 'not_empty' },
        ],
    };

    // C'est la règle du bloc « conditions propres à l'affaire » : il paraît dès qu'il y a
    // quelqu'un à rétrocéder, intermédiaire OU agent.
    assert.equal(evaluerCondition(regle, lecteur({ partenaire: '7', conditionsPartageAgent: '' })), true);
    assert.equal(evaluerCondition(regle, lecteur({ partenaire: '', conditionsPartageAgent: '2' })), true);
    assert.equal(evaluerCondition(regle, lecteur({ partenaire: '7', conditionsPartageAgent: '2' })), true);
});

test('le OU rend faux quand AUCUNE ne passe — c’est là que le bloc disparaît', () => {
    const regle = {
        operator: 'any',
        conditions: [
            { field: 'partenaire', operator: 'not_empty' },
            { field: 'conditionsPartageAgent', operator: 'not_empty' },
        ],
    };

    assert.equal(evaluerCondition(regle, lecteur({ partenaire: '', conditionsPartageAgent: '' })), false);
    // Aucun `any` sans sous-condition ne doit passer par accident.
    assert.equal(evaluerCondition({ operator: 'any', conditions: [] }, lecteur({})), false);
});

test('un champ nommé « x[] » est trouvé sous « x » — LE cas qui échouait en silence', () => {
    // Un champ à choix multiple se rend `name="conditionsPartageAgent[]"`. Le chercher sous
    // son seul nom nu rendait `undefined`, la condition devenait fausse, et le bloc restait
    // masqué pour toujours — sans erreur, sans trace.
    const elements = { 'conditionsPartageAgent[]': { value: '4' } };

    assert.equal(trouverChamp(elements, 'conditionsPartageAgent')?.value, '4');
    // Le nom nu reste évidemment prioritaire quand il existe.
    assert.equal(trouverChamp({ partenaire: { value: '9' } }, 'partenaire')?.value, '9');
    assert.equal(trouverChamp({}, 'absent'), null);
    assert.equal(trouverChamp(null, 'absent'), null);
});

test('not_empty dit vrai sur un select multiple dont une option est choisie', () => {
    // `value` ne rend que la PREMIÈRE option retenue : dès qu'on désélectionnait la
    // première ligne, un champ pourtant rempli passait pour vide.
    const avecChoix = { multiple: true, selectedOptions: { length: 2 }, value: '' };
    const sansChoix = { multiple: true, selectedOptions: { length: 0 }, value: '' };

    assert.equal(estRenseignee(valeurObservee(avecChoix)), true);
    assert.equal(estRenseignee(valeurObservee(sansChoix)), false);
});

test('un select SIMPLE vide reste vide — son placeholder n’est pas un choix', () => {
    // LE DÉFAUT VU À L'ÉCRAN. Un select simple porte lui aussi `selectedOptions`, et son
    // option de tête (« Choisir… », valeur vide) y compte pour une option retenue. Le
    // champ « intermédiaire » d'une affaire qui n'en a aucun se lisait donc « 1 », et le
    // bloc des conditions propres restait affiché là où il n'avait aucun objet.
    const videAvecPlaceholder = { multiple: false, selectedOptions: { length: 1 }, value: '' };
    const rempli = { multiple: false, selectedOptions: { length: 1 }, value: '865' };

    assert.equal(estRenseignee(valeurObservee(videAvecPlaceholder)), false);
    assert.equal(estRenseignee(valeurObservee(rempli)), true);
    // Et « in » retrouve la VALEUR, non un décompte : sans la garde, la règle comparait
    // « 1 » à la liste des valeurs attendues.
    assert.equal(valeurObservee(rempli), '865');
});

test('un groupe de radios sans choix coché ne rend rien — et ce n’est pas une erreur', () => {
    const groupe = { estGroupeRadio: true };

    assert.equal(valeurObservee(groupe, null), null);
    assert.equal(valeurObservee(groupe, '1'), '1');
    assert.equal(evaluerCondition({ field: 'critereRisque', operator: 'in', value: [0, 1] }, () => null), false);
});

test('l’opérateur « in » compare des chaînes des deux côtés', () => {
    // Un identifiant vaut 7 côté PHP et "7" dans le DOM : sans normalisation, la règle
    // aurait été fausse pour tout le monde, tout le temps.
    const regle = { field: 'critereRisque', operator: 'in', value: [0, 1] };

    assert.equal(evaluerCondition(regle, lecteur({ critereRisque: '1' })), true);
    assert.equal(evaluerCondition(regle, lecteur({ critereRisque: '2' })), false);
});

test('un opérateur inconnu ne montre rien, et ne fait rien tomber', () => {
    // Fail-closed sur l'AFFICHAGE : un champ montré par erreur ouvre une saisie que
    // personne ne lira ; un champ masqué se remarque et se signale.
    assert.equal(evaluerCondition({ field: 'x', operator: 'inconnu' }, lecteur({ x: 'v' })), false);
    assert.equal(evaluerCondition(null, lecteur({})), false);
    assert.equal(evaluerCondition({ field: 'absent', operator: 'not_empty' }, lecteur({})), false);
});

test('sans condition, le conteneur reste visible', () => {
    // Le contrat historique : seul un champ qui DÉCLARE une règle peut disparaître.
    assert.equal(conteneurVisible([], lecteur({})), true);
    assert.equal(conteneurVisible(undefined, lecteur({})), true);
});

test('plusieurs conditions sur un même conteneur se combinent en ET', () => {
    const conditions = [
        { field: 'a', operator: 'not_empty' },
        { field: 'b', operator: 'not_empty' },
    ];

    assert.equal(conteneurVisible(conditions, lecteur({ a: '1', b: '2' })), true);
    assert.equal(conteneurVisible(conditions, lecteur({ a: '1', b: '' })), false);
});

test('une rangée masquée par le canevas le reste, même avec des colonnes visibles', () => {
    // LE CAS QUI SE VOYAIT À L'ÉCRAN ET DANS AUCUN TEST. Le serveur rendait la rangée du
    // client en `d-none` (clé `hidden` du canevas), et la seconde passe la rouvrait aussitôt
    // parce que son unique colonne, elle, n'était pas masquée. Le champ revenait sous les
    // yeux de l'utilisateur alors que le HTML, lui, disait bien le contraire.
    assert.equal(rangeeVisible({ masqueeParLeCanevas: true, colonnesVisibles: 1 }), false);
    assert.equal(rangeeVisible({ masqueeParLeCanevas: true, colonnesVisibles: 0 }), false);
});

test('une rangée ordinaire suit ses colonnes', () => {
    assert.equal(rangeeVisible({ masqueeParLeCanevas: false, colonnesVisibles: 1 }), true);
    // Toutes ses colonnes masquées : la rangée n'a plus rien à montrer, et la laisser
    // ouverte creuserait un blanc que rien n'explique.
    assert.equal(rangeeVisible({ masqueeParLeCanevas: false, colonnesVisibles: 0 }), false);
});

test('les champs sources sont retrouvés jusque dans un « any » imbriqué', () => {
    // Sans cette lecture récursive, le contrôleur ne posait AUCUN écouteur sur une règle
    // composée : le bloc restait figé dans l'état calculé à l'ouverture du formulaire.
    const conditions = [{
        operator: 'any',
        conditions: [
            { field: 'partenaire', operator: 'not_empty' },
            { field: 'conditionsPartageAgent', operator: 'not_empty' },
        ],
    }];

    assert.deepEqual(champsSources(conditions), ['partenaire', 'conditionsPartageAgent']);
    // Une règle à plat continue d'être lue, et un champ cité deux fois n'est écouté qu'une.
    assert.deepEqual(champsSources([{ field: 'critereRisque', operator: 'in', value: [1] }]), ['critereRisque']);
    assert.deepEqual(
        champsSources([{ field: 'a', operator: 'not_empty' }, { field: 'a', operator: 'not_empty' }]),
        ['a'],
    );
    assert.deepEqual(champsSources(undefined), []);
});
