/**
 * LES ÉVÉNEMENTS QUE NOTRE CODE ÉMET LUI-MÊME (suivi du chantier 2).
 *
 * Plusieurs composants préviennent les autres d'une valeur posée par script (préremplissage à
 * l'ouverture, recopie de l'éditeur riche, date de fin calculée d'un congé) en émettant
 * `input`/`change`. Ce ne sont PAS des saisies de l'utilisateur : un dialogue qui les comptait
 * demanderait « Abandonner ? » sur un formulaire intact. Ils portent donc une marque, posée
 * ici et nulle part ailleurs (echap-dialogue.js#evenementDeSaisie la lit).
 */

/**
 * @param {string} type  'input' | 'change' | …
 * @returns {Event} un événement qui remonte, marqué « émis par l'application »
 */
export function evenementInterne(type) {
    const evenement = new Event(type, { bubbles: true });
    evenement.jsbInterne = true;
    return evenement;
}

/** @param {Event|null|undefined} evenement */
export function estInterne(evenement) {
    return evenement?.jsbInterne === true;
}
