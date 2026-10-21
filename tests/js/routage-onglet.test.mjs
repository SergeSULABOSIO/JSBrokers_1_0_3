/**
 * Tests du ROUTAGE PAR ONGLET (assets/controllers/routage-onglet.js) — chantier 15,
 * défaut 3 et fuite des filtres entre onglets. Plus un CONTRÔLE CROISÉ du code réel.
 * Lancement : node --test tests/js/
 *
 * LA PANNE. Une réponse lente (chargement d'une liste, rafraîchissement) était étiquetée
 * avec l'onglet de rubrique AFFICHÉ À SON ARRIVÉE, pas avec celui qui l'avait demandée.
 * Avenants charge, l'utilisateur ouvre Tranches, la réponse d'Avenants arrive : ses
 * critères (« Échus ») partaient sous l'identifiant de Tranches. Le workspace-manager les
 * mémorisait sur Tranches — filtre mémorisé ≠ filtre envoyé — et le F5 les y rejouait.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import {
    LISTE_PRINCIPALE,
    ongletProprietaire,
    publicationApresInitialisation,
    cibleDeRecherche,
} from '../../assets/controllers/routage-onglet.js';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const source = (nom) => readFileSync(join(RACINE, 'assets', 'controllers', `${nom}_controller.js`), 'utf8');

/** Le corps d'un `case 'x':` du cerveau, jusqu'au `case` suivant. */
function corpsDuCas(code, type) {
    const debut = code.indexOf(`case '${type}':`);
    assert.ok(debut >= 0, `case '${type}' introuvable`);
    const suite = code.indexOf('\n            case ', debut + 10);
    return code.slice(debut, suite);
}

/** Le corps d'une méthode `    nom(…) {`, jusqu'à la méthode suivante. */
function corpsDeMethode(code, nom) {
    const debut = code.search(new RegExp(`\\n    (async )?${nom}\\(`));
    assert.ok(debut >= 0, `méthode ${nom} introuvable`);
    // On cherche la méthode SUIVANTE à partir de la 2ᵉ ligne : la signature elle-même correspond.
    const ligne2 = code.indexOf('\n', debut + 1);
    const suite = code.slice(ligne2).search(/\n    (async )?_?[a-zA-Z]+\([^)]*\) \{\r?\n/);
    return code.slice(debut, ligne2 + suite);
}

test("la réponse appartient à l'onglet qui l'a DEMANDÉE, pas à celui qu'on regarde", () => {
    assert.equal(ongletProprietaire('ws-avenant', 'ws-tranche'), 'ws-avenant');
    // Sans annonce (ancien émetteur) : l'onglet courant, comme avant.
    assert.equal(ongletProprietaire(null, 'ws-tranche'), 'ws-tranche');
    assert.equal(ongletProprietaire(undefined, undefined), null);
});

test("DÉFAUT 3 : Avenants finit de charger après l'ouverture de Tranches", () => {
    const p = publicationApresInitialisation({
        tabId: LISTE_PRINCIPALE,
        ongletInitialise: 'ws-avenant',
        ongletCourant: 'ws-tranche',
        listeActive: LISTE_PRINCIPALE,
    });
    // Ses critères partent sous SON nom : Tranches ne les mémorise pas.
    assert.equal(p.workspaceTabId, 'ws-avenant');
    // Il n'est pas affiché : la barre de statut (globale) n'est pas touchée…
    assert.equal(p.affiche, false);
    // … mais ses propres barres (outils, totaux, recherche) et sa mémoire sont tenues à jour.
    assert.equal(p.publier, true);
});

test("onglet affiché : seule la liste ACTIVE publie son contexte", () => {
    const base = { ongletInitialise: 'ws-a', ongletCourant: 'ws-a' };
    assert.deepEqual(
        publicationApresInitialisation({ ...base, tabId: LISTE_PRINCIPALE, listeActive: LISTE_PRINCIPALE }),
        { publier: true, affiche: true, workspaceTabId: 'ws-a' },
    );
    assert.equal(publicationApresInitialisation({ ...base, tabId: 'collection-x-for-1', listeActive: LISTE_PRINCIPALE }).publier, false);
    // Ancien émetteur sans onglet annoncé : l'onglet courant, affiché.
    assert.equal(publicationApresInitialisation({ tabId: LISTE_PRINCIPALE, ongletCourant: 'ws-a', listeActive: LISTE_PRINCIPALE }).affiche, true);
});

test("onglet en arrière-plan : sa liste principale publie, une collection jamais", () => {
    const fond = { ongletInitialise: 'ws-a', ongletCourant: 'ws-b', listeActive: 'collection-y-for-2' };
    assert.equal(publicationApresInitialisation({ ...fond, tabId: LISTE_PRINCIPALE }).publier, true);
    assert.equal(publicationApresInitialisation({ ...fond, tabId: 'collection-y-for-2' }).publier, false);
});

test('une recherche va à l’onglet qu’elle NOMME', () => {
    // L'onglet nommé est affiché : la liste active de l'écran.
    assert.deepEqual(cibleDeRecherche('ws-a', 'ws-a', 'collection-x-for-1'), { workspaceTabId: 'ws-a', affiche: true, listeId: 'collection-x-for-1' });
    // L'onglet nommé est en arrière-plan : sa liste principale, jamais la liste active d'un autre.
    assert.deepEqual(cibleDeRecherche('ws-a', 'ws-b', 'collection-x-for-1'), { workspaceTabId: 'ws-a', affiche: false, listeId: LISTE_PRINCIPALE });
    // Rien de nommé : l'onglet courant (saisie à la main).
    assert.deepEqual(cibleDeRecherche(null, 'ws-b', LISTE_PRINCIPALE), { workspaceTabId: 'ws-b', affiche: true, listeId: LISTE_PRINCIPALE });
});

// ── Contrôle croisé : le code réel passe par ce routage ──────────────────────────────

test("cerveau : le chargement d'une liste n'étiquette plus avec l'onglet courant", () => {
    const cas = corpsDuCas(source('cerveau'), 'ui:tab.initialized');
    assert.match(cas, /publicationApresInitialisation\(/);
    assert.doesNotMatch(cas, /workspaceTabId:\s*this\.currentWorkspaceTabId/);
});

test('cerveau : la réponse d’un rafraîchissement porte l’onglet capturé à la DEMANDE', () => {
    const corps = corpsDeMethode(source('cerveau'), '_requestListRefresh');
    // Après le fetch, l'onglet courant ne sert plus d'ÉTIQUETTE (il a pu changer) — seulement
    // de comparaison, pour dire si la réponse concerne l'écran.
    const apresFetch = corps.slice(corps.indexOf('fetch(url'));
    assert.doesNotMatch(apresFetch, /workspaceTabId:\s*this\.currentWorkspaceTabId/);
    assert.doesNotMatch(apresFetch, /tabId:\s*this\.activeTabId/);
});

test('cerveau : une recherche soumise est routée par son onglet', () => {
    const code = source('cerveau');
    assert.match(corpsDuCas(code, 'ui:search.submitted'), /payload\.workspaceTabId/);
    assert.match(corpsDeMethode(code, '_handleSearchRequest'), /cibleDeRecherche\(/);
});

test('les émetteurs de recherche nomment leur onglet', () => {
    assert.match(corpsDeMethode(source('workspace-manager'), '_appliquerCriteresEnAttente'), /'ui:search\.submitted',\s*\{[^}]*workspaceTabId/);
    for (const nom of ['search-bar', 'search-bar-dialog']) {
        const emissions = source(nom).match(/'ui:search\.submitted',[^\n]*/g) || [];
        assert.ok(emissions.length > 0, nom);
        for (const e of emissions) assert.match(e, /workspaceTabId/, `${nom} : ${e}`);
    }
});

// ── Les publications d'un onglet en ARRIÈRE-PLAN ne touchent pas l'écran (revue du chantier) ──

test('cerveau : une liste rendue en arrière-plan ne réécrit pas la barre de statut de l’onglet affiché', () => {
    const cas = corpsDuCas(source('cerveau'), 'app:list.rendered');
    assert.match(cas, /enArrierePlan\s*=.*payload\.workspaceTabId !== this\.currentWorkspaceTabId/);
    assert.match(cas, /if \(!enArrierePlan\) \{[\s\S]*?_publishSelectionStatus\(\)/);
    // La liste dit QUELLE liste elle est (principale ou collection).
    assert.match(source('list-manager'), /'app:list\.rendered', \{[\s\S]*?tabId:/);
});

test('cerveau : les contextes publiés pour un onglet en arrière-plan le disent ; le menu contextuel les ignore', () => {
    const code = source('cerveau');
    assert.match(corpsDuCas(code, 'ui:tab.initialized'), /arrierePlan: !publication\.affiche/);
    assert.match(corpsDeMethode(code, '_requestListRefresh'), /arrierePlan: workspaceTabId !== this\.currentWorkspaceTabId/);
    assert.match(corpsDeMethode(source('context-menu'), 'handleContextUpdate'), /if \(event\.detail\?\.arrierePlan\) return;/);
});

test('workspace-manager : le filtre restauré resté en attente est reposé au retour sur un onglet PRÊT', () => {
    const code = source('workspace-manager');
    assert.match(corpsDeMethode(code, 'handleTabStateReady'), /_etatsPrets \?\?= new Set\(\)\)\.add\(tabId\)/);
    const activation = corpsDeMethode(code, '_activateWorkspaceTabById');
    assert.match(activation, /if \(this\._etatsPrets\?\.has\(tabId\)\) this\._appliquerCriteresEnAttente\(tabId\);/);
    // Un composant rechargé repart sans état : il redevient « pas prêt ».
    assert.match(activation, /this\._etatsPrets\?\.delete\(tabId\);[\s\S]*?'ui:component\.load'/);
});
