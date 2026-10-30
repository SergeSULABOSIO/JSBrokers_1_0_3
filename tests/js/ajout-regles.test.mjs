import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ajoutOffert, estOngletContextuel } from '../../assets/controllers/ajout-regles.js';

test('« Ajouter » suit la décision du serveur, et elle seule', () => {
    assert.equal(ajoutOffert({ ajout: { offert: true, motif: null } }), true);
    assert.equal(ajoutOffert({ ajout: { offert: false, motif: 'Une tranche se crée depuis sa proposition…' } }), false);
});

test('sans décision du serveur, rien n\'est offert (fermé par défaut)', () => {
    assert.equal(ajoutOffert({ endpoint_submit_url: '/admin/client/api/submit' }), false);
    assert.equal(ajoutOffert({}), false);
    assert.equal(ajoutOffert(null), false);
    assert.equal(ajoutOffert(undefined), false);
});

test('l\'onglet contextuel se reconnaît à son identifiant, et à lui seul', () => {
    assert.equal(estOngletContextuel('collection-tranches-for-12'), true);
    assert.equal(estOngletContextuel('principal'), false);
    assert.equal(estOngletContextuel('collection-tranches-for-'), false);
    assert.equal(estOngletContextuel(undefined), false);
});
