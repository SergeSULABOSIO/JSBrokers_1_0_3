/**
 * LA VEILLE DE L'ÉCOUTE — sortir du silence où deux mécanismes s'attendent.
 *
 * ── L'IMPASSE (production, sur téléphone, 2026-09-23) ───────────────────────
 *
 * Le panneau affiche « Ket vous écoute… », l'utilisateur parle, et rien ne
 * remonte. Jamais. Aucun message, aucune erreur — et la dictée, elle, fonctionne
 * parfaitement sur le même appareil.
 *
 * DEUX MÉCANISMES QUI SE TIENNENT EN ÉCHEC. Le mode Live ouvre un flux d'analyse
 * (`getUserMedia`) pour entendre l'utilisateur couper Ket. Sur téléphone, ce flux
 * PRIVE la reconnaissance du navigateur de micro : elle ne rend plus un mot, sans
 * erreur ni message. Le projet connaissait ce défaut et lui avait donné son
 * remède — `_laisserLeMicroALaReconnaissance()`, qui abandonne le flux pour le
 * lui rendre.
 *
 * MAIS CE REMÈDE NE SE DÉCLENCHAIT QUE SI NOTRE MICRO DÉTECTAIT UNE PHRASE
 * ENTIÈRE. Or un `AudioContext` naît SUSPENDU sur téléphone : quand le réveil
 * échoue, aucune trame n'arrive — donc aucune phrase n'est détectée, donc le
 * soupçon ne se lève jamais, donc le micro n'est jamais rendu, donc la
 * reconnaissance reste sourde. Chacun attend l'autre, et la session tourne dans
 * le vide jusqu'à ce que l'utilisateur abandonne.
 *
 * ── CE QUI EN SORT ─────────────────────────────────────────────────────────
 *
 * Une veille au TEMPS, qui ne dépend plus des trames — puisque c'est justement
 * leur absence qui verrouille tout.
 *
 * ET ELLE NE COÛTE RIEN LÀ OÙ ELLE SE DÉCLENCHE. Elle ne s'arme que si AUCUNE
 * trame n'est jamais arrivée : un micro vivant en livre une dizaine par seconde,
 * donc zéro trame après plusieurs secondes signifie que notre capture est de
 * toute façon aveugle. Lâcher un micro dont on ne tire rien ne fait perdre
 * strictement aucune fonction — et rend à la reconnaissance ce qui lui manquait.
 * Sur un poste où tout va bien, les trames arrivent et cette veille ne fait
 * jamais rien.
 *
 * Si le silence persiste même après cela, on le DIT. Une session qui affiche
 * « Ket vous écoute… » devant quelqu'un que personne n'écoute est la pire des
 * réponses — c'est la règle que tout ce module applique.
 */

/**
 * Sans UNE SEULE trame passé ce délai, notre capture est aveugle : on la lâche.
 *
 * Un micro vivant livre une trame toutes les ~85 ms. Cinq secondes sans rien,
 * ce n'est pas du silence : c'est un contexte audio qui n'a jamais démarré.
 */
export const SANS_TRAME_AVANT_DE_LACHER_MS = 5000;

/**
 * Silence total avant de l'écrire à l'utilisateur.
 *
 * Généreux À DESSEIN : quelqu'un qui réfléchit avant de parler ne doit pas voir
 * un avertissement. On ne parle qu'après avoir tenté le remède et laissé le
 * temps de dire une phrase.
 */
export const SILENCE_AVANT_DE_LE_DIRE_MS = 30000;

/** Ce qu'on affiche quand, malgré tout, rien n'est jamais parvenu. */
export const RIEN_ENTENDU = 'Rien ne parvient du micro. Vérifiez qu’aucune autre application ne l’utilise, puis rechargez la page.';

/**
 * Que faire d'une écoute qui ne rend rien ?
 *
 * @param {object} etat
 * @param {number} etat.depuisMs      durée de l'écoute en cours
 * @param {number} etat.textesRecus   phrases rendues par la reconnaissance depuis le début
 * @param {number} etat.tramesRecues  trames livrées par notre capture depuis le début
 * @param {boolean} etat.microLache   a-t-on déjà rendu le micro à la reconnaissance
 *
 * @returns {{action: 'rien'|'lacher-le-micro'|'le-dire', raison: string}}
 */
export function veilleDeLEcoute({ depuisMs = 0, textesRecus = 0, tramesRecues = 0, microLache = false } = {}) {
    // Quelque chose a été entendu : il n'y a rien à surveiller.
    if (textesRecus > 0) {
        return { action: 'rien', raison: '' };
    }

    // NOTRE CAPTURE EST AVEUGLE et elle tient peut-être le micro de la
    // reconnaissance. On le lui rend : c'est gratuit, puisqu'on n'en tirait rien.
    if (!microLache && tramesRecues === 0 && depuisMs >= SANS_TRAME_AVANT_DE_LACHER_MS) {
        return { action: 'lacher-le-micro', raison: '' };
    }

    // Le remède a été tenté, le temps a été laissé, et toujours rien.
    if (depuisMs >= SILENCE_AVANT_DE_LE_DIRE_MS) {
        return { action: 'le-dire', raison: RIEN_ENTENDU };
    }

    return { action: 'rien', raison: '' };
}
