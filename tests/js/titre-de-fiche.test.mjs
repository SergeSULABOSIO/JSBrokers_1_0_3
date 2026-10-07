/**
 * LE TITRE D'UN DIALOGUE NOMME LA FICHE, PAS SON NUMÉRO.
 *
 * « Modification du Client #118 » ne dit rien à celui qui ouvre la fiche : il connaît
 * Marlette SULA EKUMBO, pas le 118. Les gabarits de titre viennent de plusieurs sources
 * (canevas serveur, widgets de collection, tableau de bord) et portent leur identifiant
 * sous plusieurs formes ; une seule règle les traite toutes (selecto.js#titreDeFiche).
 *
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { libelleDeFiche, nomAffiche, titreDeFiche } from '../../assets/controllers/selecto.js';

test('le jeton d’identifiant cède la place au libellé', () => {
    assert.equal(titreDeFiche('Modification du Client #%id%', 'Marlette SULA EKUMBO'), 'Modification du Client « Marlette SULA EKUMBO »');
    assert.equal(titreDeFiche('Modification de la cotation n°%id%', 'Auto 2026'), 'Modification de la cotation « Auto 2026 »');
    assert.equal(titreDeFiche('Modifier : Contact #%id%', 'Jean Kabila'), 'Modifier : Contact « Jean Kabila »');
});

test('un identifiant déjà substitué par le serveur est remplacé aussi', () => {
    assert.equal(titreDeFiche('Modification de la tâche #42', 'Relancer l’assureur'), 'Modification de la tâche « Relancer l’assureur »');
    assert.equal(titreDeFiche('Modification du feedback #?', ''), 'Modification du feedback');
});

test('sans libellé, le numéro disparaît au lieu de s’afficher', () => {
    assert.equal(titreDeFiche('Modification du Client #%id%', ''), 'Modification du Client');
    assert.equal(titreDeFiche('Demande de congé #%id%', '   '), 'Demande de congé');
});

test('un gabarit sans identifiant est rendu tel quel', () => {
    assert.equal(titreDeFiche('Modifier la piste', 'Peu importe'), 'Modifier la piste');
    assert.equal(titreDeFiche('Paramètres des congés', ''), 'Paramètres des congés');
});

test('le libellé vient de l’étiquette serveur, sinon de l’entité ; le nom affiché ne reste jamais vide', () => {
    assert.equal(libelleDeFiche(' Marlette ', { nom: 'autre' }), 'Marlette');
    assert.equal(libelleDeFiche('', { reference: 'POL-001' }), 'POL-001');
    assert.equal(libelleDeFiche(null, {}), '');
    assert.equal(nomAffiche('', {}, 118), 'Élément #118');
});
