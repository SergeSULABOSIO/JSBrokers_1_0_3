/**
 * Cœur PUR du mode Live : SÉPARER CE QUI VOUS EST ADRESSÉ DU RESTE.
 *
 * La reconnaissance du navigateur écoute tout et comprend tout : un collègue qui parle à
 * côté, une télévision dans la pièce, un « euh » de réflexion. Elle rend ce texte sans
 * jamais dire d'où il vient — et tout partait alors au moteur comme une question, entrait
 * dans le fil et coûtait des jetons.
 *
 * Le micro, lui, sait d'où venait la voix : une phrase dite à trente centimètres écrase
 * le bruit de la pièce, une télévision à trois mètres le frôle. C'est cette mesure — la
 * MARGE, rapport de la crête au seuil de parole du moment — qui tranche ici.
 *
 * TROIS MOTIFS DE REJET, et rien d'autre. En cas de doute on se tait : une vraie question
 * perdue se répète, une fausse question engage Ket sur une piste qui n'existe pas.
 *
 * Aucun micro, aucun DOM, aucune horloge implicite : testable sous `node --test tests/js/`.
 */

/**
 * Combien de fois le seuil de parole il faut dépasser pour être tenu pour proche.
 *
 * Le seuil de parole vaut déjà 2,2 fois le bruit appris de la pièce : une voix qui
 * l'atteint tout juste est, par construction, à la limite de l'audible. Le triple est le
 * point de départ retenu, à confirmer sur des mesures réelles — chaque rejet journalise
 * sa marge, précisément pour pouvoir régler ce nombre sur des chiffres et non au jugé.
 */
export const MARGE_PROCHE = 3;

/** En deçà, ce n'est pas une phrase : un claquement, une porte, une chaise. */
export const DUREE_MIN_MS = 300;

/**
 * Au-delà, la prise de parole observée est trop ancienne pour justifier ce texte-ci.
 *
 * La reconnaissance finalise une phrase environ une seconde après le dernier mot ; quatre
 * secondes laissent de la marge sans permettre à une salve d'il y a une minute de servir
 * d'alibi à un bruit d'aujourd'hui.
 */
export const FENETRE_MS = 4000;

/** Sous cette confiance, la reconnaissance elle-même doute — mais elle ne condamne pas seule. */
export const CONFIANCE_DOUTEUSE = 0.6;

/**
 * Les bruits de parole, et EUX SEULS.
 *
 * La même liste que la finition de dictée (FinisseurDeDictee), transposée ici pour ne pas
 * même envoyer ce qu'elle effacerait. « oui », « non », « arrête », « continue » n'y sont
 * pas et n'y seront jamais : ce sont des réponses, et les plus courtes sont souvent les
 * plus utiles dans une conversation.
 */
export const TICS = [
    'euh', 'euhh', 'heu', 'hum', 'hmm', 'hm', 'mmh', 'mm', 'hein', 'ah', 'ha', 'oh',
    'bah', 'ben', 'bon ben', 'voila', 'voilà', 'enfin bref', 'du coup', 'genre',
];

/** Forme de comparaison : minuscules, sans accents, sans ponctuation, espaces réduits. */
const cle = (texte) => String(texte ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9\s]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

/** Le texte ne contient-il QUE des bruits de parole ? */
export function nEstQueDesTics(texte) {
    const mots = cle(texte).split(' ').filter((mot) => mot !== '');
    if (mots.length === 0) return true;

    const tics = new Set(TICS.map((tic) => cle(tic)).flatMap((tic) => tic.split(' ')));

    return mots.every((mot) => tics.has(mot));
}

/**
 * CE QUE DIT LE MICRO, SEUL — sans attendre le moindre texte.
 *
 * C'est la moitié du tri qu'on peut rendre AVANT de faire transcrire une phrase par le
 * serveur : inutile de payer des crédits pour une télévision. L'autre moitié (les
 * hésitations) demande le texte, et vient après.
 *
 * @returns {{recevable: boolean, motif: string, marge: number}}
 */
export function priseRecevable(prise, instantMs = 0, margeProche = MARGE_PROCHE) {
    const marge = prise?.marge ?? 0;
    const verdict = (recevable, motif) => ({ recevable, motif, marge });

    // AUCUNE VOIX N'A ÉTÉ ENTENDUE devant ce micro-ci.
    if (!prise) return verdict(false, 'muet');
    if (!prise.enCours && instantMs - prise.finMs > FENETRE_MS) return verdict(false, 'muet');

    // Une salve trop brève n'est pas une phrase. Une salve EN COURS y échappe : la
    // reconnaissance peut finaliser un premier mot avant que la phrase soit finie.
    if (!prise.enCours && prise.dureeMs < DUREE_MIN_MS) return verdict(false, 'souffle');

    // LE FILTRE QUI COMPTE : de trop loin, ce n'était pas pour Ket.
    if (marge < margeProche) return verdict(false, 'loin');

    return verdict(true, 'recevable');
}

/**
 * CE TEXTE VOUS EST-IL ADRESSÉ ? Rend le verdict et son motif.
 *
 * @param {{texte: string, confiance?: number, priseDeParole?: object|null, instantMs?: number,
 *          margeProche?: number}} demande
 * @returns {{recevable: boolean, motif: string, marge: number}}
 *   motif : « recevable », « vide », « tic », « souffle », « loin », « muet »
 */
export function phraseRecevable(demande = {}) {
    const texte = String(demande.texte ?? '').trim();
    const prise = demande.priseDeParole ?? null;
    const instantMs = demande.instantMs ?? 0;
    const margeProche = demande.margeProche ?? MARGE_PROCHE;
    const marge = prise?.marge ?? 0;
    const verdict = (recevable, motif) => ({ recevable, motif, marge });

    if (texte === '') return verdict(false, 'vide');
    if (nEstQueDesTics(texte)) return verdict(false, 'tic');

    // Une reconnaissance elle-même hésitante durcit l'exigence de preuve — jamais
    // l'inverse, et jamais seule : sur bien des navigateurs la confiance vaut zéro ou
    // rien du tout.
    const confiance = typeof demande.confiance === 'number' ? demande.confiance : null;
    const exigence = confiance !== null && confiance < CONFIANCE_DOUTEUSE ? margeProche * 1.5 : margeProche;

    return priseRecevable(prise, instantMs, exigence);
}
