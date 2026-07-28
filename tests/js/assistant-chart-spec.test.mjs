/**
 * Tests fonctionnels du cœur PUR des graphiques de l'assistant IA
 * (assets/controllers/assistant-chart-spec.js) — aucun DOM, aucun Chart.js.
 * Lancement : node --test tests/js/
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';

import {
    CHART_TYPES,
    normaliserSpec,
    construireConfigChart,
    masquerBlocsChart,
} from '../../assets/controllers/assistant-chart-spec.js';

test('normaliserSpec accepte une spec valide et coerce les nombres', () => {
    const spec = normaliserSpec({
        type: 'bar',
        titre: 'CA 2026',
        unite: '€',
        labels: ['Jan', 'Fév'],
        series: [{ label: 'HT', data: ['1 200,5', 900] }],
        legende: 'Commissions HT par mois.',
    });
    assert.ok(spec);
    assert.equal(spec.type, 'bar');
    assert.deepEqual(spec.labels, ['Jan', 'Fév']);
    assert.equal(spec.series.length, 1);
    assert.deepEqual(spec.series[0].data, [1200.5, 900]); // virgule décimale + espace gérés
    assert.equal(spec.legende, 'Commissions HT par mois.'); // légende conservée
});

test('normaliserSpec rejette les specs inexploitables', () => {
    assert.equal(normaliserSpec(null), null);
    assert.equal(normaliserSpec('bonjour'), null);
    assert.equal(normaliserSpec({ type: 'bar', labels: [], series: [] }), null); // pas de label
    assert.equal(normaliserSpec({ type: 'bar', labels: ['A'], series: [] }), null); // pas de série
    assert.equal(normaliserSpec({ type: 'bar', labels: ['A'], series: [{ label: 'x' }] }), null); // data absent
});

test('normaliserSpec retombe sur bar pour un type inconnu et aligne data sur labels', () => {
    const spec = normaliserSpec({
        type: 'camembert-maison',
        labels: ['A', 'B', 'C'],
        series: [{ label: 'S', data: [10] }], // trop court → complété à 0
    });
    assert.ok(spec);
    assert.equal(spec.type, 'bar');
    assert.ok(CHART_TYPES.includes(spec.type));
    assert.deepEqual(spec.series[0].data, [10, 0, 0]);
});

test('normaliserSpec borne le nombre de séries et la longueur de la légende', () => {
    const series = Array.from({ length: 20 }, (_, i) => ({ label: `S${i}`, data: [1] }));
    const spec = normaliserSpec({
        labels: ['A'],
        series,
        legende: 'x'.repeat(500),
    });
    assert.ok(spec);
    assert.ok(spec.series.length <= 6); // MAX_SERIES
    assert.ok(spec.legende.length <= 240); // MAX_LEGENDE
});

test('construireConfigChart produit une config Chart.js cohérente (barres, multi-séries)', () => {
    const config = construireConfigChart({
        type: 'bar',
        titre: 'CA 2026',
        labels: ['Jan', 'Fév'],
        series: [
            { label: 'HT', data: [1200, 900] },
            { label: 'TTC', data: [1400, 1050] },
        ],
        legende: 'HT et TTC.',
    });
    assert.ok(config);
    assert.equal(config.type, 'bar');
    assert.deepEqual(config.data.labels, ['Jan', 'Fév']);
    assert.equal(config.data.datasets.length, 2);
    assert.deepEqual(config.data.datasets[0].data, [1200, 900]);
    assert.equal(config.options.plugins.legend.display, true); // multi-séries → légende native
    assert.equal(config.options.plugins.title.text, 'CA 2026');
    assert.ok(config.options.scales.y.beginAtZero); // axe présent pour un histogramme
});

test('construireConfigChart colore les secteurs pour un camembert', () => {
    const config = construireConfigChart({
        type: 'doughnut',
        labels: ['Auto', 'Santé', 'RC'],
        series: [{ label: 'Répartition', data: [40, 35, 25] }],
        legende: 'Répartition du portefeuille.',
    });
    assert.ok(config);
    assert.equal(config.type, 'doughnut');
    assert.ok(Array.isArray(config.data.datasets[0].backgroundColor));
    assert.equal(config.data.datasets[0].backgroundColor.length, 3); // une couleur par secteur
    assert.deepEqual(config.options.scales, {}); // pas d'axes sur un circulaire
});

test('construireConfigChart renvoie null sur une spec invalide', () => {
    assert.equal(construireConfigChart({ labels: [], series: [] }), null);
});

test('masquerBlocsChart masque les blocs chart (terminés ou non) sans toucher au reste', () => {
    const termine = 'Voici le CA :\n```chart\n{"type":"bar"}\n```\nVoilà.';
    assert.ok(!masquerBlocsChart(termine).includes('type'));
    assert.ok(masquerBlocsChart(termine).includes('Voici le CA'));
    assert.ok(masquerBlocsChart(termine).includes('Voilà.'));

    const inacheve = 'Analyse :\n```chart\n{"type":"ba';
    assert.ok(!masquerBlocsChart(inacheve).includes('type'));
    assert.ok(masquerBlocsChart(inacheve).includes('Analyse'));

    const sansChart = 'Un simple **tableau** sans graphique.';
    assert.equal(masquerBlocsChart(sansChart), sansChart);
});
