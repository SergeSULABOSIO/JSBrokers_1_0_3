/**
 * Tests du GARDE-FOU DE FERMETURE — on ne quitte pas une saisie en attente sans trancher.
 * Lancement : node --test tests/js/
 *
 * Une saisie différée n'existe NULLE PART ailleurs qu'en mémoire de page : ni en base, ni
 * dans un brouillon, ni dans un historique. Fermer la boîte sans rien dire la perdrait
 * définitivement, et sans le moindre moyen de la retrouver. C'est le seul endroit du
 * mécanisme où une négligence coûte le travail de quelqu'un — d'où ces tests.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ajouter,
    creerGroupe,
    resumeDeFermeture,
    vider,
} from '../../assets/controllers/collection-tampon.js';

function creation(libelle, enfants = {}) {
    const formData = new FormData();
    formData.set('nom', libelle);

    return { nature: 'creation', libelle, formData, submitUrl: '/admin/x/api/submit', enfants };
}

test('rien en attente : la fermeture ne pose aucune question', () => {
    const resume = resumeDeFermeture([creerGroupe('piste'), creerGroupe('cotation')]);

    // Une confirmation systématique serait un péage : l'utilisateur apprendrait à la
    // valider sans lire, et le jour où elle compte vraiment, elle ne le retiendrait plus.
    assert.equal(resume.doitConfirmer, false);
    assert.equal(resume.nombre, 0);
    assert.deepEqual(resume.libelles, []);
});

test('la question porte sur la BOÎTE entière, pas sur une collection', () => {
    // Un dialogue porte souvent plusieurs collections : documents, cotations, tâches…
    const documents = creerGroupe('piste');
    ajouter(documents, creation('offre.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU'));

    const resume = resumeDeFermeture([documents, cotations]);

    assert.equal(resume.doitConfirmer, true);
    assert.equal(resume.nombre, 2, 'les deux collections comptent ensemble');
});

test('le décompte inclut les descendants — sinon on annoncerait moins que ce qu’on détruit', () => {
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));
    ajouter(documents, creation('annexe.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU', { documents }));

    const resume = resumeDeFermeture([cotations]);

    // Annoncer « 1 élément » ferait disparaître deux fichiers sans que rien ne les nomme.
    assert.equal(resume.nombre, 3);
});

test('la confirmation NOMME ce qui sera perdu, chemin compris', () => {
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU', { documents }));

    const resume = resumeDeFermeture([cotations]);

    // Un nombre seul obligerait l'utilisateur à deviner ce qu'il abandonne.
    assert.deepEqual(resume.libelles, ['Cotation SUNU', 'Cotation SUNU › offre.pdf']);
});

test('après l’abandon confirmé, plus rien ne se redéclenche', () => {
    const groupe = creerGroupe('piste');
    ajouter(groupe, creation('Cotation SUNU'));

    vider(groupe);

    // Vider AVANT de fermer : sans cela, le garde-fou se rappellerait à l'utilisateur
    // pour une saisie qu'il vient précisément d'accepter de perdre.
    assert.equal(resumeDeFermeture([groupe]).doitConfirmer, false);
});

test('une entrée absente ou nulle ne fait pas tomber la décision', () => {
    // Le garde-fou traverse tous les widgets du dialogue, dont certains peuvent être en
    // cours de montage. Il doit rester silencieux plutôt que de lever.
    const resume = resumeDeFermeture([null, undefined, creerGroupe('piste')]);

    assert.equal(resume.doitConfirmer, false);
    assert.equal(resumeDeFermeture(null).nombre, 0);
});
