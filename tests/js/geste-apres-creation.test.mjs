/**
 * ENREGISTRER N'EST PAS ENVOYER — le dialogue doit proposer la suite.
 *
 * ── CE QUE CE CONTRAT PROTÈGE ───────────────────────────────────────────────────────
 * Une demande de congé naît en BROUILLON : l'enregistrement ne l'envoie à personne. Rien
 * ne le disait sur l'écran de création. L'utilisateur fermait la boîte, retrouvait sa
 * ligne dans la liste, la sélectionnait, cherchait « Soumettre » — quatre gestes pour
 * finir ce qu'il venait de commencer, et autant d'occasions de croire l'affaire réglée
 * alors qu'elle dormait en brouillon.
 *
 * Le mécanisme est DÉCLARATIF : le canevas annonce `parametres.action_apres_creation`,
 * et le dialogue substitue le bouton. Trois façons de le rompre en silence, chacune
 * fermée ici :
 *
 *   1. poser le geste en ÉDITION aussi — corriger une fiche existante n'a pas de suite
 *      obligée, et un bouton « Soumettre au valideur » sur une demande déjà approuvée
 *      serait un contresens ;
 *   2. réécrire le libellé du bouton d'enregistrement plutôt que d'en poser un neuf —
 *      `toggleLoading()` le réécrit à chaque bascule, et effacerait le nôtre ;
 *   3. omettre `dialogId` dans `app:entity.saved` — sans lui, personne ne peut refermer
 *      la boîte de l'extérieur, et l'enchaînement retombe sur un clic manuel.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const dialogue = readFileSync(join(RACINE, 'dialog-instance_controller.js'), 'utf8');
const cerveau = readFileSync(join(RACINE, 'cerveau_controller.js'), 'utf8');

test("le geste de suite n'est retenu QUE lorsque la fiche vient de naître", () => {
    // Il est posé dans la branche qui bascule de création en édition — la seule qui
    // sache encore qu'il s'agissait d'une création.
    assert.match(
        dialogue,
        /if \(this\.isCreateMode && result\.entity\) \{[\s\S]*?this\._suiteApresCreation = result\.entity\.id/,
        'Le geste de suite doit être décidé dans la branche de création, avant que '
        + '`isCreateMode` ne bascule à false et que le renseignement ne soit perdu.',
    );
});

test('le geste de suite est un bouton NEUF, pas un libellé réécrit', () => {
    assert.match(
        dialogue,
        /_poserLeGesteDeSuite\(id\) \{[\s\S]*?document\.createElement\('button'\)/,
        "toggleLoading() réécrit le texte du bouton d'enregistrement à chaque bascule : "
        + 'un libellé posé dessus serait effacé au premier chargement venu.',
    );

    assert.match(
        dialogue,
        /this\.submitButtonTarget\.classList\.add\('d-none'\)/,
        "Le bouton « Enregistrer » doit s'effacer : deux boutons primaires côte à côte ne "
        + 'diraient plus lequel termine la tâche.',
    );
});

test('rien ne se passe si le canevas ne déclare aucune suite', () => {
    assert.match(
        dialogue,
        /const suite = this\.entityFormCanvas\?\.parametres\?\.action_apres_creation;\s*\n\s*if \(!suite/,
        'Une rubrique qui ne déclare pas de suite ne doit pas changer d\'un pixel.',
    );
});

test("l'identité du dialogue voyage avec l'enregistrement", () => {
    assert.match(
        dialogue,
        /notifyCerveau\('app:entity\.saved', \{[\s\S]*?dialogId: this\.dialogId/,
        'Sans dialogId, `doClose` ne peut obéir : il n\'exécute qu\'un ordre qui nomme sa boîte.',
    );
});

test('le retour à la boîte de décision laisse voir le message de succès', () => {
    assert.match(
        cerveau,
        /_retournerALaDecisionApresCorrection\(payload\) \{[\s\S]*?window\.setTimeout\(/,
        'Refermer dans la même milliseconde que le succès ferait douter que '
        + "l'enregistrement ait eu lieu.",
    );

    assert.match(
        cerveau,
        /_retournerALaDecisionApresCorrection\(payload\) \{\s*\n\s*if \(!this\.congeRetourApresEdition/,
        "La fermeture automatique ne vaut que pour une correction lancée DEPUIS la boîte "
        + 'de décision : ailleurs, refermer la fiche de quelqu\'un serait une surprise.',
    );
});

/**
 * LA DATE DE FIN SUIT LA DATE DE DÉBUT — et le navigateur ne refait aucun calendrier.
 *
 * Déplacer son départ obligeait à recalculer soi-même le retour, week-ends, jours fériés
 * et régime de travail compris. Le serveur seul connaît ces trois choses : lui redemander
 * la date coûte un aller-retour, mais garantit que l'écran et le décompte annoncé à
 * l'enregistrement disent la même chose. Refaire le calcul ici donnerait une seconde
 * réponse à « ce jour compte-t-il ? », et les deux finiraient par diverger.
 *
 * ⚠ Les champs de DemandeCongeType se nomment SANS PRÉFIXE (`getBlockPrefix()` vide) :
 * un sélecteur à crochets ne trouverait rien, en silence.
 */
test("conge-periode : la date de fin est demandée au serveur, jamais calculée ici", () => {
    const source = readFileSync(join(RACINE, 'conge-periode_controller.js'), 'utf8');

    assert.match(source, /fetch\(this\.urlValue/, 'La nouvelle fin vient du serveur.');
    assert.doesNotMatch(
        source,
        /getDay\(\)|setDate\(|\+ 86400000/,
        "Aucune arithmétique de calendrier dans le navigateur : c'est le serveur qui sait "
        + 'ce qu\'est un samedi, un férié et le régime de chacun.',
    );
});

test("conge-periode : les champs sont visés sans préfixe", () => {
    const source = readFileSync(join(RACINE, 'conge-periode_controller.js'), 'utf8');

    assert.match(source, /\[name="dateDebut"\]/);
    assert.match(source, /\[name="dateFin"\]/);
    assert.doesNotMatch(
        source,
        /name\^?=?"[a-z_]+\[dateDebut\]"/,
        'DemandeCongeType rend un prefixe vide : un sélecteur à crochets échoue en silence.',
    );
});

test("conge-periode : la durée conservée est celle d'AVANT le geste", () => {
    const source = readFileSync(join(RACINE, 'conge-periode_controller.js'), 'utf8');

    assert.match(
        source,
        /ancienDebut: this\.ancienDebut[\s\S]*?ancienneFin: this\.ancienneFin/,
        "Au moment du changement, le champ porte DÉJÀ la nouvelle valeur : la période "
        + "d'avant doit être mémorisée, pas relue.",
    );
});
