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

import { conditionRemplie } from './condition-action.js';

/**
 * LES ACTIONS QU'UNE SÉLECTION PERMET — une seule règle pour la barre d'outils et le
 * menu contextuel, qui la recopiaient chacun à l'identique.
 *
 * ── TROIS PORTÉES, ET NON DEUX ──────────────────────────────────────────────────────
 *  - `sans_selection` : l'action ne porte sur AUCUNE ligne — un calendrier d'équipe,
 *    une grille de compteurs regardent tout le cabinet. Les enfermer derrière une
 *    sélection obligeait à cocher une ligne au hasard pour ouvrir un écran qui ne la
 *    concerne pas, et laissait croire qu'ils en dépendaient ;
 *  - `multi` : visible dès 1 ligne, une ou plusieurs ;
 *  - sans drapeau : sélection UNIQUE (comportement historique).
 * Puis la condition éventuelle de l'action, lue sur l'entité de la première ligne.
 *
 * @param {object[]} actions Les `attribute_actions` du canevas.
 * @param {object[]} selection Les selectos sélectionnés.
 * @returns {object[]}
 */
export function actionsVisibles(actions, selection) {
    const nombre = selection?.length || 0;
    const entite = selection?.[0]?.entity || {};

    return (actions || []).filter((action) => {
        if (action.sans_selection === true) return true;

        const compteOk = action.multi === true ? nombre >= 1 : nombre === 1;
        if (!compteOk) return false;
        return conditionRemplie(entite, action.condition);
    });
}

/**
 * @typedef {{type: 'action', action: object}} EntreeAction
 * @typedef {{type: 'groupe', label: string, icon: string, actions: object[]}} EntreeGroupe
 */

/**
 * Regroupe les actions par famille, dans l'ordre de première apparition.
 *
 * Aucun plafond : le repli « Autres actions » a été retiré (2026-10-28) — un second « ⋮ »,
 * confondu avec « Plus » dans le rail de la colonne 3, qui a la place et défile au besoin.
 *
 * @param {object[]} actions Actions déjà filtrées (sélection + conditions).
 * @returns {(EntreeAction|EntreeGroupe)[]}
 */
export function grouperActions(actions) {
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
    return entrees.map((entree) => (
        entree.type === 'groupe' && entree.actions.length === 1
            ? { type: 'action', action: entree.actions[0] }
            : entree
    ));
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
