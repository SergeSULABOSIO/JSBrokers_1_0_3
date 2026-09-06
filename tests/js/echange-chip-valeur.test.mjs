/**
 * LE PIÈGE DU PARAMÈTRE STIMULUS QUI RESSEMBLE À UN NOMBRE.
 *
 * ── L'INCIDENT ──────────────────────────────────────────────────────────────────────
 * Le chip d'exercice ne se sélectionnait pas. Aucune erreur en console, aucun échec de
 * test : le clic partait, la méthode s'exécutait, et aucun chip ne s'allumait.
 *
 * Cause : Stimulus ANALYSE SES PARAMÈTRES EN JSON. `data-echange-exercice-param="2026"`
 * arrive donc dans `event.params.exercice` sous forme de NOMBRE, alors que
 * `element.dataset.echangeExerciceParam` rend toujours une CHAÎNE. La comparaison stricte
 * `"2026" === 2026` est fausse, et la boucle n'active rien.
 *
 * ⚠ CE QUI RENDAIT LE DÉFAUT DIFFICILE À VOIR : le chip de validité, écrit le même jour
 * avec le même code, fonctionnait parfaitement — ses valeurs (« toutes », « souscrites »)
 * ne ressemblent pas à des nombres. Seules les années tombaient.
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

/**
 * La règle, isolée : ce qu'on compare doit être une chaîne des DEUX côtés.
 *
 * C'est la ligne exacte du contrôleur — `String(event.params.x ?? '')` — sortie de son
 * contexte pour être éprouvée sans DOM ni Stimulus.
 */
const valeurDuChip = (parametre) => String(parametre ?? '');

test('un paramètre numérique redevient comparable au dataset', () => {
    // Ce que Stimulus livre pour data-…-param="2026".
    const depuisStimulus = 2026;
    // Ce que le DOM rend pour le même attribut.
    const depuisDataset = '2026';

    assert.notEqual(depuisDataset, depuisStimulus, 'Le piège existe bien : les deux types diffèrent.');
    assert.equal(valeurDuChip(depuisStimulus), depuisDataset, 'Après normalisation, ils coïncident.');
});

test('une valeur textuelle traverse sans dommage', () => {
    assert.equal(valeurDuChip('souscrites'), 'souscrites');
    assert.equal(valeurDuChip('tous'), 'tous');
});

/**
 * ⚠ UN PARAMÈTRE ABSENT NE DOIT PAS DEVENIR LA CHAÎNE « undefined » — elle ne
 * correspondrait à aucun chip, et le clic serait avalé sans que rien ne le dise.
 */
test('un paramètre absent rend une chaîne vide, jamais « undefined »', () => {
    assert.equal(valeurDuChip(undefined), '');
    assert.equal(valeurDuChip(null), '');
});

/**
 * ⚠ ZÉRO EST UNE VALEUR, PAS UNE ABSENCE. Une garde écrite `if (!choisi) return;`
 * rejetterait un chip dont la valeur est 0 — le genre de garde qu'on écrit par réflexe.
 */
test('la valeur zéro survit à la garde', () => {
    assert.equal(valeurDuChip(0), '0');
    assert.notEqual(valeurDuChip(0), '');
});
