/**
 * Cœur PUR du mode Live : L'ÉTAT DE LA CONVERSATION, et lui seul.
 *
 * Une session Live enchaîne des étapes — écouter, transcrire, attendre Ket, l'écouter
 * parler — et chacune change ce que l'interface montre, ce que le micro fait, et ce
 * qu'un événement suivant a le droit de provoquer. Écrire cela dans le contrôleur
 * Stimulus donnerait un enchevêtrement de drapeaux impossible à éprouver ; ici, c'est
 * une fonction pure, et les tests peuvent jouer toute une conversation.
 *
 * Un événement qui n'a pas de sens dans l'état courant est IGNORÉ (l'état ne bouge
 * pas) : une réponse tardive, un double clic ou une fin de lecture en retard ne doivent
 * jamais dérégler la session.
 */

export const ETATS = {
    ARRET: 'arret',
    ECOUTE: 'ecoute',
    TRANSCRIPTION: 'transcription',
    REFLEXION: 'reflexion',
    PAROLE: 'parole',
};

/** Ce que l'utilisateur lit pendant la session (jamais une couleur seule : du texte). */
export const LIBELLES = {
    [ETATS.ARRET]: 'Mode Live arrêté.',
    [ETATS.ECOUTE]: 'Ket vous écoute…',
    [ETATS.TRANSCRIPTION]: 'Transcription…',
    [ETATS.REFLEXION]: 'Ket réfléchit…',
    [ETATS.PAROLE]: 'Ket parle — parlez pour l’interrompre.',
};

/** Session neuve. */
export function sessionInitiale() {
    return { etat: ETATS.ARRET, oreille: 'serveur', derniereParole: '', secours: false };
}

/**
 * La transition. Rend TOUJOURS une nouvelle session (jamais de mutation), avec
 * éventuellement des ordres pour le contrôleur : `actions`.
 *
 * Événements : `demarrer`, `voix-detectee`, `phrase-terminee`, `texte-entendu`,
 * `silence`, `oreille-indisponible`, `reponse-affichee`, `lecture-terminee`,
 * `erreur`, `arreter`.
 *
 * @returns {{etat: string, oreille: string, derniereParole: string, secours: boolean, actions: string[]}}
 */
export function transition(session, evenement, charge = {}) {
    const s = { ...sessionInitiale(), ...session, actions: [] };
    const avec = (etat, actions = [], champs = {}) => ({ ...s, ...champs, etat, actions });

    switch (evenement) {
        case 'demarrer':
            return s.etat === ETATS.ARRET ? avec(ETATS.ECOUTE, ['ouvrir-micro', 'precharger-intermedes']) : s;

        case 'arreter':
            return s.etat === ETATS.ARRET ? s : avec(ETATS.ARRET, ['fermer-micro', 'couper-voix']);

        // L'utilisateur parle : pendant que Ket parle, c'est une INTERRUPTION.
        case 'voix-detectee':
            if (s.etat === ETATS.PAROLE) {
                return avec(ETATS.ECOUTE, ['couper-voix']);
            }
            return s;

        case 'phrase-terminee':
            return s.etat === ETATS.ECOUTE ? avec(ETATS.TRANSCRIPTION, ['transcrire']) : s;

        // Rien n'a été compris : on réécoute, sans déranger Ket.
        case 'silence':
            return s.etat === ETATS.TRANSCRIPTION ? avec(ETATS.ECOUTE) : s;

        case 'texte-entendu': {
            if (s.etat !== ETATS.TRANSCRIPTION) return s;
            const texte = String(charge.texte ?? '').trim();
            if (texte === '') return avec(ETATS.ECOUTE);
            return avec(ETATS.REFLEXION, ['envoyer-question', 'programmer-intermedes'], { derniereParole: texte });
        }

        // Les oreilles du serveur ne répondent pas : le navigateur écoute lui-même.
        case 'oreille-indisponible':
            if (s.etat !== ETATS.TRANSCRIPTION) return s;
            return avec(ETATS.ECOUTE, ['oreille-navigateur'], { oreille: 'navigateur', secours: true });

        case 'reponse-affichee':
            return s.etat === ETATS.REFLEXION ? avec(ETATS.PAROLE, ['couper-intermedes', 'lire-reponse']) : s;

        case 'lecture-terminee':
            return s.etat === ETATS.PAROLE ? avec(ETATS.ECOUTE) : s;

        // Une panne ne met jamais fin à la session : on réécoute.
        case 'erreur':
            return s.etat === ETATS.ARRET ? s : avec(ETATS.ECOUTE, ['couper-intermedes', 'couper-voix']);

        default:
            return s;
    }
}

/** Le texte d'état affiché et annoncé. */
export function libelleEtatLive(session) {
    const base = LIBELLES[session?.etat] ?? LIBELLES[ETATS.ARRET];

    return session?.secours && session?.etat === ETATS.ECOUTE ? `${base} (écoute de secours)` : base;
}
