/**
 * OUVRIR UNE PISTE AU CLIENT SÉLECTIONNÉ — et survivre au rechargement.
 *
 * ── LE PIÈGE QUE CE CONTRAT FERME ───────────────────────────────────────────────────
 * Le préremplissage d'une piste depuis un client se demande par `?idClient=`. Il serait
 * tentant de le cuire dans l'URL du formulaire, une fois pour toutes.
 *
 * Ce serait une panne muette. Après l'enregistrement, le dialogue se recharge EN ÉDITION
 * et le cerveau concatène `/${id}` à l'URL : une URL pré-cuisinée donnerait
 * « get-form?idClient=X/{id} » — l'identifiant APRÈS la query. La route retomberait en
 * mode création, et les collections de la piste (cotations, tâches, documents)
 * disparaîtraient sans le moindre message. Le piège a déjà été payé sur la piste dérivée.
 *
 * Le paramètre passe donc par le CONTEXTE du dialogue, que le cerveau ajoute proprement
 * en query après le `/id`.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const cerveau = readFileSync(join(RACINE, 'cerveau_controller.js'), 'utf8');

test('le cerveau route l\'action de la rubrique Clients', () => {
    assert.match(
        cerveau,
        /case 'ui:client\.creer-piste':\s*\n\s*this\.handleClientCreerPiste\(payload\);/,
        'Sans ce `case`, le bouton serait inerte : la barre d\'outils diffuse, le cerveau décide.',
    );
});

test('idClient voyage par le CONTEXTE, jamais cuit dans l\'URL du formulaire', () => {
    const debut = cerveau.indexOf('async handleClientCreerPiste(payload) {');
    assert.ok(debut >= 0, 'Le handler doit exister.');
    const handler = cerveau.slice(debut, debut + 2000);

    assert.match(handler, /context: \{[\s\S]*?idClient: clientId/,
        '⚠ Cuire `?idClient=` dans endpoint_form_url ferait « get-form?idClient=X/{id} » au '
        + 'rechargement en édition : la route repasserait en création et les collections '
        + 'de la piste disparaîtraient, sans un mot.');

    assert.ok(
        !/endpoint_form_url/.test(handler),
        'Le handler ne réécrit pas l\'URL du formulaire : il ne fait que poser le contexte.',
    );
    assert.match(handler, /isCreationMode: true/);
});

test('le contexte idClient est bien propagé en query au get-form', () => {
    assert.match(
        cerveau,
        /if \(context\.idClient\) \{\s*\n\s*url\.searchParams\.set\('idClient', context\.idClient\);/,
        'La liste des clés de contexte propagées est FERMÉE : sans cette ligne, le contexte '
        + 'serait posé puis silencieusement ignoré, et le formulaire s\'ouvrirait vide.',
    );
});

test('le préremplissage n\'est pas réécrit côté navigateur', () => {
    const debut = cerveau.indexOf('async handleClientCreerPiste(payload) {');
    const handler = cerveau.slice(debut, debut + 2000);

    assert.ok(
        !/setNom|typeAvenant|exercice/i.test(handler),
        'La règle de préremplissage vit dans PisteController, partagée avec le relevé de '
        + 'compte. En porter une seconde ici les ferait diverger.',
    );
});
