/**
 * UNE FICHE MODIFIÉE PAR UNE ACTION NE RESTE PAS PÉRIMÉE DANS SON DIALOGUE.
 *
 * ── LE DÉFAUT QUI L'A FAIT NAÎTRE ───────────────────────────────────────────────────
 * « Retirer du portefeuille », lancé depuis la fiche d'un client, s'appliquait en base —
 * mais le dialogue gardait l'ancien portefeuille dans son formulaire, et l'« Enregistrer »
 * suivant le réécrivait. Une perte de données silencieuse : aucune action ne prévenait
 * le dialogue qu'elle venait de changer la fiche qu'il affiche.
 *
 * ── CE QUE CE TEST TIENT ────────────────────────────────────────────────────────────
 * 1. Chaque branche de SUCCÈS d'une action qui écrit annonce la fiche modifiée
 *    (`_annoncerFicheModifiee`). Une nouvelle action qui l'oublierait doit rejoindre
 *    cette liste — et ce test la rappellera à l'ordre.
 * 2. Le dialogue écoute l'annonce, se recharge si sa saisie est intacte, et sinon
 *    AVERTIT sans rien jeter.
 * 3. Un refus serveur (409, fiche périmée) est traité comme tel, pas comme une erreur
 *    de champ.
 *
 * La garde serveur (empreinte, 409) est éprouvée par ClientPortefeuilleActionsTest.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');
const cerveau = readFileSync(join(RACINE, 'cerveau_controller.js'), 'utf8').replace(/\r\n/g, '\n');
const dialogue = readFileSync(join(RACINE, 'dialog-instance_controller.js'), 'utf8').replace(/\r\n/g, '\n');

/** Le corps d'une méthode de classe (indentation de 4 espaces). */
const methode = (source, nom) => {
    const debut = source.search(new RegExp(`\\n    (async )?${nom}\\(`));
    assert.ok(debut >= 0, `méthode ${nom} introuvable`);
    const fin = source.indexOf('\n    }\n', debut);
    return source.slice(debut, fin);
};

/** Le bloc d'un `case` du grand aiguillage du cerveau. */
const casDe = (type) => {
    const debut = cerveau.indexOf(`case '${type}'`);
    assert.ok(debut >= 0, `case ${type} introuvable`);
    const suivant = cerveau.indexOf("\n            case '", debut + 1);
    return cerveau.slice(debut, suivant);
};

const HANDLERS_DE_SUCCES = [
    '_handleClientPortefeuilleDetach',
    '_handlePartageDetachExecute',
    '_handleSoaRevokeExecute',
    '_handleAvenantMouvementEnregistre',
    '_handleCongeDecisionEnregistree',
    '_handleAvenantNonRenouvelableEnregistre',
    '_handleNoteFacturationEnregistree',
    '_handleRetroAgentReversementEnregistre',
    '_handleDocumentsAttaches',
    'handleSuppressionDossierTerminee',
    '_handleApiDeleteRequest',
];
const CAS_DE_SUCCES = ['client:portefeuille.updated', 'client:partage.updated', 'app:entity.saved'];

for (const nom of HANDLERS_DE_SUCCES) {
    test(`succès « ${nom} » : la fiche modifiée est annoncée`, () => {
        assert.match(methode(cerveau, nom), /this\._annoncerFicheModifiee\(/);
    });
}
for (const type of CAS_DE_SUCCES) {
    test(`succès « ${type} » : la fiche modifiée est annoncée`, () => {
        assert.match(casDe(type), /this\._annoncerFicheModifiee\(/);
    });
}

test('le retrait du portefeuille nomme sa cible au lieu de la deviner', () => {
    assert.match(methode(cerveau, '_handleClientPortefeuilleDetach'),
        /_annoncerFicheModifiee\(\{ entityType: 'Client', id: clientId \}\)/);
});

test('le cerveau retient la fiche visée au départ de l’action', () => {
    assert.match(cerveau, /this\._ficheDeLAction = \{ entityType: designee\.entityType, id: designee\.id \};/);
    assert.match(methode(cerveau, '_annoncerFicheModifiee'), /this\.broadcast\('app:fiche\.modifiee'/);
});

test('le dialogue écoute l’annonce, recharge si intact, avertit sinon', () => {
    assert.match(dialogue, /document\.addEventListener\('app:fiche\.modifiee', this\.boundFicheModifiee\);/);
    assert.match(dialogue, /document\.removeEventListener\('app:fiche\.modifiee', this\.boundFicheModifiee\);/);
    const surModif = methode(dialogue, '_surFicheModifiee');
    assert.match(surModif, /if \(this\._saisieEnCours\(\)\) \{\s*this\._signalerFichePerimee\(/, 'une saisie en cours ne doit jamais être jetée');
    assert.match(surModif, /this\._rechargerLaFiche\(/);
});

test('les conditions se relisent sur l’entité envoyée par le serveur à chaque chargement', () => {
    assert.match(methode(dialogue, '_relireLaFiche'), /dataset\.entite/);
    assert.match(methode(dialogue, 'handleContentReady'), /this\._relireLaFiche\(\);/);
});

test('un refus « fiche périmée » (409) propose de recharger, sans erreur de champ', () => {
    assert.match(methode(dialogue, 'handleFailedSubmit'), /if \(error\?\.conflit\) \{[\s\S]*?this\._signalerFichePerimee\(error\.message\);[\s\S]*?return;/);
});
