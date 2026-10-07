/**
 * UNE SEULE BARRE D'ACTIONS — workspace ET dialogue d'entité.
 *
 * ── LE DÉFAUT QUI L'A FAIT NAÎTRE ───────────────────────────────────────────────────
 * Le dialogue d'entité avait SA barre : boutons sombres maison, circuit d'icônes à part,
 * aucune famille regroupée, enfermée dans la colonne des attributs calculés — où neuf
 * boutons ne tenaient plus, et d'où elle disparaissait quand la fiche n'avait aucun
 * attribut calculé. Elle n'envoyait pas non plus la `selection` que lisent les handlers
 * du cerveau, et ignorait toute action sans URL (« Ajouter au chat » ne faisait rien).
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────────────
 * 1. Les deux surfaces passent par le MÊME rendu (barre-actions.js), et aucune ne
 *    reconstruit le sien.
 * 2. Le dialogue n'impose aucun plafond : la barre a toute la largeur.
 * 3. Le selecto de la fiche a EXACTEMENT les champs de celui d'une ligne de liste — une
 *    action reçoit la même chose, qu'elle parte d'une ligne ou d'une fiche.
 * 4. Échap referme le menu de famille SANS atteindre la modale qui le contient.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const lire = (fichier) => readFileSync(join(RACINE, fichier), 'utf8');

const barre = lire('barre-actions.js');
const toolbar = lire('toolbar_controller.js');
const dialogue = lire('dialog-instance_controller.js');
const ligne = lire('list-row_controller.js');

test('les deux barres passent par le même rendu', () => {
    for (const [nom, source] of [['barre du workspace', toolbar], ['barre du dialogue', dialogue]]) {
        assert.match(source, /import \{ BarreActions \} from '\.\/barre-actions\.js';/, `${nom} : import manquant`);
        assert.match(source, /new BarreActions\(/, `${nom} : la barre partagée doit être instanciée`);
        assert.doesNotMatch(source, /toolbar-groupe-menu/, `${nom} : ne doit plus construire ses menus de famille elle-même`);
        assert.doesNotMatch(source, /app:icon\.loaded', this\.boundHandleIconLoaded\);[\s\S]*attr-action/, `${nom} : plus de circuit d'icônes propre`);
    }
});

test('le dialogue ne plafonne pas ses actions', () => {
    assert.match(dialogue, /this\.barreActions\.afficher\(visibles\);/);
    assert.match(toolbar, /this\.barreActions\.afficher\(actions, \{ maxInline: TOOLBAR_MAX_ACTIONS_EN_LIGNE \}\);/);
});

test('le selecto de la fiche a les champs de celui d’une ligne de liste', () => {
    // Côté liste : l'objet `payload` de buildSelectoPayload, plus `payload.name`.
    const blocLigne = ligne.match(/const payload = \{([\s\S]*?)\};/);
    assert.ok(blocLigne, 'buildSelectoPayload introuvable');
    const champsLigne = [...blocLigne[1].matchAll(/^\s*(\w+)\s*[:,]/gm)].map((m) => m[1]);
    assert.match(ligne, /payload\.name = /);
    champsLigne.push('name');

    // Côté dialogue : l'objet rendu par _selectoDeLaFiche.
    const blocFiche = dialogue.match(/_selectoDeLaFiche\(\) \{[\s\S]*?return \{([\s\S]*?)\};/);
    assert.ok(blocFiche, '_selectoDeLaFiche introuvable');
    const champsFiche = [...blocFiche[1].matchAll(/^\s*(\w+)\s*[:,]/gm)].map((m) => m[1]);

    assert.deepEqual([...champsFiche].sort(), [...champsLigne].sort());
    assert.match(dialogue, /selection: \[this\._selectoDeLaFiche\(\)\]/, 'le clic doit envoyer la sélection');
});

test('le nom de la fiche suit la règle partagée avec la liste', () => {
    assert.match(ligne, /nomAffiche\(this\.element\.dataset\.label, donnees, this\.idobjetValue\)/);
    assert.match(dialogue, /nomAffiche\(source\?\.dataset\.label, this\.entity, this\.entity\.id\)/);
});

test('Échap ne referme que le menu, jamais la modale qui le porte', () => {
    // Capture sur le document : l'écouteur passe AVANT celui de la modale Bootstrap.
    assert.match(barre, /document\.addEventListener\('keydown', this\.boundEchapMenu, true\);/);
    assert.match(barre, /document\.removeEventListener\('keydown', this\.boundEchapMenu, true\);/);
    assert.match(barre, /event\.key === 'Escape'\) \{\s*event\.preventDefault\(\);\s*event\.stopPropagation\(\);/);
    // Le menu vit dans la barre (donc dans la modale), pas sous le body.
    assert.doesNotMatch(barre, /document\.body\.append/);
});
