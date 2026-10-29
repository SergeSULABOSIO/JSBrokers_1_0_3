/**
 * Logique PURE du RAIL DES CHIPS (refonte de la colonne 3, C3) — AUCUN accès au DOM.
 *
 * Au rail, chaque groupe de chips n'est plus qu'un bouton à icône, à droite de la liste ; ses
 * chips s'ouvrent dans un panneau en surimpression. Deux décisions vivent ici :
 *
 *  - etatDuGroupe() : ce que le bouton DIT — une pastille quand le groupe masque des données,
 *    et son nom accessible « Prime : Impayée » (le texte complet est sur la ligne des filtres).
 *  - transitionDuPanneau() : la machine à états du panneau.
 */

export const PANNEAU_FERME = 'ferme';
/** Ouvert par le POINTEUR (survol stable ou clic) : il se ferme dès que le pointeur s'en va. */
export const APERCU = 'survol';
/** Ouvert au CLAVIER : il reste tant que le focus est dedans. */
export const PANNEAU_EPINGLE = 'epingle';

/**
 * Ce que le bouton d'un groupe annonce, d'après ses chips.
 *
 * Le chip actif dit tout : « Toutes » (valeur vide) ne masque rien ; toute autre valeur —
 * le défaut d'un groupe sans « Toutes » compris, un chip-sélecteur qui porte un nom —
 * masque des données. La maille de lecture (groupe de présentation) ne masque jamais rien.
 *
 * @param {{titre: string, presentation?: boolean,
 *          chips: Array<{valeur: (string|null), libelle: string, actif: boolean}>}} groupe
 *        `valeur` null = chip-sélecteur (il porte le nom choisi, pas une valeur).
 * @returns {{filtre: boolean, nom: string}}
 */
export function etatDuGroupe({ titre, presentation = false, chips = [] }) {
    const actif = chips.find((chip) => chip.actif);
    if (!actif) return { filtre: false, nom: titre };

    return {
        filtre: !presentation && actif.valeur !== '',
        nom: `${titre} : ${actif.libelle}`,
    };
}

/**
 * LA MACHINE À ÉTATS DU PANNEAU (Serge, 2026-10-29 : « le curseur a déjà quitté la pilule, mais
 * elle continue à s'afficher » ; « la perte du focus devrait l'obliger à se refermer »).
 *
 * AUCUN GESTE DU POINTEUR N'ÉPINGLE : un aperçu — ouvert au survol ou au clic, et même après
 * qu'on y a choisi un chip — se ferme dès que le pointeur quitte le rail et le panneau. Ouvert
 * au CLAVIER, il reste tant que le focus y est. Dans tous les cas : Échap, un clic dehors, ou
 * la sortie du focus le ferment.
 *
 * Actions :
 *  - `survol`     {groupe}           survol STABLE d'un bouton du rail
 *  - `clicGroupe` {groupe, clavier}  clic sur un bouton (`clavier` : sans pointeur)
 *  - `sortie`                        le pointeur, ou le focus, a quitté le rail et le panneau
 *  - `echap`, `exterieur`            Échap ; clic hors du rail et du panneau
 *
 * Toute action inconnue laisse l'état intact.
 *
 * @param {{etat: string, groupe: (string|null)}} courant
 * @param {{type: string, groupe?: (string|null), clavier?: boolean}} action
 * @returns {{etat: string, groupe: (string|null)}}
 */
export function transitionDuPanneau(courant, action) {
    const etat = courant?.etat ?? PANNEAU_FERME;
    const groupe = courant?.groupe ?? null;
    const ferme = { etat: PANNEAU_FERME, groupe: null };

    switch (action?.type) {
        case 'survol':
            // Épinglé au clavier sur CE groupe : le survol ne le déclasse pas en aperçu.
            if (etat === PANNEAU_EPINGLE && groupe === action.groupe) return courant;
            return { etat: APERCU, groupe: action.groupe ?? null };

        case 'clicGroupe':
            // Re-cliquer le groupe ouvert le referme.
            if (etat !== PANNEAU_FERME && groupe === action.groupe) return ferme;
            return { etat: action.clavier ? PANNEAU_EPINGLE : APERCU, groupe: action.groupe ?? null };

        case 'sortie':
        case 'echap':
        case 'exterieur':
            return ferme;

        default:
            return courant ?? ferme;
    }
}
