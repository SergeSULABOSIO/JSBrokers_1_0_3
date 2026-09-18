/**
 * Cœur PUR du mode Live : SAVOIR OÙ PART LE TEMPS.
 *
 * Le 2026-09-18, l'attente d'un tour parlé se répartissait ainsi : 0,9 s de silence
 * de fin de phrase, ~3 s de transcription, 8 s de compréhension (souvent perdue),
 * 5 à 20 s de réflexion, jusqu'à 6 s de déploiement mot à mot, puis 2,4 s avant le
 * premier son. On n'a trouvé ces chiffres qu'en lisant les journaux du serveur, ce
 * qui ne dit rien de ce que VIT l'utilisateur.
 *
 * Ce chrono mesure le tour côté navigateur, étape par étape. Sans lui, la prochaine
 * optimisation se ferait au jugé — et l'on optimiserait la mauvaise étape.
 *
 * Aucun DOM, aucune horloge implicite : l'instant est toujours donné par l'appelant.
 */

/** Un tour vierge. */
export function nouveauTour(instantMs = 0) {
    return { debut: instantMs, dernier: instantMs, etapes: {} };
}

/**
 * Marque la fin d'une étape. Le temps compté va du dernier jalon à celui-ci, de sorte
 * que la somme des étapes égale toujours la durée du tour.
 */
export function jalon(tour, etape, instantMs) {
    const t = tour ?? nouveauTour(instantMs);
    const duree = Math.max(0, instantMs - t.dernier);

    return {
        debut: t.debut,
        dernier: instantMs,
        etapes: { ...t.etapes, [etape]: (t.etapes[etape] ?? 0) + duree },
    };
}

/** Durée totale du tour, en millisecondes. */
export function totalDuTour(tour) {
    return Math.max(0, (tour?.dernier ?? 0) - (tour?.debut ?? 0));
}

/** Un résumé lisible : « 12,4 s — transcription 0,2 · réflexion 9,8 · voix 1,1 ». */
export function resumeDuTour(tour) {
    const s = (ms) => (ms / 1000).toFixed(1);
    const details = Object.entries(tour?.etapes ?? {})
        .map(([etape, ms]) => `${etape} ${s(ms)}`)
        .join(' · ');

    return `${s(totalDuTour(tour))} s${details === '' ? '' : ` — ${details}`}`;
}
