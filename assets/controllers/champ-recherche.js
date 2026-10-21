import { echapper } from './echapper.js';

/**
 * Les attributs d'un champ de recherche fabriqué EN JAVASCRIPT (chantier 16).
 *
 * Jumelle de `App\Twig\Extension\ChampRechercheExtension` — MÊME sens, mêmes protections,
 * même formulaire inexistant ; `ChampsDeRechercheProtegesTest` vérifie que les deux disent la
 * même chose. Voir l'extension pour le pourquoi de chaque attribut.
 *
 *   attributsChampRecherche()            → les protections seules (le champ reste dans son
 *                                          formulaire : il est lu en direct, ou c'est un vrai champ)
 *   attributsChampRecherche('jsb-filtre') → + nom neutre et `form` inexistant : le champ n'est
 *                                          envoyé avec AUCUN formulaire
 */
export const PROTECTIONS_CHAMP_RECHERCHE = 'autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore data-bwignore data-form-type="other" data-champ-recherche';

export const FORMULAIRE_INEXISTANT = 'jsb-hors-formulaire';

export function attributsChampRecherche(nomHorsFormulaire = null) {
    if (nomHorsFormulaire === null) {
        return PROTECTIONS_CHAMP_RECHERCHE;
    }

    return `name="${echapper(nomHorsFormulaire)}" form="${FORMULAIRE_INEXISTANT}" ${PROTECTIONS_CHAMP_RECHERCHE}`;
}
