/**
 * Tests de l'ÉTAT D'UN CHIP DE FILTRE RAPIDE
 * (assets/controllers/chip-preset-etat.js) — logique pure, aucun DOM.
 *
 * Ce qui est protégé ici : qu'on VOIE le filtre posé. Le chip-sélecteur du bénéficiaire
 * gardait son libellé « Choisir un agent… » quel que soit l'agent retenu, et se marquait
 * actif quand RIEN n'était filtré — en même temps que « Tous les agents ». Deux chips
 * allumés d'un côté, un nom introuvable de l'autre : un filtre qu'on ne peut pas lire est
 * un filtre qu'on croit absent.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    etatChipPreset,
    chipAutorise,
    chipVisible,
    chipPorteLaValeur,
    resoudreClicChip,
} from '../../assets/controllers/chip-preset-etat.js';

const aValeur = (valeurAttendue) => ({ valeurAttendue, estSelecteur: false, libelleDefaut: '' });
const selecteur = { valeurAttendue: '', estSelecteur: true, libelleDefaut: 'Choisir un agent…' };

test('un chip à valeur est actif quand le critère porte SA valeur (non-régression)', () => {
    const critere = { operator: '=', value: 'sans_piece', label: 'Sans pièce' };

    assert.deepEqual(etatChipPreset(aValeur('sans_piece'), critere), { actif: true, libelle: null });
    assert.deepEqual(etatChipPreset(aValeur('avec_piece'), critere), { actif: false, libelle: null });
});

test('l’option « Tous » est active quand le critère est absent (non-régression)', () => {
    assert.equal(etatChipPreset(aValeur(''), undefined).actif, true);
    assert.equal(etatChipPreset(aValeur(''), null).actif, true);
    assert.equal(etatChipPreset(aValeur('groupe'), undefined).actif, false);
});

test('un critère rendu en valeur nue reste comparable', () => {
    // Le cerveau stocke un objet, mais rien ne garantit la forme d'un critère venu
    // d'ailleurs : durcir la comparaison masquerait des chips qui marchent aujourd'hui.
    assert.equal(etatChipPreset(aValeur('groupe'), 'groupe').actif, true);
    assert.equal(etatChipPreset(aValeur('12'), 12).actif, true);
});

test('LE SÉLECTEUR N’EST PAS ACTIF QUAND RIEN N’EST FILTRÉ — c’était le défaut', () => {
    const etat = etatChipPreset(selecteur, undefined);

    assert.equal(etat.actif, false, 'Sans agent choisi, il ne doit pas se marquer actif.');
    assert.equal(etat.libelle, 'Choisir un agent…', 'Et il retrouve son libellé d’invitation.');
});

test('le sélecteur NOMME l’agent retenu, et se marque actif', () => {
    const etat = etatChipPreset(selecteur, { operator: '=', value: 42, label: 'Alice' });

    assert.equal(etat.actif, true);
    assert.equal(etat.libelle, 'Alice');
});

test('sans libellé, le sélecteur montre la valeur brute plutôt que de mentir', () => {
    // Mieux vaut un identifiant qu'un intitulé qui prétend qu'aucun filtre n'est posé.
    const etat = etatChipPreset(selecteur, { operator: '=', value: 42 });

    assert.equal(etat.actif, true);
    assert.equal(etat.libelle, '42');
});

test('après « Tous les agents », le sélecteur redevient une invitation', () => {
    // Le retrait passe par une valeur vide : c'est ce que le cerveau lit comme « retire ce
    // critère », et le chip doit suivre du même geste.
    const etat = etatChipPreset(selecteur, { operator: '=', value: '', label: '' });

    assert.equal(etat.actif, false);
    assert.equal(etat.libelle, 'Choisir un agent…');
});

test('un chip à valeur ne touche jamais à son libellé', () => {
    // `libelle: null` = « n'y touche pas ». Sans cette distinction, les chips ordinaires
    // se réécriraient à chaque synchronisation.
    assert.equal(etatChipPreset(aValeur('ce_mois'), { value: 'ce_mois', label: 'Ce mois' }).libelle, null);
});

// ── DEUX SÉLECTEURS SOUS UNE MÊME CLÉ ───────────────────────────────────────────────
//
// Le bénéficiaire d'un reversement est un agent OU un partenaire : deux colonnes, un seul
// filtre, donc deux chips côte à côte sur la même clé de critère. Sans le préfixe, choisir
// un agent allumait AUSSI « Choisir un partenaire… » — et le faisait porter le nom de
// l'agent, un filtre qui mentait sur ce qu'il filtrait.

const selAgent = {
    valeurAttendue: '', estSelecteur: true, prefixe: 'agent',
    libelleDefaut: 'Choisir un agent…',
};
const selPartenaire = {
    valeurAttendue: '', estSelecteur: true, prefixe: 'partenaire',
    libelleDefaut: 'Choisir un partenaire…',
};

test('seul le sélecteur de la BONNE famille s’allume', () => {
    const critere = { operator: '=', value: 'agent:12', label: 'Alice' };

    assert.deepEqual(etatChipPreset(selAgent, critere), { actif: true, libelle: 'Alice' });
    assert.deepEqual(
        etatChipPreset(selPartenaire, critere),
        { actif: false, libelle: 'Choisir un partenaire…' },
    );
});

test('et réciproquement pour un partenaire', () => {
    const critere = { operator: '=', value: 'partenaire:12', label: 'SUNU Courtage' };

    assert.equal(etatChipPreset(selPartenaire, critere).actif, true);
    assert.equal(etatChipPreset(selPartenaire, critere).libelle, 'SUNU Courtage');
    // Même identifiant, autre famille : l'agent #12 n'est pas le partenaire #12.
    assert.equal(etatChipPreset(selAgent, critere).actif, false);
});

test('un préfixe qui n’est pas un préfixe ne compte pas', () => {
    // « agentX:1 » ne doit pas passer pour la famille « agent » : le séparateur fait
    // partie du test, sinon deux familles homographes se confondraient.
    assert.equal(etatChipPreset(selAgent, { value: 'agentX:1' }).actif, false);
});

test('un sélecteur SANS préfixe garde son comportement (non-régression)', () => {
    // Les autres rubriques n'ont qu'un sélecteur par critère : leur exiger un préfixe
    // les aurait toutes éteintes.
    assert.equal(etatChipPreset(selecteur, { value: 'agent:12', label: 'Alice' }).actif, true);
    assert.equal(etatChipPreset(selecteur, { value: 12, label: 'Alice' }).actif, true);
});

// ═══════════════════════════════════════════════════════════════════════════════════════
// LA COHÉRENCE ENTRE CHIPS D'UNE MÊME BARRE
// ═══════════════════════════════════════════════════════════════════════════════════════
//
// « Type : Agent » et « Bénéficiaire : SUNU Courtage (partenaire) » donnent une liste
// NÉCESSAIREMENT vide — agent IS NOT NULL ET partenaire = 5 est impossible. Rien
// n'empêchait ce couple, et rien à l'écran n'en disait la cause : le pire des deux, car
// l'utilisateur conclut que la rubrique est vide.
//
// Les trois règles sont éprouvées sur la vraie grammaire de visibilité, celle des
// dialogues, et non sur une imitation : c'est elle qui décidera en production.

const TYPE = '__type_beneficiaire__';
const BENEF = '__beneficiaire_reversement__';

/** La barre de la rubrique « Rétros intermédiaires », telle que le canevas la déclare. */
const selecteurAgent = {
    cle: BENEF,
    estSelecteur: true,
    prefixe: 'agent',
    libelleDefaut: 'Choisir un agent…',
    conditions: [{ field: TYPE, operator: 'in', value: ['', 'agent'] }],
    implique: { [TYPE]: { value: 'agent', label: 'Agent' } },
};
const selecteurPartenaire = {
    cle: BENEF,
    estSelecteur: true,
    prefixe: 'partenaire',
    libelleDefaut: 'Choisir un partenaire…',
    conditions: [{ field: TYPE, operator: 'in', value: ['', 'partenaire'] }],
    implique: { [TYPE]: { value: 'partenaire', label: 'Partenaire' } },
};
const tousBenef = { cle: BENEF, valeurAttendue: '', estSelecteur: false };
const typeAgent = { cle: TYPE, valeurAttendue: 'agent', estSelecteur: false };
const typePartenaire = { cle: TYPE, valeurAttendue: 'partenaire', estSelecteur: false };
const typeTous = { cle: TYPE, valeurAttendue: '', estSelecteur: false };

const BARRE = [typeAgent, typePartenaire, typeTous, selecteurAgent, selecteurPartenaire, tousBenef];

const critere = (valeur, label) => ({ operator: '=', value: valeur, label: label || valeur });
// Le module importe lui-même la grammaire des conditions, celle des DIALOGUES : on
// éprouve donc la vraie, pas une imitation — c'est elle qui décidera en production.
const autorise = (chip, criteres) => chipAutorise(chip, criteres);
const visible = (chip, criteres) => chipVisible(chip, criteres);
const clic = (criteres, geste) => resoudreClicChip(BARRE, criteres, geste);

// ── R1 : la visibilité ───────────────────────────────────────────────────────────────

test('R1 — sans filtre de type, les DEUX sélecteurs sont proposés', () => {
    // L'absence de critère est une réponse (« Tous »), pas un silence : la condition
    // `in ['', 'agent']` la reconnaît, et c'est ce qui évite d'ajouter un opérateur
    // `empty` à un moteur que 39 dialogues partagent.
    assert.equal(autorise(selecteurAgent, {}), true);
    assert.equal(autorise(selecteurPartenaire, {}), true);
});

test('R1 — Type=Agent ne laisse que le sélecteur d’agent', () => {
    const criteres = { [TYPE]: critere('agent', 'Agent') };

    assert.equal(autorise(selecteurAgent, criteres), true);
    assert.equal(autorise(selecteurPartenaire, criteres), false);
    // « Tous » n'a AUCUNE condition : c'est le seul moyen de retirer le filtre, il ne peut
    // donc jamais disparaître.
    assert.equal(autorise(tousBenef, criteres), true);
});

test('R1 — Type=Partenaire ne laisse que le sélecteur de partenaire', () => {
    const criteres = { [TYPE]: critere('partenaire', 'Partenaire') };

    assert.equal(autorise(selecteurPartenaire, criteres), true);
    assert.equal(autorise(selecteurAgent, criteres), false);
});

test('R1 — un chip sans conditions reste autorisé (non-régression)', () => {
    // Les quatre axes des Tranches, les statuts des Cotations : aucune rubrique existante
    // ne déclare de conditions, et aucune ne doit se retrouver masquée par ce mécanisme.
    const criteres = { [TYPE]: critere('agent') };
    [typeAgent, typePartenaire, typeTous, tousBenef].forEach((chip) => {
        assert.equal(autorise(chip, criteres), true);
    });
});

// ── L'échappatoire du F5 ─────────────────────────────────────────────────────────────

test('un chip qui AFFICHE un filtre actif reste visible malgré ses conditions', () => {
    // C'est le cas F5 : un couple devenu contradictoire entre deux sessions. Masquer le
    // chip laisserait le filtre agir sans que rien ne le dise — le défaut même que R2
    // combat. Le filtre reste donc LISIBLE, et retirable.
    const restaure = {
        [TYPE]: critere('agent', 'Agent'),
        [BENEF]: critere('partenaire:5', 'SUNU Courtage'),
    };

    assert.equal(autorise(selecteurPartenaire, restaure), false, 'ses conditions le refusent');
    assert.equal(visible(selecteurPartenaire, restaure), true, 'mais il porte le filtre actif');
    // Et il l'ANNONCE, sinon le rendre visible ne servirait à rien.
    assert.equal(etatChipPreset(selecteurPartenaire, restaure[BENEF]).libelle, 'SUNU Courtage');
});

test('l’échappatoire ne rallume pas un chip sans valeur à montrer', () => {
    const criteres = { [TYPE]: critere('agent', 'Agent') };

    assert.equal(visible(selecteurPartenaire, criteres), false);
});

// ── R2 : le retrait de l'orphelin ────────────────────────────────────────────────────

test('R2 — basculer le Type retire le bénéficiaire devenu contradictoire', () => {
    const avant = { [BENEF]: critere('partenaire:5', 'SUNU Courtage') };

    const { criteres, changements } = clic(avant, { cle: TYPE, valeur: 'agent', libelle: 'Agent' });

    assert.equal(criteres[TYPE].value, 'agent');
    assert.equal(criteres[BENEF], undefined, 'le partenaire ne peut plus être lu : il part');
    // UN SEUL aller-retour : les deux changements voyagent ensemble, donc une seule
    // recherche et un seul rendu. Deux notifications auraient produit deux listes.
    assert.equal(changements.length, 2);
    assert.deepEqual(
        changements.find((c) => c.key === BENEF),
        { key: BENEF, value: '', label: '' },
        'un retrait voyage avec une valeur vide — ce que le cerveau lit déjà',
    );
});

test('R2 — un bénéficiaire COMPATIBLE survit au même geste', () => {
    const avant = { [BENEF]: critere('agent:12', 'Alice Apporteuse') };

    const { criteres, changements } = clic(avant, { cle: TYPE, valeur: 'agent', libelle: 'Agent' });

    assert.equal(criteres[BENEF].value, 'agent:12', 'rien ne justifiait de le retirer');
    assert.equal(changements.length, 1, 'seul le Type change');
});

test('R2 — « Type : Tous » ne retire rien : plus rien ne contredit', () => {
    const avant = {
        [TYPE]: critere('agent', 'Agent'),
        [BENEF]: critere('agent:12', 'Alice Apporteuse'),
    };

    const { criteres } = clic(avant, { cle: TYPE, valeur: '', libelle: 'Tous' });

    assert.equal(criteres[TYPE], undefined);
    assert.equal(criteres[BENEF].value, 'agent:12');
});

test('R2 ne touche PAS aux critères étrangers aux chips', () => {
    // Un texte de recherche, un filtre avancé : aucun chip ne les déclare, donc la règle
    // n'a rien à en dire. Les balayer serait détruire le travail de la barre de recherche.
    const avant = { reference: critere('VIR-2026'), [BENEF]: critere('partenaire:5', 'SUNU') };

    const { criteres } = clic(avant, { cle: TYPE, valeur: 'agent', libelle: 'Agent' });

    assert.equal(criteres.reference.value, 'VIR-2026');
    assert.equal(criteres[BENEF], undefined);
});

// ── R3 : l'implication ───────────────────────────────────────────────────────────────

test('R3 — choisir un partenaire aligne le Type sur sa famille', () => {
    const { criteres, changements } = clic({}, {
        cle: BENEF,
        valeur: 'partenaire:5',
        libelle: 'SUNU Courtage',
        implique: selecteurPartenaire.implique,
    });

    assert.equal(criteres[BENEF].value, 'partenaire:5');
    assert.equal(criteres[TYPE].value, 'partenaire');
    // Le libellé de l'implication compte : c'est lui que lira le badge de la barre de
    // recherche, et « partenaire » brut y serait illisible.
    assert.equal(criteres[TYPE].label, 'Partenaire');
    assert.equal(changements.length, 2);
});

test('R3 — l’implication ÉCRASE un Type contradictoire déjà posé', () => {
    const avant = { [TYPE]: critere('agent', 'Agent') };

    const { criteres } = clic(avant, {
        cle: BENEF,
        valeur: 'partenaire:5',
        libelle: 'SUNU Courtage',
        implique: selecteurPartenaire.implique,
    });

    assert.equal(criteres[TYPE].value, 'partenaire', 'le choix le plus précis l’emporte');
    assert.equal(criteres[BENEF].value, 'partenaire:5');
});

test('R3 — « Tous » sur le bénéficiaire n’implique aucun type', () => {
    // On déduit une famille d'un CHOIX, jamais d'un retrait : sinon retirer le filtre de
    // bénéficiaire poserait un filtre de type que personne n'a demandé.
    const avant = { [BENEF]: critere('agent:12', 'Alice') };

    const { criteres } = clic(avant, { cle: BENEF, valeur: '', libelle: 'Tous', implique: selecteurAgent.implique });

    assert.equal(criteres[BENEF], undefined);
    assert.equal(criteres[TYPE], undefined);
});

test('R3 puis R2 CONVERGENT en une passe : le choix ne se détruit pas lui-même', () => {
    // Le piège : R3 pose Type=agent, et R2 passe juste après sur le bénéficiaire. Si R2
    // touchait à ce qui vient d'être posé, le clic serait sans effet — un bouton qui ne
    // fait rien, et rien pour l'expliquer.
    const { criteres, changements } = clic({}, {
        cle: BENEF,
        valeur: 'agent:12',
        libelle: 'Alice Apporteuse',
        implique: selecteurAgent.implique,
    });

    assert.equal(criteres[BENEF].value, 'agent:12');
    assert.equal(criteres[TYPE].value, 'agent');
    assert.equal(changements.length, 2);

    // Et l'état ainsi obtenu est STABLE : le rejouer ne change plus rien.
    const encore = clic(criteres, { cle: BENEF, valeur: 'agent:12', libelle: 'Alice Apporteuse', implique: selecteurAgent.implique });
    assert.deepEqual(encore.changements, [], 'aucun aller-retour possible');
});

test('après un clic, aucun chip n’a plus besoin de l’échappatoire', () => {
    // C'est ce qui garantit que R1 et R2 ne se contredisent pas : une fois l'orphelin
    // retiré, la visibilité stricte et la visibilité affichée disent la même chose.
    const avant = { [BENEF]: critere('partenaire:5', 'SUNU Courtage') };
    const { criteres } = clic(avant, { cle: TYPE, valeur: 'agent', libelle: 'Agent' });

    BARRE.forEach((chip) => {
        assert.equal(
            visible(chip, criteres),
            autorise(chip, criteres),
            'un chip visible par échappatoire signalerait un orphelin resté actif',
        );
    });
});

test('chipPorteLaValeur distingue les familles et les chips à valeur', () => {
    assert.equal(chipPorteLaValeur(selecteurAgent, 'agent:12'), true);
    assert.equal(chipPorteLaValeur(selecteurAgent, 'partenaire:12'), false);
    // Le séparateur fait partie du test : « agentX:1 » n'est pas la famille « agent ».
    assert.equal(chipPorteLaValeur(selecteurAgent, 'agentX:1'), false);
    assert.equal(chipPorteLaValeur(typeAgent, 'agent'), true);
    assert.equal(chipPorteLaValeur(typeAgent, 'partenaire'), false);
    // Une valeur vide n'est portée par personne — pas même par « Tous », qui n'affiche
    // pas un filtre : il en propose le retrait.
    assert.equal(chipPorteLaValeur(tousBenef, ''), false);
});
