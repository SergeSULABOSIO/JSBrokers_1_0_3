import { Chart } from 'chart.js';
import { normaliserSpec, construireConfigChart } from './assistant-chart-spec.js';

/**
 * Montage des graphiques de l'assistant IA. Le renderer Markdown a laissé, pour
 * chaque bloc ```chart, un hôte sûr `<div class="aic-chart"><code
 * class="aic-chart-spec">{JSON}</code></div>`. Ici on lit le JSON depuis le
 * TEXTE du <code> (jamais du HTML), on valide via le module pur, puis on monte
 * un <canvas> Chart.js dans une <figure>, avec une légende explicative sous le
 * graphique. Idempotent : un hôte déjà monté (`data-monte`) est ignoré.
 */
export function monterGraphiquesAssistant(conteneur) {
    if (!conteneur || typeof conteneur.querySelectorAll !== 'function') {
        return;
    }

    conteneur.querySelectorAll('.aic-chart:not([data-monte])').forEach((hote) => {
        hote.setAttribute('data-monte', '1'); // jamais deux fois, même si re-rendu

        const source = hote.querySelector('.aic-chart-spec');
        let brut = null;
        try {
            brut = JSON.parse((source ? source.textContent : '') || 'null');
        } catch (e) {
            brut = null;
        }
        if (source) {
            source.remove(); // le JSON source n'a rien à faire dans le fil
        }

        const spec = normaliserSpec(brut);
        const config = spec ? construireConfigChart(spec) : null;
        if (!spec || !config) {
            hote.remove(); // spec inexploitable : on n'affiche rien de cassé
            return;
        }

        const figure = document.createElement('figure');
        figure.className = 'aic-chart-figure';

        const zone = document.createElement('div');
        zone.className = 'aic-chart-canvas';
        const canvas = document.createElement('canvas');
        canvas.setAttribute('role', 'img');
        canvas.setAttribute('aria-label', spec.titre || spec.legende || 'Graphique');
        zone.appendChild(canvas);
        figure.appendChild(zone);

        if (spec.legende) {
            const cap = document.createElement('figcaption');
            cap.className = 'aic-chart-legende';
            cap.textContent = spec.legende; // échappement garanti (jamais du HTML)
            figure.appendChild(cap);
        }

        hote.appendChild(figure);

        try {
            new Chart(canvas, config);
        } catch (e) {
            console.error('Ket - rendu du graphique échoué :', e);
            hote.remove();
        }
    });
}
