/**
 * UN BOUTON DÉSACTIVÉ DE LA BARRE D'OUTILS DIT POURQUOI (chantier 20, accepté par Serge le
 * 2026-10-24).
 *
 * La barre des listes posait `disabled` : un bouton désactivé ne prend ni le survol ni le
 * focus, et `.is-disabled` coupait les gestes du pointeur — la raison du refus (son `title`,
 * lu par l'infobulle unique) ne s'affichait jamais. Pire : réactivé, le bouton perdait son
 * `title`, donc son libellé (« Ajouter ») disparaissait de l'infobulle.
 *
 * Comme la barre des dialogues (barre-actions.js) : `aria-disabled`, libellé conservé et raison
 * ajoutée (« Ajouter — … »), clic et clavier bloqués par le code, bouton survolable.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const lire = (chemin) => readFileSync(new URL(`../../${chemin}`, import.meta.url), 'utf8');
const barre = lire('assets/controllers/toolbar_controller.js');
const menu = lire('assets/controllers/context-menu_controller.js');
const css = lire('assets/styles/app.css');

test('la barre ne pose jamais `disabled` (le bouton resterait muet au survol et au clavier)', () => {
    assert.doesNotMatch(barre, /\.disabled\s*=/);
});

test('le libellé est gardé, la raison ajoutée — jamais le title effacé', () => {
    assert.doesNotMatch(barre, /removeAttribute\('title'\)/);
    assert.match(barre, /`\$\{libelle\} — \$\{raison\}`/);
});

test('un bouton désactivé ne joue pas : la barre et le menu des listes le refusent', () => {
    assert.match(barre, /notify\(event\) \{[\s\S]{0,400}aria-disabled[\s\S]{0,80}return;/);
    assert.match(menu, /notify\(event\) \{[\s\S]{0,400}aria-disabled[\s\S]{0,80}return;/);
});

test('`.is-disabled` ne coupe plus les gestes du pointeur (sinon pas d’infobulle)', () => {
    const regle = css.match(/\.btn\.is-disabled,[\s\S]*?\{([^}]*)\}/);
    assert.ok(regle, 'règle .btn.is-disabled introuvable');
    assert.doesNotMatch(regle[1], /pointer-events:\s*none/);
});

test('le menu des listes dit le motif DE LA RUBRIQUE (creation_interdite_message), pas un texte figé', () => {
    assert.match(menu, /creation_interdite_message/);
    assert.doesNotMatch(menu, /Une condition de partage se crée depuis/);
});

test('survolé, un bouton inactif ne prend pas l’air actif ; il ne fait pas d’onde au clic', () => {
    assert.match(css, /\.context-toolbar \.btn-default:not\(\[aria-disabled="true"\]\):hover \{/);
    assert.match(css, /\.context-menu \[role="menuitem"\]:not\(\[aria-disabled="true"\]\):hover/);
    const onde = lire('assets/controllers/ripple_controller.js');
    assert.match(onde, /aria-disabled[\s\S]{0,40}return;/);
});
