import { test } from 'node:test';
import assert from 'node:assert/strict';

import { etatDuGroupe, transitionDuPanneau, PANNEAU_FERME, APERCU, PANNEAU_EPINGLE } from '../../assets/controllers/rail-chips-regles.js';

const prime = (actif) => ({
    titre: 'Prime',
    chips: [
        { valeur: 'payee', libelle: 'Payée', actif: actif === 'payee' },
        { valeur: 'impayee', libelle: 'Impayée', actif: actif === 'impayee' },
        { valeur: '', libelle: 'Toutes', actif: actif === '' },
    ],
});

test('un groupe filtré : pastille, et le bouton se nomme « Titre : valeur »', () => {
    assert.deepEqual(etatDuGroupe(prime('impayee')), { filtre: true, nom: 'Prime : Impayée' });
});

test('« Toutes » ne masque rien : pas de pastille, le nom le dit quand même', () => {
    assert.deepEqual(etatDuGroupe(prime('')), { filtre: false, nom: 'Prime : Toutes' });
});

test('aucun chip actif (avant la première synchronisation) : le titre seul', () => {
    assert.deepEqual(etatDuGroupe(prime(null)), { filtre: false, nom: 'Prime' });
});

test('le défaut d’un groupe sans « Toutes » masque des données : pastille', () => {
    const statut = { titre: 'Statut', chips: [{ valeur: 'souscrites', libelle: 'Souscrites', actif: true }, { valeur: 'en_attente', libelle: 'En attente', actif: false }] };
    assert.deepEqual(etatDuGroupe(statut), { filtre: true, nom: 'Statut : Souscrites' });
});

test('un chip-sélecteur actif porte le NOM choisi, et filtre', () => {
    const beneficiaire = { titre: 'Bénéficiaire', chips: [{ valeur: '', libelle: 'Tous', actif: false }, { valeur: null, libelle: 'Alice', actif: true }] };
    assert.deepEqual(etatDuGroupe(beneficiaire), { filtre: true, nom: 'Bénéficiaire : Alice' });
});

test('la maille de lecture ne porte jamais de pastille', () => {
    const affichage = { titre: 'Affichage', presentation: true, chips: [{ valeur: 'detail', libelle: 'Détail', actif: true }] };
    assert.deepEqual(etatDuGroupe(affichage), { filtre: false, nom: 'Affichage : Détail' });
});

test('AUCUN GESTE DU POINTEUR N’ÉPINGLE : survol ou clic, il se ferme quand le pointeur part (Serge, 2026-10-29)', () => {
    const ouvertAuSurvol = transitionDuPanneau({ etat: PANNEAU_FERME, groupe: null }, { type: 'survol', groupe: 'prime' });
    assert.deepEqual(ouvertAuSurvol, { etat: APERCU, groupe: 'prime' });
    const ouvertAuClic = transitionDuPanneau({ etat: PANNEAU_FERME, groupe: null }, { type: 'clicGroupe', groupe: 'prime', clavier: false });
    assert.deepEqual(ouvertAuClic, { etat: APERCU, groupe: 'prime' });
    assert.deepEqual(transitionDuPanneau(ouvertAuClic, { type: 'sortie' }), { etat: PANNEAU_FERME, groupe: null });
});

test('ouvert au clavier, il reste ; la perte du focus le ferme (Serge, 2026-10-29)', () => {
    const auClavier = transitionDuPanneau({ etat: PANNEAU_FERME, groupe: null }, { type: 'clicGroupe', groupe: 'prime', clavier: true });
    assert.deepEqual(auClavier, { etat: PANNEAU_EPINGLE, groupe: 'prime' });
    assert.deepEqual(transitionDuPanneau(auClavier, { type: 'survol', groupe: 'prime' }), auClavier, 'le survol de son bouton ne le déclasse pas');
    assert.deepEqual(transitionDuPanneau(auClavier, { type: 'sortie' }), { etat: PANNEAU_FERME, groupe: null });
});

test('re-clic, Échap, clic dehors : fermé ; un autre groupe : il bascule', () => {
    const ouvert = { etat: APERCU, groupe: 'prime' };
    assert.deepEqual(transitionDuPanneau(ouvert, { type: 'clicGroupe', groupe: 'prime' }), { etat: PANNEAU_FERME, groupe: null });
    assert.deepEqual(transitionDuPanneau(ouvert, { type: 'echap' }), { etat: PANNEAU_FERME, groupe: null });
    assert.deepEqual(transitionDuPanneau(ouvert, { type: 'exterieur' }), { etat: PANNEAU_FERME, groupe: null });
    assert.deepEqual(transitionDuPanneau(ouvert, { type: 'survol', groupe: 'commission' }), { etat: APERCU, groupe: 'commission' });
    assert.deepEqual(transitionDuPanneau(ouvert, { type: 'inconnue' }), ouvert);
});
