/**
 * LE CONTRAT DES ÉVÉNEMENTS `app:` — tout ce qui s'écoute doit s'émettre.
 *
 * ── L'INCIDENT QUI L'A FAIT NAÎTRE ──────────────────────────────────────────────────
 * La sélection des lignes ne survivait pas au rechargement, alors que la logique était
 * juste et couverte par ses propres tests. Le défaut était AILLEURS, dans le câblage :
 *
 *     list-manager   : this.notifyCerveau('app:list.rendered', …)
 *     workspace-mgr  : document.addEventListener('app:list.rendered', …)
 *
 * Les deux lignes se lisent comme un couple. Elles n'en forment pas un : `notifyCerveau()`
 * n'émet PAS un événement de ce nom — il émet un `cerveau:event` qui porte le nom dans son
 * `detail.type`. L'écouteur n'a donc jamais été appelé. Aucune erreur, aucun avertissement,
 * rien dans la console : une fonctionnalité entière, silencieusement inerte.
 *
 * ── CE QUE CE TEST VÉRIFIE, ET POURQUOI IL EST STATIQUE ─────────────────────────────
 * Ce défaut ne se voit pas dans un test unitaire : chaque moitié fonctionne parfaitement
 * seule. Il ne se voit que dans le RENDEZ-VOUS entre deux fichiers — exactement ce qu'un
 * test statique sait lire. On collecte donc, sur tout `assets/` :
 *
 *   — les noms `app:…` ÉCOUTÉS par `addEventListener` ;
 *   — les noms `app:…` ÉMIS, par `broadcast()` ou par `new CustomEvent()`.
 *
 * Un nom écouté que personne n'émet est soit du code mort, soit un câblage rompu. Les deux
 * méritent d'être vus, et aucun des deux ne se signale à l'exécution.
 *
 * ⚠ CE TEST NE PROUVE PAS QUE LE CÂBLAGE MARCHE. Il prouve qu'aucun écouteur n'attend un
 * événement que personne ne prononce. C'est la moitié du problème — celle qui est muette.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets');

/** Tous les fichiers JS d'un dossier, sous-dossiers compris. */
function fichiersJs(dossier) {
    return readdirSync(dossier, { withFileTypes: true }).flatMap((entree) => {
        const chemin = join(dossier, entree.name);

        if (entree.isDirectory()) return fichiersJs(chemin);

        return chemin.endsWith('.js') ? [chemin] : [];
    });
}

/** @returns {{ecoutes: Map<string, string[]>, emis: Set<string>}} */
function releverLesEvenements() {
    const ecoutes = new Map();
    const emis = new Set();

    for (const chemin of fichiersJs(RACINE)) {
        const source = readFileSync(chemin, 'utf8');
        const nom = chemin.slice(RACINE.length + 1);

        for (const [, evenement] of source.matchAll(/addEventListener\(\s*'(app:[\w.-]+)'/g)) {
            if (!ecoutes.has(evenement)) ecoutes.set(evenement, []);
            ecoutes.get(evenement).push(nom);
        }

        // LES DEUX FAÇONS D'ÉMETTRE. `broadcast()` est celle du cerveau ; un contrôleur
        // ordinaire construit son `CustomEvent` lui-même. Les deux produisent un vrai
        // événement du DOM, et c'est tout ce qui compte ici.
        for (const [, evenement] of source.matchAll(/broadcast\(\s*'(app:[\w.-]+)'/g)) emis.add(evenement);
        for (const [, evenement] of source.matchAll(/CustomEvent\(\s*'(app:[\w.-]+)'/g)) emis.add(evenement);
    }

    return { ecoutes, emis };
}

test('aucun écouteur n’attend un événement que personne n’émet', () => {
    const { ecoutes, emis } = releverLesEvenements();

    const orphelins = [...ecoutes.entries()]
        .filter(([evenement]) => !emis.has(evenement))
        .map(([evenement, fichiers]) => `${evenement} (écouté par ${fichiers.join(', ')})`);

    assert.deepEqual(
        orphelins,
        [],
        'Un écouteur sans émetteur est inerte, et il ne le dit pas. Soit le nom est faux — '
        + '`notifyCerveau()` n’émet pas un événement de ce nom, il émet un `cerveau:event` —, '
        + 'soit l’écouteur est mort et doit partir.',
    );
});

test('le rendu de liste est bien DIFFUSÉ, et pas seulement notifié au cerveau', () => {
    // La régression précise du 2026-08-30 : c'est de cet événement que dépend la survie de
    // la sélection au rechargement. Le test général au-dessus le couvrirait, mais celui-ci
    // NOMME le cas, pour que sa disparition raconte ce qui casse.
    const { ecoutes, emis } = releverLesEvenements();

    assert.ok(ecoutes.has('app:list.rendered'), 'Quelqu’un attend le rendu des lignes.');
    assert.ok(
        emis.has('app:list.rendered'),
        'Le cerveau doit le RE-DIFFUSER : `notifyCerveau()` seul ne réveille aucun écouteur.',
    );
});
