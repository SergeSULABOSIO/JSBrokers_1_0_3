/**
 * REGROUPEMENT PAR FAMILLE des actions spécifiques d'une rubrique.
 *
 * Problème résolu : les actions spécifiques (`attribute_actions` du canevas de
 * formulaire) s'ajoutent une à une, en barre d'outils comme au clic droit. Passé
 * quelques-unes, la barre déborde et le menu s'allonge — l'utilisateur ne trouve
 * plus rien. Ce module ramène une FAMILLE d'actions (les mouvements d'une police,
 * par exemple) à UNE seule entrée qui déploie ses membres.
 *
 * C'est la SOURCE UNIQUE de la décision : quelles entrées, dans quel ordre, quelle
 * famille. La barre d'outils et le menu contextuel la partagent, sinon les deux
 * surfaces finiraient par proposer des regroupements différents pour les mêmes
 * actions. Chacune ne garde que sa PRÉSENTATION (bouton déroulant / sous-menu).
 *
 * Déclaration côté serveur, sur chaque action de la famille :
 *   "groupe"        => "Mouvements de la police"   (le libellé de la famille)
 *   "groupe_icone"  => "action:renew"              (facultatif : icône de l'entrée)
 */

/** Libellé du groupe de débordement (au-delà du seuil d'entrées en ligne). */
export const GROUPE_DEBORDEMENT = 'Autres actions';

/** Icône du groupe de débordement (alias IconCanvasProvider). */
export const ICONE_DEBORDEMENT = 'action:options';

/**
 * @typedef {{type: 'action', action: object}} EntreeAction
 * @typedef {{type: 'groupe', label: string, icon: string, actions: object[]}} EntreeGroupe
 */

/**
 * Regroupe les actions par famille, dans l'ordre de première apparition.
 *
 * @param {object[]} actions Actions déjà filtrées (sélection + conditions).
 * @param {{maxInline?: number}} [options] `maxInline` = nombre maximal d'entrées
 *        affichées en ligne ; au-delà, le surplus rejoint « Autres actions ».
 *        0 ou absent = pas de plafond (cas du menu contextuel, qui défile).
 * @returns {(EntreeAction|EntreeGroupe)[]}
 */
export function grouperActions(actions, { maxInline = 0 } = {}) {
    const entrees = [];
    const parFamille = new Map();

    (actions || []).forEach((action) => {
        const famille = (action.groupe || '').trim();
        if (!famille) {
            entrees.push({ type: 'action', action });
            return;
        }

        let groupe = parFamille.get(famille);
        if (!groupe) {
            groupe = { type: 'groupe', label: famille, icon: action.groupe_icone || action.icon, actions: [] };
            parFamille.set(famille, groupe);
            entrees.push(groupe);
        }
        // L'icône de famille peut être déclarée sur n'importe quel membre : le
        // premier qui la porte gagne, à défaut celle du premier membre.
        if (action.groupe_icone) groupe.icon = action.groupe_icone;
        groupe.actions.push(action);
    });

    // Un groupe d'un seul membre coûte un clic de plus pour rien : on le remet à plat.
    // Cela arrive naturellement quand les conditions n'en laissent qu'un visible.
    const aplaties = entrees.map((entree) => (
        entree.type === 'groupe' && entree.actions.length === 1
            ? { type: 'action', action: entree.actions[0] }
            : entree
    ));

    if (!maxInline || aplaties.length <= maxInline) return aplaties;

    // DÉBORDEMENT : la barre reste lisible quel que soit le nombre d'actions
    // déclarées — la dernière place est réservée à un menu « Autres actions ».
    const gardees = aplaties.slice(0, maxInline - 1);
    const debordement = { type: 'groupe', label: GROUPE_DEBORDEMENT, icon: ICONE_DEBORDEMENT, actions: [] };
    aplaties.slice(maxInline - 1).forEach((entree) => {
        if (entree.type === 'action') {
            debordement.actions.push(entree.action);
            return;
        }
        // Une famille qui déborde garde son nom en préfixe : sans lui, « Proroger »
        // et « Annuler » se retrouveraient orphelines au milieu d'actions étrangères.
        entree.actions.forEach((action) => {
            debordement.actions.push({ ...action, label: `${entree.label} · ${action.label}` });
        });
    });
    gardees.push(debordement);

    return gardees;
}

/**
 * Résout `%id%` dans l'URL d'une action (même règle dans les deux surfaces).
 *
 * @param {object} action
 * @param {string|number} selectedId
 * @returns {string}
 */
export function urlAction(action, selectedId) {
    return action.url && action.url.includes('%id%')
        ? action.url.replace('%id%', selectedId)
        : action.url;
}
