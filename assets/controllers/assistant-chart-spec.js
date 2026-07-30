/**
 * Logique PURE des graphiques de l'assistant IA — AUCUN accès DOM, AUCUN import
 * de Chart.js. Séparée exprès pour être testable sous Node (`node --test`) sans
 * bundler ni navigateur.
 *
 * Contrat : Ket émet un bloc Markdown ```chart contenant un petit JSON. Le front
 * ne lit JAMAIS ce JSON comme du HTML : on ne retient que des champs connus,
 * coercés (type/labels/series → nombres). C'est la frontière de sécurité.
 *
 *   { "type":"bar", "titre":"...", "unite":"€",
 *     "labels":["Jan","Fév"], "series":[{"label":"HT","data":[12,9]}],
 *     "legende":"phrase courte : ce que mesure la série, période, unité" }
 */

/** Types Chart.js autorisés (histogrammes, tendances, répartitions). */
export const CHART_TYPES = ['bar', 'line', 'doughnut', 'pie'];

/**
 * Palette catégorielle JS Brokers (charte cobalt), sans le rouge sémantique
 * (#dc3545) réservé aux erreurs. Sert dans l'ordre pour les séries / secteurs.
 */
export const PALETTE_CHART = ['#0047AB', '#0d6efd', '#198754', '#e69500', '#6c757d', '#003380', '#0a58ca'];

/**
 * Palette catégorielle du MODE SOMBRE. Mêmes familles de teintes, pas
 * re-calculés pour la surface sombre du canvas (#1e2227) : le cobalt #0047AB
 * n'y ferait que 1.90:1 et serait illisible. Ordre validé (séparation des
 * paires adjacentes pour les daltonismes protan/deutan/tritan, plancher de
 * vision normale, contraste >= 3:1 sur le fond sombre) — ne pas réordonner
 * sans revalider : l'ORDRE est le mécanisme de lisibilité, pas une esthétique.
 */
export const PALETTE_CHART_SOMBRE = ['#2f7fe0', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9'];

/**
 * Habillage du graphique (tout ce qui n'est pas une série) : titre, légende,
 * graduations, grille, bordure entre secteurs d'un camembert.
 */
export const CHROME_CHART = {
    titre: '#212529',
    legende: '#495057',
    graduations: '#6c757d',
    grille: '#e9ecef',
    secteur: '#ffffff',
};

/** Même habillage, pas sombres. `secteur` = fond du canvas sombre (séparation nette). */
export const CHROME_CHART_SOMBRE = {
    titre: '#f1f3f5',
    legende: '#ced4da',
    graduations: '#adb5bd',
    grille: '#343a40',
    secteur: '#1e2227',
};

/**
 * Seam UNIQUE thème → habillage graphique : c'est le seul endroit qui décide
 * quelles couleurs un graphique porte. Utilisée par le renderer (montage et
 * re-thème), et testable sans DOM.
 * @param {'light'|'dark'} theme
 * @returns {{palette: string[], chrome: object}}
 */
export function habillageGraphique(theme) {
    return theme === 'dark'
        ? { palette: PALETTE_CHART_SOMBRE, chrome: CHROME_CHART_SOMBRE }
        : { palette: PALETTE_CHART, chrome: CHROME_CHART };
}

const MAX_SERIES = 6;   // au-delà, illisible dans une bulle de chat
const MAX_POINTS = 24;  // ex. 24 mois
const MAX_LEGENDE = 240;
const MAX_TITRE = 120;

/** Réduit une valeur à un texte court, mono-ligne, borné (jamais du HTML). */
function texteCourt(valeur, max) {
    if (typeof valeur !== 'string') {
        return '';
    }
    return valeur.replace(/\s+/g, ' ').trim().slice(0, max);
}

/** Coerce une valeur en nombre fini (accepte la virgule décimale), sinon 0. */
function nombre(valeur) {
    if (typeof valeur === 'number') {
        return Number.isFinite(valeur) ? valeur : 0;
    }
    if (typeof valeur === 'string') {
        const n = Number(valeur.replace(',', '.').replace(/\s/g, ''));
        return Number.isFinite(n) ? n : 0;
    }
    return 0;
}

/**
 * Valide et normalise une spec brute (issue de JSON.parse). Retourne un objet
 * sûr `{ type, titre, unite, legende, labels, series }` ou `null` si la spec
 * est inexploitable (pas de labels, pas de série). Idempotent.
 */
export function normaliserSpec(brut) {
    if (!brut || typeof brut !== 'object') {
        return null;
    }

    const type = CHART_TYPES.includes(brut.type) ? brut.type : 'bar';

    const labels = Array.isArray(brut.labels)
        ? brut.labels.slice(0, MAX_POINTS).map((l) => texteCourt(String(l ?? ''), 40))
        : [];
    if (labels.length === 0) {
        return null;
    }

    const series = [];
    const brutes = Array.isArray(brut.series) ? brut.series : [];
    for (const s of brutes.slice(0, MAX_SERIES)) {
        if (!s || typeof s !== 'object' || !Array.isArray(s.data)) {
            continue;
        }
        // Aligne strictement la série sur les labels (complète/tronque à 0).
        const data = labels.map((_, i) => nombre(s.data[i]));
        series.push({ label: texteCourt(String(s.label ?? ''), 60) || 'Série', data });
    }
    if (series.length === 0) {
        return null;
    }

    return {
        type,
        titre: texteCourt(brut.titre, MAX_TITRE),
        unite: texteCourt(brut.unite, 12),
        legende: texteCourt(brut.legende, MAX_LEGENDE),
        labels,
        series,
    };
}

/** Convertit un hex #rrggbb en rgba() avec l'alpha donné (aires de courbe). */
function transparence(hex, alpha) {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex));
    if (!m) {
        return hex;
    }
    return `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${alpha})`;
}

/**
 * Construit une configuration Chart.js v3 à partir d'une spec (brute ou déjà
 * normalisée — `normaliserSpec` est idempotent). Retourne `null` si invalide.
 * Ne touche pas au DOM : c'est un simple objet, testable tel quel.
 *
 * `palette` et `chrome` par défaut = mode clair : un appel à un seul argument
 * produit exactement la configuration d'origine (rétrocompatibilité).
 */
export function construireConfigChart(spec, palette = PALETTE_CHART, chrome = CHROME_CHART) {
    const s = normaliserSpec(spec);
    if (!s) {
        return null;
    }

    const circulaire = s.type === 'pie' || s.type === 'doughnut';

    const datasets = circulaire
        ? [{
            label: s.series[0].label,
            data: s.series[0].data,
            backgroundColor: s.labels.map((_, i) => palette[i % palette.length]),
            borderColor: chrome.secteur,
            borderWidth: 1,
        }]
        : s.series.map((serie, i) => {
            const couleur = palette[i % palette.length];
            const ligne = s.type === 'line';
            return {
                label: serie.label,
                data: serie.data,
                backgroundColor: ligne ? transparence(couleur, 0.15) : couleur,
                borderColor: couleur,
                borderWidth: 2,
                borderRadius: ligne ? 0 : 4,
                tension: ligne ? 0.3 : 0,
                pointRadius: ligne ? 2 : 0,
                fill: ligne,
                maxBarThickness: 46,
            };
        });

    // Légende Chart.js native (noms de séries) : utile dès qu'il y a plusieurs
    // séries ou des secteurs à nommer ; superflue pour une seule série barres.
    const legendeSeries = circulaire || s.series.length > 1;

    return {
        type: s.type,
        data: { labels: s.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 320 },
            plugins: {
                legend: {
                    display: legendeSeries,
                    position: circulaire ? 'right' : 'top',
                    labels: { boxWidth: 12, font: { size: 11 }, color: chrome.legende },
                },
                title: s.titre
                    ? { display: true, text: s.titre, color: chrome.titre, font: { size: 13, weight: '600' } }
                    : { display: false },
            },
            scales: circulaire
                ? {}
                : {
                    y: { beginAtZero: true, ticks: { font: { size: 10 }, color: chrome.graduations }, grid: { color: chrome.grille } },
                    x: { ticks: { font: { size: 10 }, color: chrome.graduations }, grid: { display: false } },
                },
        },
    };
}

/**
 * Remplace tout bloc ```chart (ou ```graphique), même inachevé, par un court
 * repère. Utilisé PENDANT l'effet machine à écrire pour ne pas dérouler le JSON
 * mot à mot : le graphique n'apparaît qu'au rendu final (texte intégral).
 */
export function masquerBlocsChart(texte) {
    return String(texte ?? '').replace(/```(?:chart|graphique)[\s\S]*?(?:```|$)/gi, '📊 …');
}
