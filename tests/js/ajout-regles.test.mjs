import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ajoutOffert, estOngletContextuel } from '../../assets/controllers/ajout-regles.js';

const ecriture = { endpoint_submit_url: '/admin/tranche/api/submit' };

test('une rubrique ordinaire offre « Ajouter » partout', () => {
    assert.equal(ajoutOffert(ecriture, 'principal'), true);
    assert.equal(ajoutOffert(ecriture, 'collection-tranches-for-12', 'cotation'), true);
});

test('sans droit d\'écriture (pas d\'URL de soumission), rien n\'est offert', () => {
    assert.equal(ajoutOffert({}, 'principal'), false);
    assert.equal(ajoutOffert(null, 'principal'), false);
});

test('une entité qui ne naît que rattachée : absente de la liste principale, offerte dans l\'onglet contextuel de SON parent', () => {
    const tranche = { ...ecriture, creation_depuis_le_parent: 'cotation' };
    assert.equal(ajoutOffert(tranche, 'principal'), false);
    assert.equal(ajoutOffert(tranche, null), false, 'au chargement, avant tout contexte, l\'onglet est le principal');
    assert.equal(ajoutOffert(tranche, 'collection-tranches-for-12', 'cotation'), true);
});

test('un onglet contextuel rattaché par UN AUTRE champ n\'offre pas « Ajouter » : le serveur refuserait', () => {
    // Fiche Assureur, onglet « Cotations » : lien `assureur`, alors qu'une proposition naît de sa piste.
    const cotation = { ...ecriture, creation_depuis_le_parent: 'piste' };
    assert.equal(ajoutOffert(cotation, 'collection-cotations-for-7', 'assureur'), false);
    assert.equal(ajoutOffert(cotation, 'collection-cotations-for-7', null), false, 'lien inconnu : rien n\'est promis');
    assert.equal(ajoutOffert(cotation, 'collection-cotations-for-7', 'piste'), true);
});

test('une rubrique fermée à la création ne l\'offre nulle part', () => {
    const fermee = { ...ecriture, creation_interdite: true };
    assert.equal(ajoutOffert(fermee, 'principal'), false);
    assert.equal(ajoutOffert(fermee, 'collection-conditionsPartage-for-3', 'partenaire'), false);
});

test('l\'onglet contextuel se reconnaît à son identifiant, et à lui seul', () => {
    assert.equal(estOngletContextuel('collection-tranches-for-12'), true);
    assert.equal(estOngletContextuel('principal'), false);
    assert.equal(estOngletContextuel('collection-tranches-for-'), false);
    assert.equal(estOngletContextuel(undefined), false);
});
