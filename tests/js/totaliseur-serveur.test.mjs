/**
 * LE TOTALISEUR N'ADDITIONNE NI NE FORMATE PLUS RIEN (chantier 4 — décision de Serge : « le
 * chiffre est calculé et formaté par le serveur, plus par le navigateur »).
 *
 * La barre des totaux montre les textes du serveur : total de la page (rendu avec la page) et
 * total de la sélection (demandé par le Cerveau avec la requête de la liste). Ce garde-fou
 * refuse le retour d'une somme ou d'un formatage dans le navigateur.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../assets/controllers/list-summary_controller.js', import.meta.url), 'utf8');

test('list-summary ne formate aucun nombre (Intl, toFixed, toLocaleString)', () => {
    assert.doesNotMatch(source, /Intl\.|toFixed\(|toLocaleString\(/);
});

test('list-summary n’additionne aucune valeur', () => {
    assert.doesNotMatch(source, /\.reduce\(|\+=\s*\w+\[/);
});

test('le total de la sélection vient du serveur, par le Cerveau', () => {
    assert.match(source, /app:totaux-selection\.ready/);
    const cerveau = readFileSync(new URL('../../assets/controllers/cerveau_controller.js', import.meta.url), 'utf8');
    assert.match(cerveau, /totauxDeLaSelection: ids/);
});
