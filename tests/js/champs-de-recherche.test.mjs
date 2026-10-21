/**
 * LE FILET CONTRE LE REMPLISSAGE AUTOMATIQUE NE VIDE QUE CE QUE LE NAVIGATEUR A REMPLI.
 *
 * Chantier 16 (2026-10-20) : Chrome/Edge collaient l'adresse e-mail dans les recherches du
 * tableau de bord. Contrainte de Serge : le filet ne doit JAMAIS effacer une valeur restaurée
 * par l'application (onglets rétablis à l'identique, chantier 15). Il ne réagit donc qu'à
 * l'animation qu'app.css attache à `:autofill` — une valeur posée par le code n'en émet aucune.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { doitEffacer, NOM_ANIMATION } from '../../assets/champs-de-recherche-regles.js';

/** Un champ simulé : `matches` ne répond oui qu'au sélecteur des champs de recherche marqués. */
const champ = ({ marque = true, value = 'ssula@joseara.com' } = {}) => ({
    tagName: 'INPUT',
    value,
    matches: (sel) => sel === 'input[data-champ-recherche]' && marque,
});

test('le remplissage du NAVIGATEUR (animation d\'`:autofill`) est vidé', () => {
    assert.equal(doitEffacer({ nomAnimation: NOM_ANIMATION, cible: champ() }), true);
});

test('une valeur posée par l\'APPLICATION survit : sans animation d\'`:autofill`, rien ne se passe', () => {
    // Aucune animation n'est émise pour une valeur posée par le code : le filet n'est même pas appelé.
    // Et toute AUTRE animation (onglet qui se déplie, compteur…) ne vide jamais un champ.
    assert.equal(doitEffacer({ nomAnimation: 'jsb-onglet-deplie', cible: champ({ value: 'Relancer' }) }), false);
    assert.equal(doitEffacer({ nomAnimation: 'jsb-compte-rebond', cible: champ({ value: 'Relancer' }) }), false);
});

test('un champ NON marqué (vrai identifiant de connexion) garde son remplissage', () => {
    assert.equal(doitEffacer({ nomAnimation: NOM_ANIMATION, cible: champ({ marque: false }) }), false);
});

test('un champ vide, ou une cible sans `matches`, ne déclenche rien', () => {
    assert.equal(doitEffacer({ nomAnimation: NOM_ANIMATION, cible: champ({ value: '' }) }), false);
    assert.equal(doitEffacer({ nomAnimation: NOM_ANIMATION, cible: null }), false);
    assert.equal(doitEffacer({ nomAnimation: NOM_ANIMATION, cible: {} }), false);
});

test('app.css attache bien CETTE animation à `:autofill` et `:-webkit-autofill` des champs marqués', () => {
    const css = readFileSync(new URL('../../assets/styles/app.css', import.meta.url), 'utf8');
    assert.match(css, new RegExp(`@keyframes ${NOM_ANIMATION}\\b`));
    assert.match(css, new RegExp(`input\\[data-champ-recherche\\]:-webkit-autofill\\s*\\{[^}]*${NOM_ANIMATION}`));
    assert.match(css, new RegExp(`input\\[data-champ-recherche\\]:autofill\\s*\\{[^}]*${NOM_ANIMATION}`));
});

test('le filet est installé par app.js', () => {
    const app = readFileSync(new URL('../../assets/app.js', import.meta.url), 'utf8');
    assert.match(app, /import '\.\/champs-de-recherche\.js';/);
});
