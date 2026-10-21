/**
 * UN SEUL GESTIONNAIRE DE DIALOGUES ACTIF (suivi du chantier 2, décision de Serge, 2026-10-21).
 *
 * `dialog-manager` est posé sur le <body> de base.html.twig ET sur l'analyse du bordereau, qui
 * a aussi sa page autonome (sans base.html.twig). Injectée dans l'espace de travail, l'analyse
 * en installait donc un SECOND : chaque demande d'ouverture créait deux dialogues identiques —
 * deux formulaires, donc un risque de double enregistrement et de doublon en base.
 *
 * Règle simple : le premier installé garde la main ; un suivant ne s'installe pas. Pas de
 * relais : celui du <body> ne disparaît jamais tant que la page vit. L'unification complète
 * des gestionnaires reste au chantier 3.
 */
let installe = null;

/**
 * @param {{isConnected: boolean}} element  l'élément du contrôleur qui veut s'installer
 * @returns {boolean} vrai s'il prend la main ; faux s'il doit rester inerte
 */
export function reserverLInstallation(element) {
    if (installe && installe.isConnected && installe !== element) return false;
    installe = element;
    return true;
}

/** Rend la main — seulement si c'est bien celui qui l'avait. */
export function libererLInstallation(element) {
    if (installe === element) installe = null;
}
