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
 * Des VERBES D'USAGER dans la ligne REPLIÉE : ni « planification », ni « trousse »,
 * ni nom de modèle. Pendant l'attente, l'utilisateur veut savoir où en est son
 * travail, pas comment l'application est découpée.
 *
 * ⚠ LA PARTIE DÉPLIÉE, ELLE, MONTRE LES ROUAGES — et c'est exactement ce qu'on vient
 * y chercher. Qui a répondu, avec quel modèle, quels outils ont lu quelles données,
 * ce que chaque phase a coûté et duré : c'est la cuisine interne de Ket, et la
 * cacher n'a jamais rassuré personne. Les deux registres coexistent donc, chacun à
 * sa place : le verbe en tête de ligne, la mécanique en dessous.
 */
export const VERBES = {
    // Une question acceptée mais qui attend son tour : elle n'est PAS en train
    // d'être traitée, et le dire évite de laisser croire que Ket travaille sur
    // trois choses à la fois — elle n'en traite jamais qu'une.
    attente:       'a bien reçu, votre message attend son tour…',
    comprehension: 'réfléchit…',
    clarification: 'demande une précision…',
    planification: 'prépare le travail…',
    outils:        'consulte vos données…',
    redaction:     'rédige la réponse…',
    ecriture:      'écrit…',
};

/**
 * CE QUE CHAQUE PHASE FAIT RÉELLEMENT, en une phrase.
 *
 * Le verbe dit où l'on en est ; celle-ci dit ce qui s'y passe. Elle ne s'affiche que
 * dans la partie dépliée — répétée à chaque ligne pendant l'attente, elle serait du
 * bruit ; lue une fois, quand on ouvre le détail, elle explique la facture.
 *
 * Écrites pour un COURTIER, pas pour un ingénieur : on nomme le geste métier
 * (« relire votre question », « lire vos données »), jamais la classe qui l'exécute.
 */
export const EXPLICATIONS = {
    attente:       'La question est en file : Ket n’en traite qu’une à la fois.',
    comprehension: 'Ket relit votre question pour établir ce que vous voulez dire, avant d’agir.',
    clarification: 'La demande est ambiguë : Ket préfère demander plutôt que de deviner.',
    planification: 'Ket choisit les outils dont elle a besoin et prépare ses appels.',
    outils:        'Ket lit vos données dans l’application — aucun appel au modèle ici.',
    redaction:     'Ket met en forme la réponse à partir de ce qu’elle vient de lire.',
    ecriture:      'La réponse s’affiche au fil de sa rédaction.',
};

/**
 * L'explication d'une étape, ou une chaîne vide quand la clé est inconnue — un
 * serveur plus récent que le navigateur ne doit pas produire de ligne bancale.
 *
 * @param {string} cle
 * @returns {string}
 */
export function explicationEtape(cle) {
    return EXPLICATIONS[cle] || '';
}

/**
 * LES COULISSES D'UNE ÉTAPE, en une ligne : qui a répondu, avec quel modèle, quels
 * outils, et comment les jetons se répartissent.
 *
 * ⚠ LE MODÈLE EST CELUI QUI A RÉPONDU, pas celui qui est configuré : un 503 fait
 * basculer sur un secours, et c'est ce nom-là qui remonte. Afficher le modèle
 * configuré ferait mentir l'écran au moment précis où l'on se demande pourquoi une
 * réponse est moins bonne que d'habitude.
 *
 * Rend une liste de fragments plutôt qu'une chaîne : l'appelant en fait des éléments
 * distincts, et peut styler le modèle autrement que le reste.
 *
 * @param {{moteur?: string, modele?: string, modeles?: string[], origine?: string,
 *          outils?: string[], entree?: number,
 *          sortie?: number, cache?: number, tours?: number, ms?: number}} etape
 * @param {string} locale
 * @returns {string[]}
 */
export function coulissesEtape(etape, locale = 'fr-FR') {
    // (`dureeEtape` est déclarée plus bas : une déclaration `function` est hissée.)
    if (!etape) return [];
    const fragments = [];

    // CE QUI S'EST PASSÉ À LA PLACE DU MODÈLE. La compréhension ne l'appelle pas
    // toujours : une salutation est court-circuitée, et un appel qui échoue laisse
    // la main à une heuristique locale. Le dire vaut mieux que de laisser une ligne
    // muette — et bien mieux que de nommer un modèle qui n'a rien fait.
    if (etape.origine === 'court-circuit') {
        fragments.push('comprise sans appeler le modèle');
    } else if (etape.origine === 'repli') {
        fragments.push('le modèle n’a pas répondu — compréhension locale');
    }

    if (etape.modele) {
        // Le moteur ET le modèle : « gemini » seul ne dit pas lequel a répondu, et
        // c'est justement la question.
        fragments.push(etape.moteur ? `${etape.moteur} · ${etape.modele}` : etape.modele);
    }

    // LE REPLI SE DIT. Quand une phase a été jouée par plusieurs modèles, c'est que
    // le principal a lâché (503 ou 429) et qu'un secours a pris la main. Ne montrer
    // que celui qui a fini serait vrai mais tairait la seule chose qui explique une
    // réponse en dessous de l'ordinaire. On nomme donc les abandonnés, dans l'ordre.
    const abandonnes = Array.isArray(etape.modeles)
        ? etape.modeles.filter((m) => m !== etape.modele)
        : [];
    if (abandonnes.length) {
        fragments.push(`après repli de ${abandonnes.join(', ')}`);
    }

    if (etape.tours > 1) {
        fragments.push(`${etape.tours} allers-retours`);
    }

    // LE TEMPS PASSÉ CHEZ LE FOURNISSEUR, distinct de la durée de l'étape affichée
    // en tête de ligne. Une phase peut durer huit secondes dont sept chez Gemini et
    // une à assembler ce qu'on lui envoie : les deux chiffres côte à côte disent
    // s'il faut changer de modèle ou alléger le contexte. Un seul ne dit rien.
    if (etape.msModele) {
        fragments.push(`${dureeEtape(etape.msModele, locale)} chez le modèle`);
    }

    // LA VENTILATION DES JETONS. « Entrée » est ce qu'on ENVOIE (instructions, outils,
    // historique), « sortie » ce que le modèle écrit. Le cache est compté à part
    // parce qu'il ne se facture pas au même prix — et qu'un cache qui ne sert jamais
    // est la première chose à regarder quand une conversation devient chère.
    const ventilation = [];
    if (etape.entree) ventilation.push(`${formatNombre(etape.entree, 0, locale)} envoyés`);
    if (etape.sortie) ventilation.push(`${formatNombre(etape.sortie, 0, locale)} écrits`);
    if (etape.cache) ventilation.push(`${formatNombre(etape.cache, 0, locale)} relus en cache`);
    if (ventilation.length) fragments.push(ventilation.join(', '));

    if (Array.isArray(etape.outils) && etape.outils.length) {
        fragments.push(`outils : ${etape.outils.join(', ')}`);
    }

    return fragments;
}

/**
 * La durée d'une étape, en toutes lettres. Sous la seconde, on donne les
 * millisecondes : « 0,0 s » ferait croire à une mesure ratée.
 *
 * @param {number} ms
 * @param {string} locale
 * @returns {string}
 */
export function dureeEtape(ms, locale = 'fr-FR') {
    if (!ms || ms < 0) return '';

    return ms < 1000 ? `${ms} ms` : `${formatNombre(ms / 1000, 1, locale)} s`;
}

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
