/**
 * Cœur PUR de la LECTURE À VOIX HAUTE des réponses de Ket.
 *
 * La synthèse vocale est celle du navigateur (`speechSynthesis`) : gratuite, sans
 * serveur ni token. Ce module ne parle pas lui-même ; il prépare ce qui sera dit
 * et choisit qui le dit :
 * - `texteAPrononcer` : le Markdown SOURCE de la bulle → un texte qui s'écoute
 *   (une voix qui lit « astérisque astérisque » ou un tableau cellule par cellule
 *   n'est plus une lecture, c'est un défaut) ;
 * - `decouperEnPhrases` : des segments courts, parce que Chrome interrompt un
 *   énoncé long au bout d'une quinzaine de secondes ;
 * - `choisirVoix` : la meilleure voix FÉMININE disponible, neuronale d'abord.
 *
 * Aucun DOM, aucune API du navigateur : testable sous `node --test tests/js/`.
 */

/** Débit et hauteur : diction vive et claire, sans effet robotique. */
export const DEBIT = 1.0;
export const HAUTEUR = 1.05;

/** Longueur maximale d'un segment prononcé d'un seul tenant. */
export const SEGMENT_MAX = 220;

/**
 * Voix féminines connues, par prénom (Edge « Natural », Windows, macOS, iOS).
 * Le prénom est la seule marque fiable : l'API n'expose pas le genre d'une voix.
 */
const PRENOMS_FEMININS = [
    // fr
    'denise', 'vivienne', 'eloise', 'coralie', 'jacqueline', 'josephine', 'yvette', 'celeste', 'brigitte',
    'sylvie', 'ariane', 'charline', 'amelie', 'audrey', 'aurelie', 'marie', 'julie', 'hortense', 'virginie',
    // en
    'aria', 'jenny', 'ava', 'emma', 'michelle', 'sonia', 'libby', 'natasha', 'clara', 'samantha', 'zira',
    'hazel', 'susan', 'karen', 'moira', 'tessa', 'serena',
];

/** Voix masculines connues : jamais retenues, même faute de mieux. */
const PRENOMS_MASCULINS = [
    'henri', 'paul', 'remy', 'thomas', 'claude', 'alain', 'antoine', 'fabrice', 'gerard', 'jerome', 'maxime',
    'yves', 'daniel', 'nicolas', 'jean', 'thierry', 'guy', 'andrew', 'brian', 'christopher', 'eric', 'roger', 'steffan',
    'ryan', 'william', 'david', 'mark', 'george', 'james', 'alex', 'fred', 'oliver', 'rishi', 'aaron', 'arthur',
];

/** Forme de comparaison : minuscules, sans accents. */
const cle = (texte) => String(texte ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const contientPrenom = (nom, prenoms) => prenoms.some((p) => new RegExp(`(^|[^a-z])${p}([^a-z]|$)`).test(nom));

/**
 * Note d'une voix pour la langue voulue (0 = inutilisable). Plus haut = meilleur.
 *
 * @param {{name?: string, lang?: string, localService?: boolean}} voix
 * @param {string} langue « fr » ou « en »
 */
export function noteVoix(voix, langue) {
    const nom = cle(voix?.name);
    const lang = cle(voix?.lang).replace('_', '-');
    if (!lang.startsWith(langue)) return 0;
    if (contientPrenom(nom, PRENOMS_MASCULINS)) return 0;

    const feminine = contientPrenom(nom, PRENOMS_FEMININS);
    const neuronale = /natural|neural|online/.test(nom);
    // « Google français » / « Google US English » : voix féminines en ligne de Chrome.
    const google = nom.startsWith('google');

    // Neuronale féminine (Edge) > Google (en ligne, féminine) > système féminine > quelconque.
    let note = 1;
    if (feminine) note += 10;
    if (google) note += 15;
    if (neuronale) note += 20;
    // La langue principale du pays passe devant les variantes (fr-FR avant fr-CA).
    if (lang === (langue === 'fr' ? 'fr-fr' : 'en-us')) note += 2;

    return note;
}

/**
 * La meilleure voix disponible pour la langue de l'interface, ou null.
 *
 * @template V
 * @param {V[]} voix résultat de speechSynthesis.getVoices()
 * @param {string} langue « fr » ou « en »
 * @returns {V|null}
 */
export function choisirVoix(voix, langue = 'fr') {
    let meilleure = null;
    let meilleureNote = 0;
    for (const v of voix ?? []) {
        const note = noteVoix(v, langue);
        if (note > meilleureNote) {
            meilleure = v;
            meilleureNote = note;
        }
    }
    return meilleure;
}

/** Une ligne de tableau Markdown → ses cellules. */
const cellules = (ligne) => ligne.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map((c) => c.trim());

/**
 * Tableaux Markdown → une phrase par ligne : « En-tête : valeur, En-tête : valeur. »
 * Lu cellule par cellule, un tableau devient une suite de nombres sans repère.
 */
function tableauxEnPhrases(texte) {
    const lignes = texte.split('\n');
    const sortie = [];
    for (let i = 0; i < lignes.length; i++) {
        const estTableau = /^\s*\|.*\|\s*$/.test(lignes[i]) && /^\s*\|?[\s:|-]+\|?\s*$/.test(lignes[i + 1] ?? '')
            && (lignes[i + 1] ?? '').includes('-');
        if (!estTableau) {
            sortie.push(lignes[i]);
            continue;
        }
        const entetes = cellules(lignes[i]);
        i += 2; // en-tête + séparateur
        for (; i < lignes.length && /^\s*\|.*\|\s*$/.test(lignes[i]); i++) {
            const valeurs = cellules(lignes[i]);
            const paires = valeurs
                .map((valeur, k) => (valeur === '' ? '' : (entetes[k] ? `${entetes[k]} : ${valeur}` : valeur)))
                .filter((p) => p !== '');
            if (paires.length) sortie.push(`${paires.join(', ')}.`);
        }
        i--;
    }
    return sortie.join('\n');
}

/**
 * Le Markdown d'une réponse → le texte à prononcer.
 *
 * @param {string} markdown source de la bulle (data-md-source)
 * @returns {string}
 */
export function texteAPrononcer(markdown) {
    let texte = String(markdown ?? '').replace(/\r\n?/g, '\n');

    // Graphiques : leur JSON ne se lit pas ; on dit qu'il y en a un.
    texte = texte.replace(/```(?:chart|graphique)[\s\S]*?(?:```|$)/gi, '\nGraphique affiché à l\'écran.\n');
    // Autres blocs de code : le contenu seul, sans les clôtures.
    texte = texte.replace(/```[a-z]*\n?([\s\S]*?)```/gi, '$1');

    texte = tableauxEnPhrases(texte);

    texte = texte
        .replace(/!\[([^\]]*)\]\([^)]*\)/g, '$1') // images
        .replace(/\[([^\]]+)\]\([^)]*\)/g, '$1') // liens
        .replace(/`([^`]*)`/g, '$1') // code en ligne
        .replace(/^\s{0,3}#{1,6}\s+(.*)$/gm, '$1.') // titres → phrase
        .replace(/^\s*>\s?/gm, '') // citations
        .replace(/^\s*(?:[-*+•]|\d+[.)])\s+/gm, '') // puces et numéros
        .replace(/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/gm, '') // séparateurs
        .replace(/(\*\*|__)(.*?)\1/g, '$2') // gras
        .replace(/(^|[\s(])[*_]([^*_\n]+)[*_](?=[\s).,;:!?]|$)/g, '$1$2') // italique
        .replace(/~~(.*?)~~/g, '$1');

    // Emojis et pictogrammes : aucune voix ne les dit bien.
    texte = texte.replace(/[\p{Extended_Pictographic}\u{FE0F}\u{200D}\u{20E3}]/gu, '');

    // Chaque ligne est une unité de sens : une ligne sans ponctuation finale en reçoit une,
    // pour que la voix marque la pause au lieu d'enchaîner deux éléments de liste.
    return texte
        .split('\n')
        .map((ligne) => ligne.replace(/\s+/g, ' ').trim())
        .filter((ligne) => ligne !== '')
        .map((ligne) => (/[.!?:;,…]$/.test(ligne) ? ligne : `${ligne}.`))
        .join(' ')
        // Les espaces avant « : ; ! ? » restent : c'est la typographie française.
        .replace(/\s+([.,])/g, '$1')
        .replace(/:\./g, ':')
        .trim();
}

/**
 * Découpe un texte en segments prononçables d'un seul tenant : aux fins de phrase
 * d'abord, puis aux virgules, puis aux espaces pour une phrase démesurée. Rien n'est
 * perdu : la jointure des segments redonne le texte.
 *
 * @param {string} texte
 * @param {number} max
 * @returns {string[]}
 */
export function decouperEnPhrases(texte, max = SEGMENT_MAX) {
    const phrases = String(texte ?? '').match(/[^.!?…]+[.!?…]+["»)]*\s*|[^.!?…]+$/g) ?? [];
    const segments = [];
    let courant = '';

    const pousser = (morceau) => {
        const m = morceau.trim();
        if (m === '') return;
        if (m.length <= max) {
            segments.push(m);
            return;
        }
        // Phrase trop longue : virgules, puis espaces.
        let reste = m;
        while (reste.length > max) {
            let coupe = reste.lastIndexOf(', ', max);
            if (coupe < max / 3) coupe = reste.lastIndexOf(' ', max);
            if (coupe <= 0) coupe = max;
            segments.push(reste.slice(0, coupe + 1).trim());
            reste = reste.slice(coupe + 1).trim();
        }
        if (reste !== '') segments.push(reste);
    };

    for (const phrase of phrases) {
        if ((courant + phrase).trim().length <= max) {
            courant += phrase;
            continue;
        }
        pousser(courant);
        courant = phrase;
    }
    pousser(courant);

    return segments;
}
