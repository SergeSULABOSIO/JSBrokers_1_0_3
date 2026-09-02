/**
 * UNE ACTION TRANSVERSE S'AFFICHE SANS SÉLECTION — barre d'outils ET menu contextuel.
 *
 * ── LE DÉFAUT QUI L'A FAIT NAÎTRE ───────────────────────────────────────────────────
 * Le calendrier d'équipe et la grille des compteurs étaient déclarés `multi`, dans la
 * croyance que ce drapeau les rendait accessibles sans cocher de ligne. Il n'en dit
 * rien : il signifie « dès UNE ligne, une ou plusieurs ». Les deux écrans restaient donc
 * enfermés derrière une sélection — il fallait cocher une demande au hasard pour ouvrir
 * un calendrier qui ne parle pas d'elle.
 *
 * ── LES DEUX MOITIÉS DOIVENT S'ACCORDER ─────────────────────────────────────────────
 * La barre d'outils et le menu contextuel filtrent les mêmes actions, chacun de son
 * côté. Un drapeau honoré par l'un et ignoré par l'autre donne une application qui se
 * contredit d'un clic droit à l'autre — et rien ne le signale.
 *
 * ── ET LA SÉLECTION VIDE NE DOIT PAS FAIRE TOMBER LE RENDU ──────────────────────────
 * Les deux contrôleurs lisaient `selection[0].id` sans précaution. Une action affichée
 * sans sélection amène précisément ce cas : la lecture lève, et c'est TOUT le rendu de
 * la barre ou du menu qui échoue — pas seulement l'action fautive.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const RACINE = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'assets', 'controllers');

const CONTROLEURS = {
    'barre d’outils': readFileSync(join(RACINE, 'toolbar_controller.js'), 'utf8'),
    'menu contextuel': readFileSync(join(RACINE, 'context-menu_controller.js'), 'utf8'),
};

for (const [nom, source] of Object.entries(CONTROLEURS)) {
    test(`${nom} : le drapeau sans_selection court-circuite le décompte`, () => {
        assert.match(
            source,
            /if \(action\.sans_selection === true\) return true;/,
            'Sans ce court-circuit, l\'action retombe sur la règle du décompte et reste '
            + 'invisible tant qu\'aucune ligne n\'est cochée.',
        );
    });

    test(`${nom} : une sélection vide ne fait pas tomber le rendu`, () => {
        assert.doesNotMatch(
            source,
            /const selectedId = this\.(selectos|entities)\[0\]\.id;/,
            'La lecture doit être protégée (`?.id ?? null`) : une action transverse '
            + 'affichée sans sélection amène exactement ce cas.',
        );

        assert.match(
            source,
            /const selectedId = this\.(selectos|entities)\[0\]\?\.id \?\? null;/,
        );
    });
}
