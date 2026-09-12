/**
 * L'ÉTAT DES CASES D'UN ARBRE DE SUPPRESSION.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────
 * L'arbre propose d'épargner une ligne en la décochant. Sur quatre des cinq niveaux du
 * dossier, la base l'interdit : une échéance n'existe pas sans sa proposition. Décocher
 * une échéance ne peut donc vouloir dire qu'une chose — renoncer à supprimer ce qui la
 * porte — et l'écran doit le MONTRER, pas le découvrir à l'exécution.
 *
 * ⚠ LA FAUTE À CRAINDRE EST UNE PROMESSE QUE LE SERVEUR DÉMENTIRA : une case qui se décoche
 * sans rien remonter, et un dossier qui part quand même avec la ligne qu'on croyait
 * sauvée — ou qui échoue en bloc, sans que personne comprenne pourquoi.
 *
 * Lancement : node --test tests/js/
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    construireArbre, fusionnerProjections, etatInitialDeLArbre, basculerLeNoeud, etatDe,
    compterLesObjets, lots, conserver, chemin, racineDe,
} from '../../assets/controllers/arbre-suppression.js';

/**
 * Un dossier réaliste : une affaire, deux propositions, des échéances, une facture et son
 * règlement, plus un paquet de lignes de facture.
 */
function dossier() {
    return construireArbre({
        racine: { cle: 'Piste#1', classe: 'Piste', libelle: 'Pistes', nom: 'Affaire ACME', total: 0 },
        total: 0,
        noeuds: [
            { cle: 'Cotation#10', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'SUNU', nature: 'structurel', geste: 'detruire', total: 254 },
            { cle: 'Cotation#11', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'AXA', nature: 'structurel', geste: 'detruire', total: 2 },
            { cle: 'Tranche#20', parent: 'Cotation#10', classe: 'Tranche', libelle: 'Tranches', nom: '1re échéance', nature: 'structurel', geste: 'detruire', total: 252 },
            { cle: 'Tranche#21', parent: 'Cotation#11', classe: 'Tranche', libelle: 'Tranches', nom: '1re échéance AXA', nature: 'structurel', geste: 'detruire', total: 1 },
            { cle: 'paquet:Article@Tranche#20', parent: 'Tranche#20', classe: 'Article', libelle: 'Lignes de facture', nom: null, nature: 'structurel', geste: 'detruire', total: 247, paquet: true, elements: 247 },
            { cle: 'Document#30', parent: 'Cotation#10', classe: 'Document', libelle: 'Documents', nom: 'offre-sunu.pdf', nature: 'detachable', geste: 'detruire', total: 1 },
            { cle: 'Paiement#40', parent: 'Tranche#20', classe: 'Paiement', libelle: 'Paiements', nom: 'RGL-2026-1', nature: 'detachable', geste: 'detruire', total: 1 },
        ],
    });
}

/** Le même dossier, mais une dépense verrouille la première échéance. */
function dossierVerrouille() {
    const projection = {
        racine: { cle: 'Piste#1', classe: 'Piste', libelle: 'Pistes', nom: 'Affaire ACME', total: 0 },
        total: 0,
        noeuds: [
            { cle: 'Cotation#10', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'SUNU', nature: 'structurel', geste: 'detruire', total: 2 },
            { cle: 'Cotation#11', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'AXA', nature: 'structurel', geste: 'detruire', total: 1 },
            { cle: 'Tranche#20', parent: 'Cotation#10', classe: 'Tranche', libelle: 'Tranches', nom: '1re échéance', nature: 'structurel', geste: 'detruire', total: 1 },
            { cle: 'DepenseCourtier#50', parent: 'Tranche#20', classe: 'DepenseCourtier', libelle: 'Dépenses', nom: 'Frais AXA', nature: 'structurel', geste: 'verrouille', total: 0, verrou: '3 Dépenses en dépendent.' },
        ],
    };

    return construireArbre(projection);
}

test('le parent précède ses enfants, et un seul passage suffit à bâtir l’arbre', () => {
    const arbre = dossier();
    assert.equal(arbre.racine, 'Piste#1');
    assert.deepEqual(arbre.enfants.get('Piste#1'), ['Cotation#10', 'Cotation#11']);
    assert.deepEqual(arbre.enfants.get('Tranche#20'), ['paquet:Article@Tranche#20', 'Paiement#40']);
});

test('décocher une échéance décoche sa proposition ET l’affaire, mais laisse la proposition sœur cochée', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Tranche#20', false);

    assert.equal(etat.get('Tranche#20'), false);
    assert.equal(etat.get('Cotation#10'), false, 'La proposition ne survit pas au refus de son échéance.');
    assert.equal(etat.get('Piste#1'), false, 'L’affaire non plus.');
    assert.equal(etat.get('Cotation#11'), true, '⚠ LES FRÈRES NE BOUGENT PAS : l’autre proposition reste à supprimer.');
    assert.equal(etat.get('Tranche#21'), true);
});

test('décocher une pièce jointe ne remonte à rien : elle se garde seule', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Document#30', false);

    assert.equal(etat.get('Document#30'), false);
    assert.equal(etat.get('Cotation#10'), true, 'Épargner un document ne renonce pas à supprimer la proposition.');
    assert.equal(etat.get('Piste#1'), true);
});

test('recocher ne ressuscite jamais ce qu’on venait d’épargner', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Tranche#20', false);   // l’affaire entière est épargnée
    basculerLeNoeud(arbre, etat, 'Tranche#20', true);    // on revient sur l’échéance seule

    assert.equal(etat.get('Tranche#20'), true);
    assert.equal(
        etat.get('Piste#1'),
        false,
        '⚠ LA REMONTÉE EST UNE CONSÉQUENCE, PAS UNE INTENTION : recocher une échéance ne redemande pas la suppression de l’affaire.',
    );
});

test('le tri-état ne dit jamais « partiel » sur une branche entièrement cochée', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    assert.equal(etatDe(arbre, etat, 'Piste#1'), 'true');

    basculerLeNoeud(arbre, etat, 'Document#30', false);
    assert.equal(etatDe(arbre, etat, 'Cotation#10'), 'mixed', 'Une pièce épargnée rend la branche partielle.');
    assert.equal(etatDe(arbre, etat, 'Cotation#11'), 'true', 'La proposition intacte reste pleine.');

    basculerLeNoeud(arbre, etat, 'Cotation#11', false);
    assert.equal(etatDe(arbre, etat, 'Cotation#11'), 'false', 'Décochée avec tout son contenu : vide, pas partielle.');
});

test('les compteurs parlent en OBJETS : un paquet de 247 lignes pèse 247', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    const plein = compterLesObjets(arbre, etat);
    assert.equal(plein.conserves, 0);
    assert.ok(plein.total >= 247, `Le paquet doit peser son volume, pas une ligne (total = ${plein.total}).`);

    basculerLeNoeud(arbre, etat, 'paquet:Article@Tranche#20', false);
    const apres = compterLesObjets(arbre, etat);
    assert.equal(apres.total, plein.total, 'Le total du dossier ne bouge pas : c’est ce qui est retenu qui change.');
    assert.ok(apres.conserves >= 247, 'Les 247 lignes épargnées se comptent une par une.');
});

test('tout coché ne fait qu’UN seul lot : la racine', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    assert.deepEqual(lots(arbre, etat), ['Piste#1']);
});

test('épargner une branche fait des autres autant de lots distincts', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Tranche#20', false);

    assert.deepEqual(
        lots(arbre, etat),
        ['Cotation#11'],
        'La proposition intacte devient sa propre racine ; celle qu’on a épargnée n’est pas soumise.',
    );
});

test('une pièce restée cochée sous une branche épargnée n’est pas supprimée toute seule', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Tranche#20', false); // épargne l’affaire par remontée

    assert.equal(etat.get('Document#30'), true, 'La pièce reste cochée : la remontée ne touche que les ancêtres.');
    assert.ok(
        !lots(arbre, etat).includes('Document#30'),
        '⚠ Personne n’a demandé d’effacer CE document : il ne devait partir que comme conséquence.',
    );
});

test('une pièce décochée sous une branche qui part est CONSERVÉE, pas détruite', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Document#30', false);

    assert.deepEqual(conserver(arbre, etat), { Document: [30] });
});

test('une pièce décochée sous une branche déjà épargnée n’a rien à signaler', () => {
    const arbre = dossier();
    const etat = etatInitialDeLArbre(arbre);

    basculerLeNoeud(arbre, etat, 'Document#30', false);
    basculerLeNoeud(arbre, etat, 'Tranche#20', false); // l’affaire entière est épargnée

    assert.deepEqual(
        conserver(arbre, etat),
        {},
        'Rien ne menace ce document : inutile de demander au serveur de le détacher.',
    );
});

test('un verrou décoche sa branche et ses ancêtres, et n’est jamais soumis', () => {
    const arbre = dossierVerrouille();
    const etat = etatInitialDeLArbre(arbre);

    assert.equal(etat.get('DepenseCourtier#50'), false);
    assert.equal(etat.get('Tranche#20'), false, 'Ce qui porte le verrou ne peut pas partir.');
    assert.equal(etat.get('Cotation#10'), false);
    assert.equal(etat.get('Piste#1'), false);
    assert.equal(etat.get('Cotation#11'), true, 'Le reste du dossier reste supprimable.');

    assert.deepEqual(lots(arbre, etat), ['Cotation#11']);

    basculerLeNoeud(arbre, etat, 'DepenseCourtier#50', true);
    assert.equal(etat.get('DepenseCourtier#50'), false, '⚠ UN VERROU NE SE COCHE PAS : la base refusera.');
});

test('une sélection multiple devient UNE forêt, coiffée d’une racine qui n’existe qu’à l’écran', () => {
    const projection = fusionnerProjections([
        {
            racine: { cle: 'Piste#1', classe: 'Piste', libelle: 'Pistes', nom: 'Affaire A', total: 2 },
            total: 2,
            noeuds: [{ cle: 'Cotation#10', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'SUNU', nature: 'structurel', geste: 'detruire', total: 1 }],
        },
        {
            racine: { cle: 'Piste#2', classe: 'Piste', libelle: 'Pistes', nom: 'Affaire B', total: 1 },
            total: 1,
            noeuds: [],
        },
    ]);
    const arbre = construireArbre(projection);
    const etat = etatInitialDeLArbre(arbre);

    assert.equal(arbre.racine, 'Selection#0');
    assert.deepEqual(arbre.enfants.get('Selection#0'), ['Piste#1', 'Piste#2']);
    assert.deepEqual(
        lots(arbre, etat),
        ['Piste#1', 'Piste#2'],
        '⚠ La racine de sélection ne correspond à AUCUNE ligne : la soumettre effacerait une entité inexistante.',
    );
    assert.equal(
        compterLesObjets(arbre, etat).total,
        3,
        'Elle ne pèse rien non plus : le volume est celui des dossiers réels.',
    );
});

test('un élément seul garde son propre arbre, sans racine artificielle', () => {
    const seule = { racine: { cle: 'Piste#1', classe: 'Piste', libelle: 'Pistes', nom: 'A', total: 1 }, total: 1, noeuds: [] };

    assert.equal(fusionnerProjections([seule]).racine.cle, 'Piste#1', 'Un niveau de plus qui ne dit rien est un niveau de trop.');
});

test('chaque lot sait de quel élément sélectionné il dépend', () => {
    const projection = fusionnerProjections([
        {
            racine: { cle: 'Piste#1', classe: 'Piste', libelle: 'Pistes', nom: 'A', total: 3 },
            total: 3,
            noeuds: [
                { cle: 'Cotation#10', parent: 'Piste#1', classe: 'Cotation', libelle: 'Propositions', nom: 'SUNU', nature: 'structurel', geste: 'detruire', total: 2 },
                { cle: 'Tranche#20', parent: 'Cotation#10', classe: 'Tranche', libelle: 'Tranches', nom: 'E1', nature: 'structurel', geste: 'detruire', total: 1 },
            ],
        },
        { racine: { cle: 'Piste#2', classe: 'Piste', libelle: 'Pistes', nom: 'B', total: 1 }, total: 1, noeuds: [] },
    ]);
    const arbre = construireArbre(projection);

    assert.equal(racineDe(arbre, 'Tranche#20'), 'Piste#1', 'C’est la racine qui nomme la route, donc la garde d’appartenance.');
    assert.equal(racineDe(arbre, 'Piste#2'), 'Piste#2');
});

test('le chemin joint par « › », comme les collections différées', () => {
    const arbre = dossier();

    assert.equal(chemin(arbre, 'Document#30'), 'Affaire ACME › SUNU › offre-sunu.pdf');
});
