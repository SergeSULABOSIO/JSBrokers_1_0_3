/**
 * UNE ACTION CONDITIONNELLE EST-ELLE APPLICABLE À CETTE LIGNE ?
 *
 * La barre d'outils et le menu contextuel proposent les mêmes actions et doivent donc
 * répondre la même chose. Ils portaient chacun leur copie de la règle — deux lignes
 * identiques, dans deux fichiers : elles étaient d'accord par chance, et le premier
 * ajustement les aurait séparées. Une action visible d'un côté et masquée de l'autre est
 * exactement le genre d'incohérence qu'on ne remarque qu'en la subissant.
 *
 * ── DEUX FORMES DE CONDITION ────────────────────────────────────────────────────────
 *
 *  1. `{field, value}` — l'égalité, souple (`==`), telle qu'elle a toujours été. Toutes les
 *     conditions existantes portent des booléens (`hasPortefeuille`, `hasPisteDerivee`…) et
 *     gardent exactement leur comportement.
 *
 *  2. `{field, present}` — la PRÉSENCE. Certains drapeaux ne sont pas des booléens mais des
 *     libellés : « Effort commercial : Alice » sert à la fois de voyant sur la ligne et de
 *     drapeau pour les actions. On ne peut pas l'écrire `value: true` — en JavaScript,
 *     `'Effort commercial : Alice' == true` vaut FALSE (les deux termes passent par un
 *     nombre, et la chaîne y devient NaN). L'action serait restée invisible, sans erreur.
 *     Un champ vide, `null` ou absent compte pour absent ; tout le reste pour présent.
 */

/**
 * @param {object} entityData - le `data-entity` de la ligne sélectionnée.
 * @param {{field: string, value?: *, present?: boolean}|null|undefined} condition
 * @returns {boolean}
 */
export function conditionRemplie(entityData, condition) {
    if (!condition) return true;

    const valeur = (entityData || {})[condition.field];

    if (Object.prototype.hasOwnProperty.call(condition, 'present')) {
        const renseigne = valeur !== null && valeur !== undefined && valeur !== '';

        return renseigne === (condition.present === true);
    }

    // eslint-disable-next-line eqeqeq -- égalité SOUPLE délibérée : le `data-entity` rend
    // parfois « false » en chaîne, et toutes les conditions existantes en dépendent.
    return valeur == condition.value;
}
