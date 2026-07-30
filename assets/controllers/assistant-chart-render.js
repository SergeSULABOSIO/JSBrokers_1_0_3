import { Chart } from 'chart.js';
import { normaliserSpec, construireConfigChart, habillageGraphique } from './assistant-chart-spec.js';

/**
 * Montage des graphiques de l'assistant IA. Le renderer Markdown a laissé, pour
 * chaque bloc ```chart, un hôte sûr `<div class="aic-chart"><code
 * class="aic-chart-spec">{JSON}</code></div>`. Ici on lit le JSON depuis le
 * TEXTE du <code> (jamais du HTML), on valide via le module pur, puis on monte
 * un <canvas> Chart.js dans une <figure>, avec une légende explicative sous le
 * graphique. Idempotent : un hôte déjà monté (`data-monte`) est ignoré.
 *
 * @param {Element} conteneur
 * @param {'light'|'dark'} theme Habillage à appliquer (couleurs de séries + chrome).
 */
export function monterGraphiquesAssistant(conteneur, theme = 'light') {
    if (!conteneur || typeof conteneur.querySelectorAll !== 'function') {
        return;
    }

    const { palette, chrome } = habillageGraphique(theme);

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
        const config = spec ? construireConfigChart(spec, palette, chrome) : null;
        if (!spec || !config) {
            hote.remove(); // spec inexploitable : on n'affiche rien de cassé
            return;
        }

        // La spec source vient d'être retirée du DOM (elle n'a rien à faire dans
        // le fil), mais un changement de thème doit pouvoir RECONSTRUIRE ce
        // graphique. On la re-sérialise donc en data-* : invisible pour le
        // lecteur, disponible pour `rethemeGraphiquesAssistant`.
        hote.dataset.chartSpec = JSON.stringify(spec);

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

/**
 * Re-peint les graphiques DÉJÀ montés d'un conteneur avec l'habillage d'un
 * autre thème (bascule clair / sombre). Chart.js ne lit pas les variables CSS :
 * les couleurs vivent dans la configuration, il faut donc la reconstruire.
 *
 * On détruit puis on remonte plutôt que d'appeler `chart.update()` : cela évite
 * les échelles et les caches de légende obsolètes, et conserve le <canvas>
 * existant — donc son `role="img"` et son `aria-label`.
 *
 * @param {Element} conteneur
 * @param {'light'|'dark'} theme
 */
export function rethemeGraphiquesAssistant(conteneur, theme) {
    if (!conteneur || typeof conteneur.querySelectorAll !== 'function') {
        return;
    }

    const { palette, chrome } = habillageGraphique(theme);

    conteneur.querySelectorAll('.aic-chart[data-monte] canvas').forEach((canvas) => {
        const hote = canvas.closest('.aic-chart');
        let spec = null;
        try {
            spec = JSON.parse((hote && hote.dataset.chartSpec) || 'null');
        } catch (e) {
            spec = null;
        }

        const config = spec ? construireConfigChart(spec, palette, chrome) : null;
        if (!config) {
            return; // rien à repeindre : on laisse le graphique tel quel
        }

        try {
            const existant = Chart.getChart(canvas);
            if (existant) {
                existant.destroy();
            }
            new Chart(canvas, config);
        } catch (e) {
            console.error('Ket - re-thème du graphique échoué :', e);
        }
    });
}
