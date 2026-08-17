/**
 * Tests fonctionnels du cœur PUR du repli de la colonne 2 du workspace
 * (assets/controllers/workspace-col2.js) — aucun DOM, aucun localStorage.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    FERME,
    SURVOL,
    EPINGLE,
    MARGE_VIEWPORT,
    sommetDuFlyout,
    etatSuivant,
    estOuvert,
    ancreDeReposChange,
    rubriqueAMarquer,
} from '../../assets/controllers/workspace-col2.js';

/* ─────────────────────────── Géométrie ─────────────────────────── */

test('sommetDuFlyout aligne le panneau sur son ancre quand il y a la place', () => {
    // Icône à 300 px du haut, panneau de 200 px, fenêtre de 900 px : rien ne déborde.
    assert.equal(
        sommetDuFlyout({ ancreTop: 300, hauteurPanneau: 200, hauteurViewport: 900 }),
        300
    );
});

test('sommetDuFlyout écrête en haut', () => {
    // Une icône collée au bord haut ne doit pas coller le panneau au bord.
    assert.equal(
        sommetDuFlyout({ ancreTop: 4, hauteurPanneau: 200, hauteurViewport: 900 }),
        MARGE_VIEWPORT
    );
    assert.equal(
        sommetDuFlyout({ ancreTop: -50, hauteurPanneau: 200, hauteurViewport: 900 }),
        MARGE_VIEWPORT
    );
});

test('sommetDuFlyout écrête en bas', () => {
    // Groupe survolé en bas de la colonne 1 : le panneau remonte pour tenir.
    // 700 - 400 - 8 = 292.
    assert.equal(
        sommetDuFlyout({ ancreTop: 600, hauteurPanneau: 400, hauteurViewport: 700 }),
        292
    );
});

test('sommetDuFlyout colle au bord HAUT si le panneau est plus grand que la fenêtre', () => {
    // Cas dégénéré : l'écrêtage bas donnerait une valeur négative. Mieux vaut voir
    // le début de la liste (le reste s'atteint au défilement interne) que la fin.
    assert.equal(
        sommetDuFlyout({ ancreTop: 300, hauteurPanneau: 1200, hauteurViewport: 700 }),
        MARGE_VIEWPORT
    );
});

test('sommetDuFlyout accepte une marge explicite', () => {
    assert.equal(
        sommetDuFlyout({ ancreTop: 0, hauteurPanneau: 100, hauteurViewport: 900, marge: 24 }),
        24
    );
});

/* ─────────────────────── Machine à états ───────────────────────── */

const ferme = { etat: FERME, groupe: null };

test('le survol ouvre un panneau TRANSITOIRE', () => {
    const apres = etatSuivant(ferme, { type: 'survol', groupe: 'Sinistre' });
    assert.deepEqual(apres, { etat: SURVOL, groupe: 'Sinistre' });
    assert.equal(estOuvert(apres), true);
});

test('quitter referme un panneau ouvert au survol', () => {
    const survol = etatSuivant(ferme, { type: 'survol', groupe: 'Sinistre' });
    assert.deepEqual(etatSuivant(survol, { type: 'quitte' }), ferme);
});

test('le clic sur un groupe ÉPINGLE le panneau', () => {
    const apres = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(apres, { etat: EPINGLE, groupe: 'Production' });
    assert.equal(estOuvert(apres), true);
});

test('un panneau épinglé survit au départ du pointeur — c\'est toute sa raison d\'être', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(etatSuivant(epingle, { type: 'quitte' }), epingle);
});

test('re-cliquer le MÊME groupe referme', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(etatSuivant(epingle, { type: 'clicGroupe', groupe: 'Production' }), ferme);
});

test('cliquer un AUTRE groupe ré-épingle sur lui', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(
        etatSuivant(epingle, { type: 'clicGroupe', groupe: 'Sinistre' }),
        { etat: EPINGLE, groupe: 'Sinistre' }
    );
});

test('survoler un autre groupe ne déclasse pas un panneau épinglé', () => {
    // La colonne 2 montre bien la description du groupe survolé, mais le panneau
    // reste épinglé sur le sien : le mouseleave doit le lui rendre.
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(etatSuivant(epingle, { type: 'survol', groupe: 'Sinistre' }), epingle);
});

test('les trois fermetures explicites rangent le panneau épinglé', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    for (const type of ['clicRubrique', 'echap', 'exterieur']) {
        assert.deepEqual(etatSuivant(epingle, { type }), ferme, `action « ${type} »`);
    }
});

test('déplier la colonne range le panneau', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(etatSuivant(epingle, { type: 'deplie' }), ferme);
});

test('une action inconnue laisse l\'état intact', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    assert.deepEqual(etatSuivant(epingle, { type: 'eternuement' }), epingle);
    assert.deepEqual(etatSuivant(epingle, {}), epingle);
});

test('etatSuivant tolère un état de départ absent', () => {
    assert.deepEqual(etatSuivant(undefined, { type: 'quitte' }), ferme);
    assert.deepEqual(
        etatSuivant(null, { type: 'clicGroupe', groupe: 'IA' }),
        { etat: EPINGLE, groupe: 'IA' }
    );
});

/* ─────────────────────── Ancre de repos ────────────────────────── */

test('un survol simple pose son ancre de repos', () => {
    const action = { type: 'survol', groupe: 'Sinistre' };
    assert.equal(ancreDeReposChange(etatSuivant(ferme, action), action), true);
});

test('le clic qui épingle pose son ancre de repos', () => {
    const action = { type: 'clicGroupe', groupe: 'Production' };
    assert.equal(ancreDeReposChange(etatSuivant(ferme, action), action), true);
});

test('survoler un AUTRE groupe ne déplace pas l\'ancre de repos du panneau épinglé', () => {
    // Le nœud du problème : le panneau se montre bien à hauteur du groupe survolé,
    // mais il doit revenir se poser sur son groupe ÉPINGLÉ quand le pointeur s'en va.
    // Sinon la longue liste de « Production » réapparaît à hauteur de « Sinistre »,
    // et déborde sous le bas de la fenêtre.
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    const survolAilleurs = { type: 'survol', groupe: 'Sinistre' };

    assert.equal(ancreDeReposChange(etatSuivant(epingle, survolAilleurs), survolAilleurs), false);
});

test('re-survoler le groupe épinglé lui-même laisse l\'ancre où elle est', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    const survolMeme = { type: 'survol', groupe: 'Production' };

    // Même résultat, pour une raison différente : l'ancre y est déjà.
    assert.equal(ancreDeReposChange(etatSuivant(epingle, survolMeme), survolMeme), true);
});

test('cliquer un autre groupe déplace bien l\'ancre de repos sur lui', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    const clicAilleurs = { type: 'clicGroupe', groupe: 'Sinistre' };

    assert.equal(ancreDeReposChange(etatSuivant(epingle, clicAilleurs), clicAilleurs), true);
});

test('un panneau fermé n\'a aucune ancre de repos', () => {
    const epingle = etatSuivant(ferme, { type: 'clicGroupe', groupe: 'Production' });
    for (const type of ['clicRubrique', 'echap', 'exterieur', 'deplie', 'quitte']) {
        assert.equal(ancreDeReposChange(etatSuivant(epingle, { type }), { type }), false, `action « ${type} »`);
    }
});

/* ────────────────── Rappel de la rubrique ouverte ──────────────── */

const ongletPortefeuilles = {
    componentName: '_view_manager_production.html.twig',
    entityName: 'Portefeuille',
    groupName: 'Production',
};

test('la rubrique ouverte est marquée quand on rouvre la liste de son groupe', () => {
    // Le cas rapporté : l'onglet « Portefeuilles » est actif, l'utilisateur clique le
    // groupe « Production » ; la liste qui s'affiche doit rappeler « Portefeuilles ».
    assert.deepEqual(
        rubriqueAMarquer(ongletPortefeuilles, 'Production'),
        { componentName: '_view_manager_production.html.twig', entityName: 'Portefeuille' }
    );
});

test('la liste d\'un AUTRE groupe ne marque rien', () => {
    // Sinon on désignerait une rubrique absente de la liste affichée — ou une homonyme.
    assert.equal(rubriqueAMarquer(ongletPortefeuilles, 'Sinistre'), null);
});

test('sans onglet actif, rien n\'est marqué', () => {
    assert.equal(rubriqueAMarquer(null, 'Production'), null);
    assert.equal(rubriqueAMarquer(undefined, 'Production'), null);
});

test('un onglet injecté à la volée ne désigne aucune rubrique', () => {
    // Aperçu de note, panneau HTML du chat : ni composant, ni groupe.
    assert.equal(rubriqueAMarquer({ title: 'Aperçu', tabKey: 'note-12' }, 'Production'), null);
    assert.equal(rubriqueAMarquer({ title: 'Aperçu', groupName: 'Production' }, 'Production'), null);
});

test('une rubrique sans entité reste marquable', () => {
    // `entity_name` est facultatif dans le menu : le gabarit rend alors un attribut
    // vide, que le rapprochement doit viser tel quel plutôt que de renoncer.
    assert.deepEqual(
        rubriqueAMarquer({ componentName: '_view_x.html.twig', groupName: 'IA' }, 'IA'),
        { componentName: '_view_x.html.twig', entityName: '' }
    );
});

test('un onglet hors groupe ne se confond pas avec un groupe vide', () => {
    const horsGroupe = { componentName: '_dashboard.html.twig', entityName: '', groupName: '' };
    assert.equal(rubriqueAMarquer(horsGroupe, 'Production'), null);
});

test('estOuvert accepte l\'état nu comme l\'objet', () => {
    assert.equal(estOuvert(FERME), false);
    assert.equal(estOuvert(SURVOL), true);
    assert.equal(estOuvert(EPINGLE), true);
    assert.equal(estOuvert(ferme), false);
    assert.equal(estOuvert(undefined), false);
});
