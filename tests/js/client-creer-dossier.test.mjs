/**
 * OUVRIR UN DOSSIER AU CLIENT SÉLECTIONNÉ — et survivre au rechargement.
 *
 * Deux gestes, une seule mécanique : « Créer une piste » et « Créer un sinistre » passent
 * par le même handler, et ce fichier verrouille autant leur routage que leur mutualisation.
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

test('le cerveau route les DEUX actions de la rubrique Clients', () => {
    for (const [evenement, libelle] of [
        ['ui:client.creer-piste', 'la piste'],
        ['ui:client.creer-sinistre', 'le sinistre'],
    ]) {
        const motif = new RegExp(
            `case '${evenement.replace('.', '\\.')}':\\s*\\n\\s*`
            + `this\\.handleClientCreerDossier\\(payload, '${libelle}'\\);`,
        );
        assert.match(cerveau, motif,
            `Sans ce \`case\`, « ${evenement} » serait inerte : la barre d'outils diffuse, `
            + 'le cerveau décide.');
    }
});

test('un seul corps sert les deux gestes', () => {
    assert.ok(
        !/async handleClientCreerPiste\(/.test(cerveau) && !/async handleClientCreerSinistre\(/.test(cerveau),
        'Piste et sinistre suivent la même mécanique : deux copies auraient divergé au '
        + 'premier correctif. Un seul handler, paramétré par le mot à dire.',
    );
    assert.equal(
        (cerveau.match(/async handleClientCreerDossier\(/g) || []).length, 1,
        'Le handler mutualisé doit exister une fois, et une seule.',
    );
});

test('idClient voyage par le CONTEXTE, jamais cuit dans l\'URL du formulaire', () => {
    const debut = cerveau.indexOf('async handleClientCreerDossier(payload, libelle) {');
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
    const debut = cerveau.indexOf('async handleClientCreerDossier(payload, libelle) {');
    const handler = cerveau.slice(debut, debut + 2000);

    assert.ok(
        !/setNom|typeAvenant|exercice/i.test(handler),
        'La règle de préremplissage vit dans PisteController et NotificationSinistreController, '
        + 'partagée avec le relevé de compte. En porter une seconde ici les ferait diverger.',
    );
});
