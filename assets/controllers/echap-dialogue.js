/**
 * ÉCHAP DANS LES DIALOGUES — règles pures (chantier 2).
 *
 * Les dialogues de formulaire refusaient Échap (`data-bs-keyboard="false"`) : Bootstrap les
 * aurait fermés sans demander, et une saisie qui n'existe nulle part ailleurs serait perdue.
 * Exigences de Serge (2026-10-20) :
 *   — Échap ferme d'abord ce qui est OUVERT dans le dialogue (autocomplétion, menu, liste,
 *     picker) — ces composants s'en chargent eux-mêmes —, puis le dialogue du DESSUS seulement ;
 *   — jamais pendant une saisie dans un éditeur riche (Quill) ;
 *   — une saisie non enregistrée demande confirmation (même garde-fou que « Fermer ») ;
 *   — le focus revient à l'élément qui a ouvert le dialogue (dialog-manager).
 * Branchement : dialog-manager_controller.js (écoute en phase de CAPTURE, pour décider avant
 * que les composants ne referment leur liste et ne fassent croire qu'il n'y avait rien d'ouvert).
 */

/**
 * Ce qui, ouvert dans un dialogue, doit recevoir Échap AVANT lui. Des MENUS et des LISTES —
 * pas une section dépliée : un accordéon porte `aria-expanded="true"` sans `aria-haspopup`.
 */
export const OUVERTS_DANS_LE_DIALOGUE = [
    '[aria-haspopup][aria-expanded="true"]',
    '[role="combobox"][aria-expanded="true"]',
    '.jsb-picker-overlay',
    '.dropdown-menu.show',
    '.ql-tooltip:not(.ql-hidden)',
    '.ql-picker.ql-expanded',
].join(', ');

/**
 * Que faire de cette touche ?
 * @param {{touche: string, enComposition?: boolean, dejaTraitee?: boolean,
 *          dessus?: 'formulaire'|'simple'|'confirmation'|'autre'|null, dansEditeurRiche?: boolean,
 *          sousElementOuvert?: boolean, repetee?: boolean, echapLocal?: boolean,
 *          infobulleOuverte?: boolean}} p
 *   `dessus` : le dialogue au sommet de la pile — 'formulaire' (dialog-instance), 'simple'
 *   (dialogue applicatif sans saisie à protéger, ex. recherche avancée), 'confirmation' (la
 *   question « Abandonner… ? » : la fermer, c'est ANNULER, sans rien perdre), 'autre' (modale
 *   tierce : elle gère son Échap), null (aucun).
 *   La confirmation se ferme par nous : deux modales empilées se disputent le focus, et
 *   Bootstrap ne recevait pas l'Échap qui lui était destiné (constaté au navigateur).
 * @returns {'laisser'|'fermer-formulaire'|'fermer-simple'|'fermer-confirmation'}
 */
export function decisionEchap({ touche, enComposition = false, dejaTraitee = false, repetee = false, echapLocal = false, dessus = null, dansEditeurRiche = false, sousElementOuvert = false, infobulleOuverte = false }) {
    if (touche !== 'Escape' && touche !== 'Esc') return 'laisser';
    // Une touche MAINTENUE se répète : sans ce filtre, elle fermait toute la pile, un
    // dialogue à chaque répétition. Un champ qui fait son propre usage d'Échap (« Aller à la
    // page » rétablit sa valeur) le garde.
    if (enComposition || dejaTraitee || repetee || echapLocal) return 'laisser';
    // L'INFOBULLE OUVERTE PREND LE PREMIER ÉCHAP (WCAG 1.4.13, chantier 3) : c'est la surface la
    // plus passagère ; le second Échap ferme le dialogue.
    if (infobulleOuverte) return 'laisser';
    if (dessus === 'confirmation') return 'fermer-confirmation';
    if (dessus !== 'formulaire' && dessus !== 'simple') return 'laisser';
    if (dansEditeurRiche || sousElementOuvert) return 'laisser';

    return dessus === 'formulaire' ? 'fermer-formulaire' : 'fermer-simple';
}

/**
 * L'empreinte d'un formulaire : ses valeurs, dans l'ordre. Un texte riche compte par son HTML
 * (Quill le recopie dans son champ à chaque changement) ; un FICHIER par son nom et sa taille —
 * son contenu n'a pas à être relu à chaque comparaison.
 * @param {Iterable<[string, unknown]>} entrees  ex. `new FormData(form).entries()`
 * @returns {string}
 */
export function empreinteDuFormulaire(entrees) {
    return JSON.stringify([...entrees].map(([nom, valeur]) => [nom, estFichier(valeur) ? `${valeur.name}:${valeur.size}` : valeur]));
}

function estFichier(valeur) {
    return valeur !== null && typeof valeur === 'object' && 'name' in valeur && 'size' in valeur;
}

// ── Suivi du chantier 2 (décisions de Serge, 2026-10-21) ─────────────────────────

/**
 * Cet événement est-il une SAISIE à protéger ? Compte : tout `input`/`change` de confiance
 * (dictée, glisser-déposer, gestionnaire de mots de passe) et tout `input`/`change` étranger
 * (extension qui remplit un champ). Ne compte pas : ce que NOTRE code émet
 * (evenement-interne.js) et la recopie interne de Tom Select vers son <select> caché.
 * Et seulement sur un champ qui COMPTE dans le formulaire (un `name` dans le <form>, ou
 * l'éditeur riche) : la dictée dans la recherche interne de Tom Select ou le remplissage d'un
 * champ anonyme ne changent rien de ce qui serait enregistré.
 * @param {{type: string, isTrusted?: boolean, interne?: boolean, surSelectTomSelect?: boolean,
 *          champDuFormulaire?: boolean}} e
 */
export function evenementDeSaisie({ type, isTrusted = false, interne = false, surSelectTomSelect = false, champDuFormulaire = true }) {
    if (type !== 'input' && type !== 'change') return false;
    if (!champDuFormulaire) return false;
    if (isTrusted) return true;

    return !interne && !surSelectTomSelect;
}

/**
 * La fermeture doit-elle demander « Abandonner ? » ?
 * Avec une empreinte (prise au premier geste), la COMPARAISON seule décide : traverser les
 * champs au Tab sans rien changer ne demande rien. Sans empreinte (aucun geste dans la page),
 * une saisie sans geste — valeur posée par une extension, la dictée… — suffit.
 * @param {{empreinteInitiale: string|null, empreinteActuelle?: string, saisieSansGeste?: boolean}} p
 */
export function fermetureADemander({ empreinteInitiale, empreinteActuelle, saisieSansGeste = false }) {
    if (empreinteInitiale === null) return saisieSansGeste;

    return empreinteInitiale !== empreinteActuelle;
}

/**
 * La fermeture AUTOMATIQUE (congé : 2 s après l'enregistrement) peut-elle avoir lieu ? Non si
 * l'utilisateur a repris la main depuis — un geste, une saisie sans geste, ou un formulaire
 * qui a changé : il travaille, on ne lui retire pas le dialogue.
 * @param {{gesteDepuisEnregistrement: boolean, saisieSansGeste: boolean, modifie: boolean}} p
 */
export function fermetureAutomatiqueAutorisee({ gesteDepuisEnregistrement, saisieSansGeste, modifie }) {
    return !gesteDepuisEnregistrement && !saisieSansGeste && !modifie;
}
