/**
 * Tests de LA FRONTIÈRE DES TÂCHES À LA RESTAURATION DES ONGLETS (chantier 15 × TacheVisibiliteScope).
 *
 * Après un F5, un onglet se recharge par l'une de deux voies seulement
 * (workspace-manager_controller.js) :
 *
 *   — une RUBRIQUE (componentName + entityName) : elle redemande sa liste au serveur,
 *     et la liste des Tâches passe par le moteur de recherche, qui pose la frontière.
 *     Une tâche devenue invisible n'y figure simplement plus ;
 *   — un APERÇU, par son `loadUrl` : note, bordereau, relevé de compte. Aucun ne montre
 *     une tâche.
 *
 * Ce qui est protégé ici : qu'aucun `loadUrl` ne vise un jour une fiche de tâche (le serveur
 * répondrait 404 à qui ne la voit pas : il faudrait que l'onglet se ferme proprement), et que
 * l'échec d'un rechargement se dise dans le panneau, sans exception.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DOSSIER = fileURLToPath(new URL('../../assets/controllers/', import.meta.url));

function sources() {
    return readdirSync(DOSSIER)
        .filter((f) => f.endsWith('.js'))
        .map((f) => ({ fichier: f, texte: readFileSync(join(DOSSIER,f), 'utf8') }));
}

test('chaque loadUrl persisté vise un aperçu connu, jamais une fiche de tâche', () => {
    const autorises = [/^\/admin\/note\/workspace-apercu\//, /^\/admin\/bordereau\/workspace-apercu\//];
    const trouves = [];
    for (const { fichier, texte } of sources()) {
        for (const m of texte.matchAll(/loadUrl:\s*([^,\n}]+)/g)) {
            trouves.push({ fichier, valeur: m[1].trim() });
        }
    }
    assert.ok(trouves.length >= 4, 'préalable : les producteurs de loadUrl sont bien lus');
    for (const { fichier, valeur } of trouves) {
        assert.doesNotMatch(valeur, /tache|feedback|document/i, `${fichier} : ${valeur}`);
        const litteral = valeur.match(/^`([^`$]*)/);
        if (litteral) {
            assert.ok(autorises.some((r) => r.test(litteral[1])), `${fichier} : aperçu inattendu ${valeur}`);
        } else {
            // Seule URL non littérale : le relevé de compte, reçu du serveur (payload.url).
            assert.ok(['payload.url', 'loadUrl'].includes(valeur), `${fichier} : loadUrl non littéral ${valeur}`);
        }
    }
});

test("un rechargement refusé (404) s'affiche dans le panneau, sans exception", () => {
    const texte = readFileSync(join(DOSSIER,'workspace-manager_controller.js'), 'utf8');
    const corps = texte.slice(texte.indexOf('async _loadTabFromUrl('), texte.indexOf('async _loadTabFromUrl(') + 1500);
    assert.match(corps, /if \(!response\.ok\) throw/);
    assert.match(corps, /catch \(e\)[\s\S]*Impossible de recharger l'onglet/);
});
