/**
 * ÉMETTRE UNE NOTE N'EST PAS L'ENVOYER — la fenêtre doit proposer le PDF.
 *
 * ── CE QUE CE CONTRAT PROTÈGE ───────────────────────────────────────────────────────
 * Le courtier facture pour ENVOYER : la note de débit part à l'assureur, qui règle sur
 * son vu. Une fenêtre qui se refermerait au succès — comme le fait celle du reversement,
 * où il n'y a rien à envoyer — l'obligerait à retrouver sa note dans la rubrique, à la
 * sélectionner, à choisir « Télécharger en PDF ». Trois gestes pour finir ce qu'il vient
 * de commencer, et autant d'occasions de croire l'affaire faite alors que rien n'est parti.
 *
 * Quatre façons de rompre cela en silence, chacune fermée ici :
 *
 *   1. fermer au succès plutôt que basculer le pied — le PDF devient introuvable ;
 *   2. écrire du code d'ouverture de PDF ici — le cerveau sait déjà le faire sur une URL
 *      « download », et une seconde implémentation divergerait ;
 *   3. ne lire que l'URL de l'action au lieu de la SÉLECTION — `%id%` ne porte que la
 *      première ligne cochée, les autres échéances seraient oubliées sans un mot ;
 *   4. proposer « Facturer les suivantes » quand il n'y a pas de suivant.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const picker = readFileSync(join(RACINE, 'facturation-picker_controller.js'), 'utf8');
const cerveau = readFileSync(join(RACINE, 'cerveau_controller.js'), 'utf8');

test('le picker hérite du socle commun plutôt que de le recopier', () => {
    assert.match(
        picker,
        /import PickerBase from '\.\/picker-base_controller\.js'/,
        'Overlay, Échap, restitution du focus, progression et zone d\'erreur sont acquis : '
        + 'les réécrire ferait une seconde fenêtre à tenir en accord avec les cinq autres.',
    );
    assert.match(picker, /extends PickerBase/);
});

test('la fenêtre ne se ferme PAS au succès : elle bascule vers le PDF', () => {
    const corps = picker.slice(picker.indexOf('async _onActionClick'));
    const fin = corps.indexOf('_basculerVersLeSucces(message)');
    const envoi = corps.slice(0, fin > 0 ? fin : undefined);

    assert.match(envoi, /_basculerVersLeSucces\(/,
        'Le succès doit basculer le pied, pas refermer la fenêtre.');
    assert.ok(
        !/this\.close\(\);/.test(envoi),
        'Fermer au succès obligerait à retrouver la note dans sa rubrique pour l\'envoyer.',
    );
});

test('« Ouvrir la note (PDF) » délègue au cerveau, avec l\'URL de téléchargement', () => {
    assert.match(
        picker,
        /ouvrirLePdf\(\)[\s\S]*?ui:note\.preview-request[\s\S]*?download=1/,
        'Le cerveau connaît déjà cette branche : il ouvre le PDF dans un onglet. Écrire ici '
        + 'un second chemin d\'impression le ferait diverger du bouton de la rubrique.',
    );
    assert.ok(
        !/window\.open|window\.print/.test(picker),
        'Aucune ouverture directe : c\'est le cerveau qui décide comment une note s\'ouvre.',
    );
});

test('« Facturer les suivantes » ne paraît que s\'il reste un destinataire', () => {
    assert.match(
        picker,
        /const resteDesSuivants = \(this\.suivantsValue \|\| \[\]\)\.length > 0;[\s\S]*?suivantTarget\.hidden = !resteDesSuivants/,
        'Un bouton qui ne mènerait nulle part vaut moins que pas de bouton du tout.',
    );
});

test('le cerveau lit la SÉLECTION, pas seulement l\'URL de l\'action', () => {
    // ⚠ ON VISE LA DÉFINITION, PAS LE NOM. `_handleNoteFacturationEnregistree`
    // apparaît d'abord dans le `switch`, bien avant sa méthode : borner dessus rendait
    // une tranche VIDE, et le test passait en ne lisant rien.
    const debut = cerveau.indexOf('async handleTrancheFacturerCommission(payload) {');
    const fin = cerveau.indexOf('_handleNoteFacturationEnregistree(payload) {', debut);
    const handler = cerveau.slice(debut, fin);

    assert.ok(debut >= 0 && fin > debut, 'Les bornes du handler doivent être trouvées.');

    assert.match(handler, /payload\.selection/,
        '`%id%` ne transporte que la PREMIÈRE ligne cochée : s\'en contenter facturerait '
        + 'une échéance et oublierait les autres, sans un mot.');
    assert.match(handler, /ids\.join\(','\)/, 'La sélection entière voyage en query.');
    assert.match(handler, /controllerName: 'facturation-picker'/);
});

test('le succès de l\'écriture est routé par le cerveau, qui rafraîchit la liste', () => {
    assert.match(
        cerveau,
        /case 'client:note\.facturation-enregistree':/,
        'Sans ce `case`, la notification et le rafraîchissement seraient des trous noirs.',
    );
    assert.match(
        picker,
        /_notifyCerveau\('client:note\.facturation-enregistree'/,
        'Le picker annonce, le cerveau décide quoi rafraîchir — il est le seul à savoir '
        + 'quel onglet est actif.',
    );
});

test('changer de destinataire repasse par le SERVEUR', () => {
    assert.match(
        picker,
        /changerDestinataire\(\)[\s\S]*?ui:tranche\.facturer-commission[\s\S]*?destinataire=/,
        'Un client n\'est pas l\'assureur de sa police : les lignes proposées changent. '
        + 'Recalculer dans le navigateur y porterait une seconde fois la règle métier.',
    );
});

test('le picker ne calcule aucun montant métier', () => {
    assert.match(
        picker,
        /parseFloat\(ligne\.dataset\.montant/,
        'Le reste à facturer est posé par le serveur, par la même règle que l\'assistant. '
        + 'Le picker ne fait qu\'additionner ce qui est coché.',
    );
    assert.ok(
        !/taux|pourcentage|montantTTC/i.test(picker),
        'Aucune formule de commission ne doit vivre dans le navigateur.',
    );
});
