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
 * l'atteint tout juste est, par construction, à la limite de l'audible.
 *
 * RAMENÉ DE 3 À 2 LE 2026-09-20 : « il faut crier pour qu'elle écoute ». Trois fois le
 * seuil, soit près de sept fois le bruit de la pièce, exigeait une voix forte — et un
 * téléphone tenu à bout de bras n'y arrive pas. Deux reste très au-dessus d'une
 * télévision lointaine, qui frôle le seuil sans le doubler. Ce nombre n'a rien d'une
 * vérité : chaque rejet journalise sa marge, précisément pour le régler sur des chiffres
 * réels plutôt qu'au jugé.
 */
export const MARGE_PROCHE = 2;

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

/**
 * Combien de mots consécutifs de Ket il faut reconnaître avant de retrancher quoi que
 * ce soit. Quatre : en deçà, deux phrases françaises partagent trop facilement un début
 * (« est-ce que vous », « dans les trois ») et l'on mutilerait une vraie question.
 */
export const MOTS_AVANT_RETRAIT = 4;

/**
 * LES FINALES QU'ON N'A PAS ENCORE LUES, et elles seules.
 *
 * `event.results` est la liste CUMULÉE de la session de reconnaissance : la relire en
 * entier renvoie les phrases déjà posées. Un compteur suffit — à une condition, apprise
 * en production le 2026-09-20 : sur Android, la reconnaissance CLÔT sa session à chaque
 * phrase et repart. Quand elle repart, la liste recommence à zéro, et un compteur resté
 * en l'état ne lirait plus rien ; quand elle ne repart pas, le remettre à zéro relirait
 * TOUT. D'où la règle : c'est la liste qui dit si elle a recommencé, pas nous.
 *
 * @param {{length: number}} resultats la liste de l'événement
 * @param {number} dejaLues combien de ses éléments ont déjà été traités
 * @returns {{finales: object[], lues: number}}
 */
export function finalesNouvelles(resultats, dejaLues = 0) {
    const total = resultats?.length ?? 0;
    // La liste a rétréci : une nouvelle session a commencé, tout y est neuf.
    let curseur = total < dejaLues ? 0 : dejaLues;
    const finales = [];
    for (let i = curseur; i < total; i++) {
        if (!resultats[i]?.isFinal) continue;
        finales.push(resultats[i]);
        curseur = i + 1;
    }

    return { finales, lues: curseur };
}

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
 * RETRANCHE DE CE QU'ON ENTEND LES MOTS QUE KET VIENT DE DIRE.
 *
 * L'INCIDENT (2026-09-19). Dans la bulle de l'utilisateur : « aucun avenant ne répertorié
 * avec une date VA VOIR AUSSI DANS LES PROCHAINS 90 JOURS ». Les premiers mots sont ceux
 * de Ket, la fin est bien celle de l'utilisateur. Le juge de provenance ne peut rien
 * ici : quelqu'un parlait VRAIMENT tout près du micro — c'est la reconnaissance du
 * navigateur, qui entend aussi le haut-parleur, qui a fondu les deux voix en une phrase.
 *
 * On ne compare donc pas des phrases entières (la reconnaissance déforme : « n'est » →
 * « ne »), mais des MOTS, dans l'ordre, depuis le début. Tant que le mot entendu se
 * retrouve dans ce que Ket disait, il lui appartient. Au premier mot qui n'y est pas,
 * l'utilisateur a pris la parole, et tout le reste est à lui.
 *
 * CONSERVATEUR : on ne retranche qu'à partir de quatre mots reconnus, et jamais au
 * milieu d'une phrase — seul un DÉBUT d'écho se retire. Si l'utilisateur reprend les
 * mots de Ket à dessein (« les trente polices échues, oui »), sa suite lui reste.
 *
 * @param {string} texte
 * @param {string[]} phrasesDeKet ce qu'elle vient de dire (réponse lue, intermèdes joués)
 * @returns {string} le texte sans son écho de tête
 */
export function retirerLaVoixDeKet(texte, phrasesDeKet = []) {
    const mots = String(texte ?? '').trim().split(/\s+/).filter((m) => m !== '');
    if (mots.length === 0) return '';

    let meilleur = 0;
    for (const phrase of phrasesDeKet) {
        const motsDeKet = cle(phrase).split(' ').filter((m) => m !== '');
        if (motsDeKet.length === 0) continue;

        let curseur = 0;
        let reconnus = 0;
        for (const mot of mots) {
            // Un « mot » entendu peut en valoir deux une fois normalisé : « laissez-moi »
            // devient « laissez moi ». On les compare donc tous, dans l'ordre.
            const morceaux = cle(mot).split(' ').filter((m) => m !== '');
            let tousTrouves = morceaux.length > 0;
            let curseurLocal = curseur;
            for (const morceau of morceaux) {
                // Les mots outils très courts (« n », « d », « ne ») sont des artefacts de
                // transcription : ils ne prouvent rien, mais ils ne cassent pas la série.
                if (morceau.length <= 2) continue;
                const trouve = motsDeKet.indexOf(morceau, curseurLocal);
                if (trouve === -1) { tousTrouves = false; break; }
                curseurLocal = trouve + 1;
            }
            if (!tousTrouves) break;
            curseur = curseurLocal;
            reconnus++;
        }
        meilleur = Math.max(meilleur, reconnus);
    }

    if (meilleur < MOTS_AVANT_RETRAIT || meilleur >= mots.length) {
        // Rien de reconnu, ou TOUT l'est : dans le second cas c'est un écho pur, et le
        // texte vidé sera écarté par phraseRecevable (motif « vide »).
        return meilleur >= mots.length ? '' : String(texte ?? '').trim();
    }

    return mots.slice(meilleur).join(' ');
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
    // SANS MICRO FIABLE, ON NE JUGE PAS DE LA PROVENANCE. L'absence de preuve n'est pas
    // une preuve d'absence : sur un téléphone, le contexte audio peut démarrer suspendu,
    // et aucune trame n'arrive alors au détecteur. Appliquer la règle de proximité dans
    // ces conditions revient à tout rejeter — Ket devient sourde, ce qui est bien pire
    // que de laisser passer un bruit. On garde le seul filtre qui ne dépend que du texte.
    const microFiable = demande.microFiable !== false;
    const prise = demande.priseDeParole ?? null;
    const instantMs = demande.instantMs ?? 0;
    const margeProche = demande.margeProche ?? MARGE_PROCHE;
    const marge = prise?.marge ?? 0;
    const verdict = (recevable, motif) => ({ recevable, motif, marge });

    if (texte === '') return verdict(false, 'vide');
    if (nEstQueDesTics(texte)) return verdict(false, 'tic');
    if (!microFiable) return verdict(true, 'sans-micro');

    // Une reconnaissance elle-même hésitante durcit l'exigence de preuve — jamais
    // l'inverse, et jamais seule : sur bien des navigateurs la confiance vaut zéro ou
    // rien du tout.
    const confiance = typeof demande.confiance === 'number' ? demande.confiance : null;
    const exigence = confiance !== null && confiance < CONFIANCE_DOUTEUSE ? margeProche * 1.5 : margeProche;

    return priseRecevable(prise, instantMs, exigence);
}
