/**
 * Tests du TAMPON DES COLLECTIONS — l'arbre des saisies en attente.
 * Lancement : node --test tests/js/
 *
 * Ce que ces tests protègent, et qu'aucun test PHP ne peut voir : la GÉNÉALOGIE. Les
 * formulaires du workspace s'emboîtent sans limite (piste › cotation › avenant › document),
 * et le rejeu doit descendre l'arbre en donnant à chaque niveau l'id que le niveau du dessus
 * vient d'obtenir. Une erreur ici ne se manifesterait qu'à l'enregistrement, sur une saisie
 * déjà longue — le pire moment pour perdre le travail de quelqu'un.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    ajouter,
    compter,
    creerGroupe,
    libelleDepuisFormData,
    libellesEnAttente,
    preparerRejeu,
    rejouerGroupe,
    retirer,
    vider,
} from '../../assets/controllers/collection-tampon.js';

/** Un nœud « creation » minimal, avec ses sous-collections éventuelles. */
function creation(libelle, enfants = {}) {
    const formData = new FormData();
    formData.set('nom', libelle);

    return { nature: 'creation', libelle, formData, submitUrl: '/admin/x/api/submit', enfants };
}

/** Un exécuteur d'essai : note les appels et rend des ids croissants. */
function executeurFactice(idsRendus = {}, echouentSur = []) {
    const appels = [];
    let prochain = 100;

    const executer = async (noeud, charge, parentId) => {
        appels.push({
            libelle: noeud.libelle,
            nature: noeud.nature,
            parentId,
            // Ce que le serveur recevrait réellement pour le champ parent.
            champParent: charge ? [...charge.keys()].filter((k) => k !== 'nom') : null,
            valeurParent: charge ? charge.get(champParentDe(charge)) : null,
        });
        if (echouentSur.includes(noeud.libelle)) return { ok: false, erreur: 'refus simulé' };

        return { ok: true, id: idsRendus[noeud.libelle] ?? ++prochain };
    };

    return { executer, appels };
}

/** Le seul champ autre que « nom » posé par preparerRejeu : le champ parent. */
function champParentDe(formData) {
    return [...formData.keys()].find((k) => k !== 'nom') ?? null;
}

test('un arbre à trois générations rejoue dans l’ordre, chacun avec l’id de son parent', async () => {
    // piste › cotation › document : exactement le cas que l'utilisateur a décrit.
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU', { documents }));

    const { executer, appels } = executeurFactice({ 'Cotation SUNU': 71 });
    const echecs = await rejouerGroupe(cotations, 30, executer);

    assert.deepEqual(echecs, []);
    assert.equal(appels.length, 2);

    // La cotation est rattachée à la piste enregistrée…
    assert.equal(appels[0].libelle, 'Cotation SUNU');
    assert.equal(appels[0].valeurParent, '30');
    // …et le document à la cotation qui vient TOUT JUSTE de naître.
    assert.equal(appels[1].libelle, 'offre.pdf');
    assert.equal(appels[1].valeurParent, '71');
});

test('le champ parent est POSÉ, pas empilé — un rejeu relancé ne double rien', () => {
    const noeud = creation('Cotation');
    preparerRejeu(noeud, 'piste', 30);
    preparerRejeu(noeud, 'piste', 30); // second essai, après un échec ailleurs

    // Avec `append`, le serveur aurait reçu deux valeurs pour « piste » et n'en aurait
    // retenu qu'une, au hasard de l'ordre.
    assert.deepEqual(noeud.formData.getAll('piste'), ['30']);
});

test('un échec ne fait pas tomber ses frères, et remonte son chemin complet', async () => {
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));
    ajouter(documents, creation('avenant.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation A', { documents }));
    ajouter(cotations, creation('Cotation B'));

    const { executer, appels } = executeurFactice({}, ['offre.pdf']);
    const echecs = await rejouerGroupe(cotations, 30, executer);

    assert.equal(echecs.length, 1);
    // Le chemin nomme l'endroit exact : « Cotation A › offre.pdf ».
    assert.deepEqual(echecs[0].chemin, ['Cotation A', 'offre.pdf']);
    // Le frère du document ET la cotation suivante ont bien été tentés.
    assert.ok(appels.some((a) => a.libelle === 'avenant.pdf'));
    assert.ok(appels.some((a) => a.libelle === 'Cotation B'));
});

test('un rattachement n’ouvre aucun sous-arbre', async () => {
    const groupe = creerGroupe('conditionPartage');
    ajouter(groupe, {
        nature: 'rattachement',
        libelle: 'Incendie',
        idCible: 5,
        pickerBase: '/admin/conditionpartage/api/12',
        action: 'attach-risque',
        // Même si on lui en posait, ils ne doivent pas être parcourus : l'entité existe
        // déjà en base, avec sa propre descendance.
        enfants: { documents: creerGroupe('risque') },
    });

    const { executer, appels } = executeurFactice();
    const echecs = await rejouerGroupe(groupe, 12, executer);

    assert.deepEqual(echecs, []);
    assert.equal(appels.length, 1);
    assert.equal(appels[0].nature, 'rattachement');
    // Aucun FormData n'est préparé pour un rattachement : il n'y a rien à poster.
    assert.equal(appels[0].champParent, null);
});

test('retirer un nœud emporte tout son sous-arbre', () => {
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));

    const cotations = creerGroupe('piste');
    const cotation = ajouter(cotations, creation('Cotation SUNU', { documents }));
    ajouter(cotations, creation('Cotation SANLAM'));

    assert.equal(compter(cotations), 3, 'deux cotations + un document');

    retirer(cotations, cotation.cle);

    // Le document n'avait d'existence que par sa cotation : il part avec elle, plutôt que
    // de rester orphelin et sans rien à quoi se rattacher au rejeu.
    assert.equal(compter(cotations), 1);
    assert.equal(cotations.noeuds[0].libelle, 'Cotation SANLAM');
});

test('le décompte est récursif — c’est le chiffre montré à l’abandon', () => {
    const petitsEnfants = creerGroupe('avenant');
    ajouter(petitsEnfants, creation('annexe.pdf'));

    const avenants = creerGroupe('cotation');
    ajouter(avenants, creation('Police 2026', { documents: petitsEnfants }));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU', { avenants }));

    // Trois générations : annoncer « 1 élément » ferait perdre deux saisies sans le dire.
    assert.equal(compter(cotations), 3);
    assert.equal(compter(creerGroupe('piste')), 0);
    assert.equal(compter(null), 0);
});

test('les libellés d’abandon disent CE QUI sera perdu, chemin compris', () => {
    const documents = creerGroupe('cotation');
    ajouter(documents, creation('offre.pdf'));

    const cotations = creerGroupe('piste');
    ajouter(cotations, creation('Cotation SUNU', { documents }));

    assert.deepEqual(libellesEnAttente(cotations), ['Cotation SUNU', 'Cotation SUNU › offre.pdf']);
});

test('le libellé se déduit de la saisie, et jamais une ligne muette', () => {
    const avecNom = new FormData();
    avecNom.set('nom', '  Incendie  ');
    assert.equal(libelleDepuisFormData(avecNom, 'Risque'), 'Incendie');

    // Ordre de préférence : on prend le champ qui NOMME la chose.
    const avecReference = new FormData();
    avecReference.set('nom', '');
    avecReference.set('reference', 'POL-2026-001');
    assert.equal(libelleDepuisFormData(avecReference, 'Avenant'), 'POL-2026-001');

    // Rien d'exploitable : le titre du formulaire, plutôt qu'une ligne vide.
    assert.equal(libelleDepuisFormData(new FormData(), 'Document'), 'Document');
    assert.equal(libelleDepuisFormData(null, 'Document'), 'Document');
});

test('vider rend le groupe inerte — plus rien à rejouer ni à confirmer', async () => {
    const groupe = creerGroupe('piste');
    ajouter(groupe, creation('Cotation SUNU'));
    vider(groupe);

    assert.equal(compter(groupe), 0);
    assert.deepEqual(libellesEnAttente(groupe), []);

    const { appels } = executeurFactice();
    assert.deepEqual(await rejouerGroupe(groupe, 30, async () => ({ ok: true, id: 1 })), []);
    assert.equal(appels.length, 0);
});
