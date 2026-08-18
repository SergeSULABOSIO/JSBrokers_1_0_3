/**
 * CŒUR PUR de la visibilité conditionnelle des « Risques ciblés » — aucune dépendance,
 * ni Stimulus ni DOM, pour être exerçable par `node --test` (même patron que
 * confirmation-armement.js à côté de son contrôleur).
 *
 * Le critère sur le risque a trois valeurs : ne cibler aucun risque, ne partager QUE sur
 * certains, ou ne PAS partager sur certains. Dans le premier cas, la liste des risques n'a
 * aucun objet — la laisser à l'écran demande à l'utilisateur de comprendre seul qu'elle ne
 * sert à rien ici (Bastien & Scapin > Charge de travail), et l'expose à cocher des risques
 * qui ne seront jamais lus.
 */

/** Valeur du critère « il n'y a pas de risques ciblés » (ConditionPartage::CRITERE_PAS_RISQUES_CIBLES). */
export const PAS_DE_RISQUES_CIBLES = '2';

/**
 * Ce nom de champ désigne-t-il le critère sur le risque ?
 *
 * L'INCIDENT (2026-08-18). La règle exigeait des CROCHETS (`[critereRisque]`), comme dans
 * un formulaire imbriqué. Or ConditionPartageType rend `getBlockPrefix()` à la chaîne
 * VIDE : dans le dialogue, les radios s'appellent simplement « critereRisque ». Le
 * sélecteur ne matchait donc JAMAIS, et le bloc restait visible quoi qu'on coche — sans
 * la moindre erreur en console. Une règle trop stricte échoue en silence.
 *
 * On accepte les deux écritures : le champ nu du dialogue, et le champ préfixé qu'on
 * obtiendrait si ce formulaire était un jour imbriqué dans un autre.
 */
export function estChampCritereRisque(nom) {
    if (typeof nom !== 'string' || nom === '') return false;

    return nom === 'critereRisque' || nom.endsWith('[critereRisque]');
}

/**
 * Le bloc « Risques ciblés » doit-il être visible ?
 *
 * Il ne disparaît QUE sur « il n'y a pas de risques ciblés ». Aucun choix coché : on
 * montre — fail-open sur l'AFFICHAGE, jamais sur les données. Cacher un champ dont on
 * ignore encore s'il servira priverait l'utilisateur d'une saisie sans rien lui dire.
 */
export function risquesCiblesVisibles(valeurCochee) {
    return valeurCochee === null || valeurCochee === undefined || valeurCochee !== PAS_DE_RISQUES_CIBLES;
}

/** Le sélecteur des radios du critère, cohérent avec estChampCritereRisque(). */
export const SELECTEUR_CRITERE_COCHE = '[name="critereRisque"]:checked, [name$="[critereRisque]"]:checked';
