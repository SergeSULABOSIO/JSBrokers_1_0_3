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

/**
 * Session neuve.
 *
 * L'OREILLE PAR DÉFAUT EST CELLE DU NAVIGATEUR quand il en a une. Mesuré le
 * 2026-09-18 : la transcription serveur coûte à peu près la durée de la phrase (3,2 s
 * pour 3,2 s) et des crédits, là où la reconnaissance du navigateur travaille PENDANT
 * qu'on parle — le texte est prêt à la seconde où l'on se tait. Les oreilles du
 * serveur restent le repli : Firefox, navigateurs sans reconnaissance, ou panne.
 */
export function sessionInitiale(oreille = 'navigateur') {
    return { etat: ETATS.ARRET, oreille, derniereParole: '', secours: false };
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
    const s = { ...sessionInitiale(session?.oreille), ...session, actions: [] };
    const avec = (etat, actions = [], champs = {}) => ({ ...s, ...champs, etat, actions });

    switch (evenement) {
        case 'demarrer':
            if (s.etat !== ETATS.ARRET) return s;
            return avec(ETATS.ECOUTE, [
                'ouvrir-micro',
                'precharger-intermedes',
                'garder-ecran-allume',
                ...(s.oreille === 'navigateur' ? ['oreille-navigateur'] : []),
            ]);

        case 'arreter':
            return s.etat === ETATS.ARRET ? s : avec(ETATS.ARRET, ['fermer-micro', 'couper-voix', 'liberer-ecran']);

        // L'utilisateur parle : pendant que Ket parle, c'est une INTERRUPTION.
        case 'voix-detectee':
            if (s.etat === ETATS.PAROLE) {
                return avec(ETATS.ECOUTE, ['couper-voix']);
            }
            return s;

        // Le serveur écoute : la phrase part en transcription, et un intermède comble
        // ce temps-là aussi. Avec l'oreille du navigateur, le texte arrive directement
        // (« texte-entendu ») et cet état ne dure pas.
        case 'phrase-terminee':
            return s.etat === ETATS.ECOUTE ? avec(ETATS.TRANSCRIPTION, ['transcrire', 'programmer-intermedes']) : s;

        // Rien n'a été compris : on réécoute, sans déranger Ket.
        case 'silence':
            return s.etat === ETATS.TRANSCRIPTION ? avec(ETATS.ECOUTE, ['couper-intermedes']) : s;

        // L'oreille du navigateur rend le texte sans passer par la transcription serveur :
        // l'écoute mène donc directement à la réflexion.
        case 'texte-entendu': {
            if (s.etat !== ETATS.TRANSCRIPTION && s.etat !== ETATS.ECOUTE) return s;
            const texte = String(charge.texte ?? '').trim();
            if (texte === '') return avec(ETATS.ECOUTE, ['couper-intermedes']);
            return avec(ETATS.REFLEXION, ['envoyer-question', 'programmer-intermedes'], { derniereParole: texte });
        }

        // Les oreilles du serveur ne répondent pas : le navigateur écoute lui-même.
        case 'oreille-indisponible':
            if (s.etat !== ETATS.TRANSCRIPTION) return s;
            return avec(ETATS.ECOUTE, ['oreille-navigateur', 'couper-intermedes'], { oreille: 'navigateur', secours: true });

        // La reconnaissance du navigateur manque ou refuse : les oreilles du serveur
        // prennent le relais, phrase par phrase.
        case 'oreille-serveur':
            return s.etat === ETATS.ARRET ? s : avec(s.etat, [], { oreille: 'serveur' });

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
