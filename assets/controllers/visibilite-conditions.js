/**
 * LA DÉCISION DE VISIBILITÉ D'UN CHAMP — cœur PUR du moteur déclaratif des dialogues.
 *
 * Sans Stimulus, sans DOM à construire, donc exerçable par `node --test` (même patron que
 * collection-tampon.js et confirmation-armement.js). Le contrôleur ne garde que le geste :
 * poser ou retirer une classe.
 *
 * ── POURQUOI CE MODULE EXISTE ────────────────────────────────────────────────────────
 * Ce moteur décide si un champ paraît. Quand il se trompe, il ne lève rien, n'écrit rien
 * en console : le champ reste simplement affiché ou masqué à tort, et l'on peut chercher
 * longtemps. C'est précisément ce qui s'était produit sur « Risques ciblés », dont le
 * sélecteur exigeait des crochets que le nom du champ ne portait pas.
 *
 * Trois manques y ont été comblés, tous silencieux :
 *   1. le OU n'existait pas — les conditions ne se combinaient qu'en ET ;
 *   2. un champ à choix multiple, rendu `name="x[]"`, restait introuvable ;
 *   3. `not_empty` ne regardait que la première option d'un `<select multiple>`.
 */

/** Le nom tel qu'il peut apparaître dans le formulaire : nu, ou suffixé pour un multiple. */
export function nomsPossibles(nom) {
    return [nom, `${nom}[]`];
}

/**
 * Le champ, quel que soit son écriture.
 *
 * Un champ à choix multiple se rend `name="conditionsPartageAgent[]"` : le chercher sous
 * son seul nom nu échouait, `evaluateCondition` rendait `false`, et le bloc restait masqué
 * pour toujours. L'écoute des sources, elle, gérait déjà les deux formes — c'est cette
 * asymétrie qui rendait la panne indétectable.
 */
export function trouverChamp(elements, nom) {
    if (!elements) return null;

    for (const candidat of nomsPossibles(nom)) {
        const champ = elements[candidat];
        if (champ) return champ;
    }

    return null;
}

/**
 * La valeur observable d'un champ, pour les besoins d'une condition.
 *
 * Rend `null` quand il n'y a rien à observer — un groupe de radios sans choix coché, par
 * exemple : la condition est alors simplement fausse, elle n'est pas en erreur.
 */
export function valeurObservee(champ, valeurCocheeRadio = null) {
    if (!champ) return null;

    // Groupe de boutons radio : c'est l'option cochée qui parle, et le contrôleur nous la
    // donne (lui seul sait interroger le DOM).
    if (champ.estGroupeRadio) return valeurCocheeRadio;

    // <select MULTIPLE> seulement : `value` ne rend que la PREMIÈRE option choisie, ce qui
    // suffisait à faire croire « vide » un champ qui ne l'était pas dès que la première
    // ligne était désélectionnée. Le nombre d'options retenues est la seule mesure fidèle.
    //
    // LA GARDE `multiple` EST LE CŒUR DE LA RÈGLE. Sans elle, un select SIMPLE tombait
    // ici aussi : son option de tête — le « choisir… » vide — compte pour une option
    // retenue, si bien qu'un champ manifestement vide se lisait « 1 ». Le bloc des
    // conditions propres restait donc affiché sur une affaire sans aucun bénéficiaire,
    // exactement ce que la règle devait empêcher.
    if (champ.multiple === true && typeof champ.selectedOptions?.length === 'number') {
        return champ.selectedOptions.length > 0 ? String(champ.selectedOptions.length) : '';
    }

    return champ.value ?? null;
}

/** Une valeur est-elle « renseignée » ? */
export function estRenseignee(valeur) {
    return valeur !== null && valeur !== undefined && String(valeur).trim() !== '';
}

/**
 * Évalue UNE condition.
 *
 * `lireValeur(nomDuChamp)` est injecté : c'est lui qui touche le DOM, et c'est ce qui garde
 * cette fonction pure. Il rend `undefined` quand le champ n'existe pas — distinct d'une
 * valeur vide, qui, elle, est une réponse.
 *
 * @param {{operator?: string, field?: string, value?: any[], conditions?: any[]}} condition
 * @param {(nom: string) => (string|null|undefined)} lireValeur
 */
export function evaluerCondition(condition, lireValeur) {
    if (!condition) return false;

    // LE OU. Le moteur ne combinait qu'en ET (`every`), ce qui interdisait d'exprimer
    // « paraître si un intermédiaire OU un agent est désigné ». `any` porte ses propres
    // sous-conditions et se lit récursivement — il peut donc en contenir d'autres.
    if (condition.operator === 'any') {
        return (condition.conditions ?? []).some((sous) => evaluerCondition(sous, lireValeur));
    }

    // Symétrique, pour que la grammaire soit complète plutôt qu'à moitié.
    if (condition.operator === 'all') {
        const sousConditions = condition.conditions ?? [];

        return sousConditions.length > 0
            && sousConditions.every((sous) => evaluerCondition(sous, lireValeur));
    }

    const valeur = lireValeur(condition.field);
    if (valeur === undefined || valeur === null) return false;

    if (condition.operator === 'in') {
        // Les deux côtés en chaînes : un identifiant vaut 7 côté PHP et "7" dans le DOM.
        return (condition.value ?? []).map(String).includes(String(valeur));
    }

    if (condition.operator === 'not_empty') {
        return estRenseignee(valeur);
    }

    // Opérateur inconnu : on ne montre pas. Un champ affiché par erreur est une porte
    // ouverte sur une saisie qui ne sera pas lue ; un champ masqué se remarque et se
    // signale.
    return false;
}

/**
 * Un conteneur doit-il être visible ?
 *
 * Les conditions d'un même conteneur se combinent en ET — c'est le contrat historique, et
 * `any` est là pour exprimer l'autre.
 */
export function conteneurVisible(conditions, lireValeur) {
    if (!Array.isArray(conditions) || conditions.length === 0) return true;

    return conditions.every((condition) => evaluerCondition(condition, lireValeur));
}

/**
 * Une rangée doit-elle être visible ?
 *
 * UNE RANGÉE QUE LE CANEVAS A MASQUÉE LE RESTE. La règle « une rangée est masquée quand
 * toutes ses colonnes le sont » ne portait aucune exception : appliquée à une rangée que
 * le serveur avait rendue avec `d-none` (clé `hidden` du canevas), elle la RÉAFFICHAIT —
 * une seule colonne visible suffisait à conclure « rangée visible ». Le champ revenait
 * donc à l'écran une fraction de seconde après son arrivée, et le masquage déclaré par le
 * canevas n'avait d'effet que dans le HTML brut, jamais sous les yeux de l'utilisateur.
 *
 * @param {{masqueeParLeCanevas: boolean, colonnesVisibles: number}} etat
 */
export function rangeeVisible(etat) {
    if (etat.masqueeParLeCanevas) return false;

    return etat.colonnesVisibles > 0;
}

/**
 * Les noms de champs dont dépend un jeu de conditions — y compris ceux enfouis dans un
 * `any`/`all`.
 *
 * POURQUOI CETTE FONCTION EXISTE. Le contrôleur posait ses écouteurs en lisant
 * `condition.field` à plat. Une condition composée n'en porte pas : elle porte des
 * sous-conditions. Aucun écouteur n'était donc posé, et le bloc gardait l'état calculé à
 * l'ouverture — désigner un intermédiaire ne le faisait pas paraître, le retirer ne le
 * faisait pas disparaître. Rien ne signalait la panne : la règle était juste, seule la
 * mise à jour manquait.
 *
 * @param {Array} conditions
 * @returns {string[]} noms uniques, dans l'ordre de rencontre
 */
export function champsSources(conditions) {
    const noms = [];

    const parcourir = (condition) => {
        if (!condition || typeof condition !== 'object') return;
        if (Array.isArray(condition.conditions)) {
            condition.conditions.forEach(parcourir);
        }
        if (typeof condition.field === 'string' && !noms.includes(condition.field)) {
            noms.push(condition.field);
        }
    };

    (Array.isArray(conditions) ? conditions : []).forEach(parcourir);

    return noms;
}
