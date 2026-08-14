import { formatNombre } from '../number-format.js';

/*
 * FIL D'ACTIVITÉ DU CHAT — traduire ce que fait le moteur en une ligne lisible.
 *
 * Entre le clic « envoyer » et le premier mot affiché, le chat n'avait rien à dire :
 * un « Ket réfléchit… » figé pendant que trois appels au modèle et une exécution
 * d'outils s'enchaînaient derrière. Vingt à quarante secondes de silence se lisent
 * comme une panne.
 *
 * Cœur PUR, sans DOM et sans Stimulus : c'est ce qui rend ces règles vérifiables
 * (cf. assistant-markdown-table.js, même parti pris).
 */

/**
 * Le verbe de chaque étape. Les clés viennent du serveur — les trois phases du
 * moteur portent le libellé de l'enum PHP `Phase`, les autres nomment des moments
 * qui ne sont pas des phases (l'exécution locale des outils, la frappe côté
 * navigateur).
 *
 * Des VERBES D'USAGER, jamais nos rouages : ni « planification », ni « trousse »,
 * ni nom de modèle. L'utilisateur veut savoir où en est son travail, pas comment
 * l'application est découpée.
 */
export const VERBES = {
    comprehension: 'réfléchit…',
    clarification: 'demande une précision…',
    planification: 'prépare le travail…',
    outils:        'consulte vos données…',
    redaction:     'rédige la réponse…',
    ecriture:      'écrit…',
};

/**
 * Verbe d'une étape. Une clé inconnue — serveur plus récent que le navigateur,
 * page ouverte pendant un déploiement — retombe sur l'attente générique plutôt
 * que d'afficher une clé technique.
 *
 * @param {string} cle
 * @returns {string}
 */
export function verbeEtape(cle) {
    return VERBES[cle] || VERBES.comprehension;
}

/**
 * Le compteur affiché à droite du verbe.
 *
 * « JETONS IA », jamais « tokens » : le mot « tokens » désigne déjà, dans cette
 * même interface, le solde facturé au cabinet (budget d'un plan, message de quota
 * épuisé). Deux compteurs sans rapport ne doivent pas porter le même nom — un
 * utilisateur qui voit « 24 324 tokens » défiler croirait vider son solde.
 *
 * Trois cas, et c'est tout :
 *   rien de consommé      → ''                                (pas de compteur)
 *   tout vient d'un appel → « 512 jetons IA »
 *   sinon                 → « +23 812 jetons IA (24 324 au total) »
 *
 * @param {{tokensEtape?: number, tokensCumul?: number}} etape
 * @param {string|null} [locale] Langue forcée (les tests n'ont pas de document).
 * @returns {string}
 */
export function compteurEtape({ tokensEtape = 0, tokensCumul = 0 } = {}, locale = null) {
    if (!tokensCumul) return '';

    const total = `${formatNombre(tokensCumul, 0, locale)} jetons IA`;
    if (!tokensEtape || tokensEtape === tokensCumul) return total;

    return `+${formatNombre(tokensEtape, 0, locale)} jetons IA (${formatNombre(tokensCumul, 0, locale)} au total)`;
}

/**
 * Découpe un morceau de flux en événements complets.
 *
 * Le serveur émet une ligne « data: {json} » par événement. Un morceau réseau ne
 * s'arrête pas sur une frontière de ligne : le reste incomplet est rendu à
 * l'appelant, qui le repassera collé au morceau suivant. Une ligne illisible est
 * ignorée — un fil d'activité ne doit jamais faire échouer un envoi.
 *
 * @param {string} tampon  Fragment laissé par l'appel précédent.
 * @param {string} morceau Texte fraîchement décodé.
 * @returns {[Array<object>, string]} Les événements complets, et le nouveau reste.
 */
export function decouperFlux(tampon, morceau) {
    const lignes = (tampon + morceau).split('\n');
    const reste = lignes.pop();
    const evenements = [];

    for (const ligne of lignes) {
        if (!ligne.startsWith('data: ')) continue;
        try {
            evenements.push(JSON.parse(ligne.slice(6)));
        } catch {
            // Ligne tronquée ou bruit : sans intérêt, et sans conséquence.
        }
    }

    return [evenements, reste];
}

/**
 * Résumé d'une seule ligne pour le récapitulatif sous la réponse.
 * Ex. « 3 appels · 38 400 jetons IA · 6,2 s ».
 *
 * @param {{appels?: number, jetonsIa?: number, secondes?: number}|null} activite
 * @param {string|null} [locale]
 * @returns {string} '' quand il n'y a rien d'honnête à afficher.
 */
export function resumeActivite(activite, locale = null) {
    if (!activite || !activite.jetonsIa) return '';

    const appels = activite.appels || 0;
    const parts = [
        `${appels} appel${appels > 1 ? 's' : ''}`,
        `${formatNombre(activite.jetonsIa, 0, locale)} jetons IA`,
    ];
    if (activite.secondes) parts.push(`${formatNombre(activite.secondes, 1, locale)} s`);

    return parts.join(' · ');
}
