import { Controller } from '@hotwired/stimulus';

/**
 * LES GESTES DU TABLEAU DE BORD — sans une ligne de code dans le HTML.
 *
 * Les blocs du tableau de bord (tâches, renouvellements, encaissements, dépenses, sinistres,
 * pistes, notes, bordereaux, utilisateurs, production) appellent les fonctions `window.db…`
 * d'`assets/app.js` : bascules de filtre, recherche, menus contextuels, créations. Elles
 * s'appelaient par un attribut onclick (`dbRenewToggle(this)`), ce que la politique de sécurité
 * (chantier 9, `PolitiqueCsp`) refuse. Elles s'appellent désormais ainsi :
 *
 *   data-action="click->tableau-de-bord#appeler"
 *   data-tableau-de-bord-fonction-param="dbRenewToggle"
 *   data-tableau-de-bord-arguments-param='["risque","Nouveau risque"]'   (facultatif)
 *
 * Même appel qu'avant : la fonction reçoit l'élément (l'ancien `this`), puis les arguments
 * fixes ; un menu contextuel (`contextmenu`) reçoit `(event, élément)`, comme
 * `dbTaskCtxOpen(event,this)`. `:stop` remplace `event.stopPropagation();`.
 *
 * LISTE FERMÉE. Le nom vient d'un attribut : seules les fonctions ci-dessous peuvent être
 * appelées. Un attribut injecté ne pourrait donc rien déclencher d'autre — ni `eval`, ni une
 * fonction qu'on ajouterait demain à `window` sans y penser.
 *
 * Posé sur <body> (base.html.twig) : couvre les blocs chargés en fragment et les lignes que
 * `app.js` fabrique (tableaux de production), que Stimulus relie à leur arrivée.
 */
const FONCTIONS_AUTORISEES = new Set([
    'dbBlockAdd',
    'dbBordCtxOpen', 'dbBordNew', 'dbBordSearch', 'dbBordToggle',
    'dbCashSearch', 'dbCashToggle',
    'dbDepCtxOpen', 'dbDepSearch', 'dbDepToggle',
    'dbInviteCtxDelete', 'dbInviteCtxEdit', 'dbInviteCtxOpen', 'dbInviteCtxResend',
    'dbNoteCtxOpen', 'dbNoteNew', 'dbNoteSearch', 'dbNoteToggle',
    'dbPisteCtxOpen', 'dbPisteNew', 'dbPisteSearch', 'dbPisteToggle',
    'dbProdToggleAssureur', 'dbProdToggleMode', 'dbProdToggleMonth', 'dbProdToggleRisque',
    'dbRenewCtxNoRenewal', 'dbRenewCtxOpen', 'dbRenewCtxPiste', 'dbRenewSearch', 'dbRenewToggle',
    'dbSinCtxOpen', 'dbSinistreNew', 'dbSinistreSearch', 'dbSinistreToggle',
    'dbTaskCtxClose', 'dbTaskCtxFeedback', 'dbTaskCtxOpen', 'dbTaskNew', 'dbTaskSearch', 'dbTaskToggle',
]);

export default class extends Controller {
    appeler(event) {
        const { fonction } = event.params;
        // La liste d'abord, `window` ensuite : un nom refusé n'est même pas lu.
        // Un refus LÈVE (au lieu d'écrire en console) : Stimulus le remonte à window.onerror, et la
        // veille des erreurs (veille-erreurs.js) le porte à la supervision — comme l'ancien
        // attribut onclick appelant une fonction absente, qui levait une ReferenceError.
        if (!FONCTIONS_AUTORISEES.has(fonction)) {
            throw new Error(`tableau-de-bord : fonction non autorisée « ${fonction} »`);
        }
        const cible = window[fonction];
        if (typeof cible !== 'function') {
            throw new Error(`tableau-de-bord : fonction « ${fonction} » absente (assets/app.js)`);
        }
        const element = event.currentTarget;
        if (event.type === 'contextmenu') {
            cible(event, element);
            return;
        }
        const suite = Array.isArray(event.params.arguments) ? event.params.arguments : [];
        cible(element, ...suite);
    }
}
