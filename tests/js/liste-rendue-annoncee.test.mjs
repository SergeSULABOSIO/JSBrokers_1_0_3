/**
 * LA LISTE RENDUE PAR LE SERVEUR DOIT S'ANNONCER, ELLE AUSSI.
 *
 * ── L'INCIDENT QUI L'A FAIT NAÎTRE ──────────────────────────────────────────────────
 * La sélection ne survivait toujours pas au rechargement — après un premier correctif qui
 * avait pourtant rétabli le bon événement et le bon ordre. Le reste du circuit était juste ;
 * il manquait un SIGNAL, et il manquait au seul endroit qu'on ne regardait pas.
 *
 * `app:list.rendered` n'était émis que depuis `handleListRefreshed()`, c'est-à-dire quand
 * le cerveau POUSSE de nouvelles lignes : une recherche, une pagination, un
 * rafraîchissement. Jamais sur la liste que le serveur avait déjà rendue dans le panneau
 * au chargement de la page.
 *
 * Or c'est ce signal qui déclenche la repose des lignes cochées. Conséquence : la
 * sélection ne revenait que sur les onglets qui portaient AUSSI des filtres — les reposer
 * déclenche une recherche, donc un rafraîchissement, donc l'événement. Sur une rubrique
 * consultée sans filtre, rien ne se produisait jamais.
 *
 * C'est le pire genre de défaut : la moitié qui marche masque l'autre. On vérifie une
 * fois, sur un onglet filtré, on voit la sélection revenir, et l'on conclut que la
 * fonctionnalité est acquise.
 *
 * ── POURQUOI CE TEST EST STATIQUE ───────────────────────────────────────────────────
 * Comme pour `evenements-diffuses`, le défaut ne vit pas dans une fonction mais dans le
 * rendez-vous entre deux chemins d'exécution : chacun est correct isolément. On lit donc
 * la source et l'on vérifie que les DEUX chemins de rendu annoncent.
 *
 * ⚠ Ce test ne prouve pas que la sélection revient. Il prouve que le rendu initial n'est
 * pas muet — la moitié du problème qui ne se signale jamais.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');

const listManager = readFileSync(join(RACINE, 'list-manager_controller.js'), 'utf8');
const workspaceManager = readFileSync(join(RACINE, 'workspace-manager_controller.js'), 'utf8');

/** Le corps d'une méthode, de sa signature à l'accolade fermante de même indentation. */
function corpsDeMethode(source, signature) {
    const debut = source.indexOf(signature);
    assert.notEqual(debut, -1, `Méthode introuvable : ${signature}`);

    const fin = source.indexOf('\n    }', debut);
    assert.notEqual(fin, -1, `Fin de méthode introuvable : ${signature}`);

    return source.slice(debut, fin);
}

test("le rendu initial (serveur) annonce app:list.rendered", () => {
    const corps = corpsDeMethode(listManager, '_initializeAndNotifyState() {');

    assert.match(
        corps,
        /_annoncerLeRendu\(\)/,
        "La liste rendue par le serveur doit s'annoncer : sans cela, la sélection ne revient "
        + "que sur les onglets qui ont AUSSI des filtres, et le défaut passe inaperçu.",
    );
});

test("le rafraîchissement annonce app:list.rendered", () => {
    const corps = corpsDeMethode(listManager, '_postDataLoadActions() {');

    assert.match(corps, /_annoncerLeRendu\(\)/);
});

test("l'annonce passe par une seule et même méthode", () => {
    const occurrences = listManager.match(/notifyCerveau\('app:list\.rendered'/g) || [];

    assert.equal(
        occurrences.length,
        1,
        "Un seul endroit doit prononcer app:list.rendered : deux formulations finissent par "
        + 'diverger sur les compteurs qu\'elles transportent.',
    );
});

test("l'annonce initiale est réservée à la liste PRINCIPALE", () => {
    const corps = corpsDeMethode(listManager, '_initializeAndNotifyState() {');

    assert.match(
        corps,
        /if \(isPrincipalTab\) \{\s*\n\s*this\._annoncerLeRendu\(\);/,
        "Une collection contextuelle partage le workspaceTabId de sa rubrique : la laisser "
        + 'annoncer ferait cocher ses lignes avec les identifiants de la liste voisine.',
    );
});

test("le rendu à ignorer est noté à la restauration, pas déduit de l'état des critères", () => {
    assert.match(
        workspaceManager,
        /_premierRenduAIgnorer/,
        'La garde doit être posée explicitement à la restauration.',
    );

    const corps = corpsDeMethode(workspaceManager, 'reposerSelectionDOnglet(event) {');

    assert.match(
        corps,
        /this\._premierRenduAIgnorer\?\.\[tabId\]/,
        "La garde ne doit plus lire `_criteresEnAttente` : celui-ci est vidé au moment où les "
        + "filtres sont posés, dans la même pile d'appels que l'annonce du rendu initial — "
        + "elle ne voyait donc plus rien à attendre.",
    );

});
