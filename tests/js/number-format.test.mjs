import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatMontant } from '../../assets/number-format.js';

// Miroir de ServiceNombresTest : aucun « $ » par défaut (chantier 5). Un montant du cabinet
// est dans SA monnaie de travail ; seuls les prix des tokens sont en dollars, et le disent.
test('sans symbole demandé, le nombre seul', () => {
    assert.equal(formatMontant(10, 2, 'fr'), '10,00');
    assert.equal(formatMontant(1234.5, 2, 'en'), '1,234.50');
});

test('le symbole demandé change de côté avec la langue', () => {
    assert.equal(formatMontant(10, 2, 'fr', '$'), '10,00 $');
    assert.equal(formatMontant(10, 2, 'en', '$'), '$10.00');
});

test("une valeur qui n'est pas un nombre ne rend rien", () => {
    assert.equal(formatMontant('abc', 2, 'fr', '$'), '');
});
