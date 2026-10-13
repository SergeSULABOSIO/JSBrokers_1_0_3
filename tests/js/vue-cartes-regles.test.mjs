import test from 'node:test';
import assert from 'node:assert/strict';

import {
    clicDeSelection, cleDeLaVue, cookieDeVue, dialogueSuivi, doitDistribuer, messageDEtape, vueARestaurer, vueDuCookie,
} from '../../assets/controllers/vue-cartes-regles.js';

const ETAPES = {
    a_coter: { singulier: 'À coter', raison: 'aucune cotation n’a encore été demandée' },
    en_cotation: { singulier: 'En cotation', raison: 'aucune cotation n’a de prime' },
    offres_chiffrees: { singulier: 'Offre chiffrée', raison: 'aucune cotation n’a encore été souscrite' },
    transformees: { singulier: 'Transformée', raison: '' },
};

test('la vue par défaut est « cartes », et une valeur inconnue y retombe — même règle que le serveur', () => {
    assert.equal(vueARestaurer('liste'), 'liste');
    assert.equal(vueARestaurer('cartes'), 'cartes');
    assert.equal(vueARestaurer(undefined), 'cartes');
    assert.equal(vueARestaurer('9111986cartes'), 'cartes', 'la valeur préfixée de l’ancien saveCookie ne passe pas');
    assert.equal(vueARestaurer(''), 'cartes');
});

test('le cookie est rangé par cabinet et par entité, lisible par le serveur sur tout le site', () => {
    const cle = cleDeLaVue(5, 'Piste');
    assert.equal(cle, 'listeVue_5_Piste');
    assert.equal(cookieDeVue(cle, 'liste'), 'listeVue_5_Piste=liste; path=/; max-age=31536000; SameSite=Lax');
    assert.equal(cookieDeVue(cle, 'nimporte'), 'listeVue_5_Piste=cartes; path=/; max-age=31536000; SameSite=Lax');
    assert.equal(vueDuCookie('a=1; listeVue_5_Piste=liste; b=2', cle), 'liste');
    assert.equal(vueDuCookie('listeVue_6_Piste=liste', cle), 'cartes', 'le choix d’un autre cabinet ne s’applique pas');
});

test('le second clic d’un double-clic ne défait pas la sélection', () => {
    assert.equal(clicDeSelection(1), true);
    assert.equal(clicDeSelection(2), false);
    assert.equal(clicDeSelection(3), false);
    assert.equal(clicDeSelection(0), true, 'un clic synthétique (clavier) compte');
});

test('une source n’est distribuée qu’une fois : connect() puis le premier app:list.rendered n’en font qu’une', () => {
    const source = { dataset: {} };
    assert.equal(doitDistribuer(source), true);
    source.dataset.distribue = 'oui'; // ce que fait distribuer()
    assert.equal(doitDistribuer(source), false, 'le premier rendu annoncé ne redistribue pas');
    assert.equal(doitDistribuer({ dataset: {} }), true, 'un rafraîchissement apporte une source neuve');
    assert.equal(doitDistribuer(null), false);
});

test('« passée en … » quand l’étape change après un enregistrement', () => {
    assert.deepEqual(
        messageDEtape({ avant: 'en_cotation', apres: 'offres_chiffrees', enregistre: true }, ETAPES),
        { texte: 'Piste passée en « Offre chiffrée ».', type: 'success' },
    );
});

test('« reste … » seulement si quelque chose a été enregistré, avec la raison', () => {
    assert.deepEqual(
        messageDEtape({ avant: 'en_cotation', apres: 'en_cotation', enregistre: true }, ETAPES),
        { texte: 'La piste reste « En cotation » : aucune cotation n’a de prime.', type: 'info' },
    );
});

test('fiche fermée sans rien enregistrer : aucun message', () => {
    assert.equal(messageDEtape({ avant: 'en_cotation', apres: 'en_cotation', enregistre: false }, ETAPES), null);
    assert.equal(messageDEtape({ avant: 'en_cotation', apres: 'offres_chiffrees', enregistre: false }, ETAPES), null);
    assert.equal(messageDEtape({ avant: 'a_coter', apres: null, enregistre: true }, ETAPES), null, 'sans réponse du serveur, pas de message faux');
});

test('un dialogue ouvert depuis la fiche de la carte lui est rattaché (collections imbriquées)', () => {
    assert.equal(dialogueSuivi({ racine: true }), true);
    assert.equal(dialogueSuivi({ dansUnDialogueSuivi: true }), true, 'la cotation ouverte depuis la piste, l’avenant depuis la cotation');
    assert.equal(dialogueSuivi({}), false, 'un dialogue sans lien avec la carte ne compte pas');
});
