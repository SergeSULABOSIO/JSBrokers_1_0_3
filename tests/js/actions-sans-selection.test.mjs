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

// LA RÈGLE N'EST PLUS ÉCRITE QU'UNE FOIS (actions-groupees.js#actionsVisibles) : les deux
// surfaces la recopiaient à l'identique. On vérifie donc la règle à sa source, et que
// chaque surface y passe bien — une copie locale réintroduirait la divergence.
const PARTAGE = readFileSync(join(RACINE, 'actions-groupees.js'), 'utf8');

test('la règle partagée : le drapeau sans_selection court-circuite le décompte', () => {
    assert.match(
        PARTAGE,
        /if \(action\.sans_selection === true\) return true;/,
        'Sans ce court-circuit, l\'action retombe sur la règle du décompte et reste '
        + 'invisible tant qu\'aucune ligne n\'est cochée.',
    );
});

for (const [nom, source] of Object.entries(CONTROLEURS)) {
    test(`${nom} : les actions visibles viennent de la règle partagée`, () => {
        assert.match(source, /actionsVisibles\(/);
        assert.doesNotMatch(source, /action\.sans_selection/, 'aucune copie locale de la règle');
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

/**
 * LE MENU D'UNE FAMILLE SE POSE AVEC LA GÉOMÉTRIE PARTAGÉE.
 *
 * Il se posait en `absolute` dans la barre, avec une règle CSS qui le rabattait à droite
 * dès qu'il était le DERNIER bouton — au motif qu'un dernier bouton est en fin de barre.
 * C'est faux dès que la barre en porte peu : le bouton est alors à gauche, le menu part
 * vers l'arrière et sort du panneau, qui le rogne. On lisait « …pteurs de congés ».
 *
 * `positionnerMenu` est la même géométrie que le menu de bulle, le chip-sélecteur et le
 * menu contextuel : elle bascule au-dessus s'il n'y a pas la place dessous, et écrête aux
 * bords du viewport. Aucun menu ne peut plus sortir de l'écran.
 */
test("barre d’outils : le menu de famille est posé par la géométrie partagée", () => {
    // Le rendu des familles vit désormais dans le module PARTAGÉ par la barre du
    // workspace et celle du dialogue d'entité : c'est là que la règle doit tenir.
    const source = readFileSync(join(RACINE, 'barre-actions.js'), 'utf8');

    assert.match(
        source,
        /import \{ positionnerMenu \} from '\.\/menu-flottant\.js';/,
        'La géométrie des menus flottants est partagée : la réécrire ici la ferait diverger.',
    );

    assert.match(
        source,
        /_positionnerMenu\(button, menu\) \{[\s\S]*?alignement: 'gauche',/,
        "Le menu s'ouvre du côté où le bouton commence, dans le sens du geste.",
    );
});
