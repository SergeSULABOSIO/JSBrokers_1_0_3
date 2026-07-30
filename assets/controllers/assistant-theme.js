/**
 * Logique PURE du thème du chat de l'assistant IA — AUCUN accès au DOM, à
 * `localStorage`, à `matchMedia` ni au réseau. Séparée exprès pour être testable
 * sous Node (`node --test tests/js/`) sans bundler ni navigateur, sur le modèle
 * de `assistant-chart-spec.js`. La coquille Stimulus (`assistant-chat`) lit les
 * entrées (attribut serveur, préférence système) et les passe en arguments.
 *
 * Règle de résolution, unique et volontairement simple :
 *
 *     choix explicite de l'utilisateur  >  préférence système du poste  >  clair
 *
 * Le serveur rend 'light' / 'dark' quand l'utilisateur a tranché (colonne
 * Utilisateur::$themeAssistant), 'auto' sinon — d'où la normalisation qui
 * ramène tout ce qui n'est pas un thème explicite à `null`.
 */

export const THEME_CLAIR = 'light';
export const THEME_SOMBRE = 'dark';

/**
 * Événement de bascule, diffusé sur `document`. Toutes les instances de chat
 * ouvertes l'écoutent — y compris celle qui l'émet : un seul chemin de code
 * applique le thème, donc plusieurs conversations ouvertes restent cohérentes.
 */
export const EVENEMENT_THEME = 'assistant:theme.changed';

/**
 * Ramène une valeur quelconque à un thème explicite, ou `null` s'il n'y en a
 * pas ('auto', undefined, chaîne inconnue…). Idempotent.
 * @param {*} valeur
 * @returns {'light'|'dark'|null}
 */
export function normaliserTheme(valeur) {
    if (valeur === THEME_SOMBRE) {
        return THEME_SOMBRE;
    }

    return valeur === THEME_CLAIR ? THEME_CLAIR : null;
}

/**
 * Résout le thème effectif à appliquer.
 * @param {{stocke?: *, prefereSombre?: boolean}} entrees
 * @returns {'light'|'dark'}
 */
export function resoudreTheme({ stocke = null, prefereSombre = false } = {}) {
    const explicite = normaliserTheme(stocke);
    if (explicite !== null) {
        return explicite;
    }

    return prefereSombre ? THEME_SOMBRE : THEME_CLAIR;
}

/**
 * Thème opposé — la bascule est binaire : une fois cliquée, elle fige toujours
 * un choix explicite (on ne revient jamais au mode « auto » par ce bouton).
 * @param {*} theme
 * @returns {'light'|'dark'}
 */
export function themeOppose(theme) {
    return theme === THEME_SOMBRE ? THEME_CLAIR : THEME_SOMBRE;
}
