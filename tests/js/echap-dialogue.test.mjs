/**
 * ÉCHAP DANS LES DIALOGUES (chantier 2) — règles pures de assets/controllers/echap-dialogue.js,
 * plus un CONTRÔLE CROISÉ du code réel.
 * Lancement : node --test tests/js/
 *
 * Exigences de Serge (2026-10-20) : Échap ferme d'abord l'élément ouvert DANS le dialogue
 * (autocomplétion, menu, liste), puis le dialogue du DESSUS seulement ; jamais pendant une
 * saisie dans un éditeur riche ; une saisie non enregistrée (formulaire comparé entre
 * l'ouverture et maintenant, Quill et fichiers compris) demande confirmation ; le focus revient
 * à l'élément qui a ouvert le dialogue.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
    decisionEchap,
    empreinteDuFormulaire,
    OUVERTS_DANS_LE_DIALOGUE,
} from '../../assets/controllers/echap-dialogue.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (chemin) => readFileSync(join(RACINE, chemin), 'utf8');

const base = { touche: 'Escape', dessus: 'formulaire' };

test('Échap sur un dialogue de formulaire au repos : il se ferme (par le garde-fou de saisie)', () => {
    assert.equal(decisionEchap(base), 'fermer-formulaire');
    assert.equal(decisionEchap({ ...base, touche: 'Esc' }), 'fermer-formulaire');
});

test('un élément ouvert DANS le dialogue se ferme d’abord : le dialogue reste', () => {
    assert.equal(decisionEchap({ ...base, sousElementOuvert: true }), 'laisser');
});

test('pendant une saisie dans un éditeur riche, Échap ne ferme rien', () => {
    assert.equal(decisionEchap({ ...base, dansEditeurRiche: true }), 'laisser');
});

test('seul le dialogue du DESSUS est concerné', () => {
    // La QUESTION au-dessus du formulaire : Échap la ferme (annuler ne perd rien), et
    // seulement elle — un second Échap rapide ne ferme pas en plus le formulaire du dessous.
    // Constaté au navigateur : deux modales empilées se disputent le focus, et Bootstrap ne
    // voyait pas l'Échap destiné à la question ; on la ferme donc nous-mêmes.
    assert.equal(decisionEchap({ ...base, dessus: 'confirmation' }), 'fermer-confirmation');
    assert.equal(decisionEchap({ ...base, dessus: 'confirmation', dansEditeurRiche: true, sousElementOuvert: true }), 'fermer-confirmation');
    assert.equal(decisionEchap({ ...base, dessus: 'autre' }), 'laisser');
    assert.equal(decisionEchap({ ...base, dessus: null }), 'laisser');
    assert.equal(decisionEchap({ ...base, dessus: 'simple' }), 'fermer-simple');
});

test('une autre touche, une composition (IME) ou un Échap déjà traité : rien', () => {
    assert.equal(decisionEchap({ ...base, touche: 'Enter' }), 'laisser');
    assert.equal(decisionEchap({ ...base, enComposition: true }), 'laisser');
    assert.equal(decisionEchap({ ...base, dejaTraitee: true }), 'laisser');
});

test('l’empreinte du formulaire : champs, texte riche et FICHIERS (nom et taille)', () => {
    const vide = empreinteDuFormulaire([['nom', ''], ['description', '<p><br></p>']]);
    const saisi = empreinteDuFormulaire([['nom', 'Marlette'], ['description', '<p><br></p>']]);
    const riche = empreinteDuFormulaire([['nom', ''], ['description', '<p>Relancer</p>']]);
    assert.notEqual(vide, saisi);
    assert.notEqual(vide, riche);
    // Une saisie défaite redonne l'empreinte de départ : rien à confirmer.
    assert.equal(empreinteDuFormulaire([['nom', ''], ['description', '<p><br></p>']]), vide);

    const fichier = (name, size) => ({ name, size, type: 'application/pdf', lastModified: 1 });
    const sansFichier = empreinteDuFormulaire([['piece', fichier('', 0)]]);
    const avecFichier = empreinteDuFormulaire([['piece', fichier('police.pdf', 1200)]]);
    const autreTaille = empreinteDuFormulaire([['piece', fichier('police.pdf', 1300)]]);
    assert.notEqual(sansFichier, avecFichier);
    assert.notEqual(avecFichier, autreTaille);
    assert.doesNotThrow(() => empreinteDuFormulaire([]));
});

test('les éléments « ouverts » reconnus : menus et listes, pas une section dépliée', () => {
    for (const attendu of ['[aria-haspopup][aria-expanded="true"]', '[role="combobox"][aria-expanded="true"]', '.jsb-picker-overlay', '.dropdown-menu.show', '.ql-tooltip:not(.ql-hidden)']) {
        assert.ok(OUVERTS_DANS_LE_DIALOGUE.includes(attendu), attendu);
    }
    // Un accordéon déplié porte aria-expanded="true" sans aria-haspopup : il ne bloque pas Échap.
    assert.ok(!/(^|,\s*)\[aria-expanded="true"\]/.test(OUVERTS_DANS_LE_DIALOGUE));
});

// ── Contrôle croisé ─────────────────────────────────────────────────────────────

test('le dialogue garde data-bs-keyboard="false" : Bootstrap ne ferme JAMAIS sans le garde-fou', () => {
    assert.match(source('assets/controllers/dialog-manager_controller.js'), /data-bs-keyboard="false"/);
});

test('dialog-manager décide d’Échap en phase de CAPTURE, par decisionEchap', () => {
    const code = source('assets/controllers/dialog-manager_controller.js');
    assert.match(code, /addEventListener\('keydown', this\.boundEchap, true\)/);
    assert.match(code, /decisionEchap\(/);
    // Le dialogue de formulaire se ferme par SA méthode close() (garde-fou), jamais par hide().
    assert.match(code, /\.close\(\)/);
});

test('dialog-instance : une seule empreinte, prise au premier geste, création comprise', () => {
    const code = source('assets/controllers/dialog-instance_controller.js');
    assert.match(code, /empreinteDuFormulaire\(/);
    assert.doesNotMatch(code, /if \(!form \|\| this\.isCreateMode\) return;/);
    // La fermeture demande confirmation quand le formulaire a changé.
    assert.match(code, /this\._saisieEnCours\(\)/);
});

// ── Revue du chantier 2 ─────────────────────────────────────────────────────────

test('une touche Échap MAINTENUE (répétition automatique) ne ferme pas la pile entière', () => {
    assert.equal(decisionEchap({ ...base, repetee: true }), 'laisser');
});

test('un champ qui fait son propre usage d’Échap (« Aller à la page ») le garde', () => {
    assert.equal(decisionEchap({ ...base, echapLocal: true }), 'laisser');
});

test('dialog-instance : rien ne se ferme pendant un enregistrement (boutons verrouillés)', () => {
    const code = source('assets/controllers/dialog-instance_controller.js');
    const close = code.slice(code.indexOf('\n    close() {'), code.indexOf('\n    _widgetsEnAttente() {'));
    assert.match(close, /closeButtonTarget\.disabled/);
    // close() dit s'il ferme : le geste de suite n'ouvre rien quand une question est posée.
    const suite = code.slice(code.indexOf('\n    executerLeGesteDeSuite() {'), code.indexOf('\n    doClose(event) {'));
    assert.match(suite, /if \(!this\.close\(\)\)/);
});
