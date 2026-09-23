/**
 * CE QU'ON FAIT QUAND LE MICRO D'ANALYSE EST REFUSÉ — le cœur pur de la décision.
 *
 * ── L'INCIDENT (production, 2026-09-23) ─────────────────────────────────────
 *
 * « Ket n'écoute pas en live sur le serveur de prod. » Même code qu'en
 * développement, mêmes clés, mêmes en-têtes : on appuie sur Live, et il ne se
 * passe rien. Aucun message, aucune erreur visible.
 *
 * Le flux que le mode Live ouvre avec `getUserMedia` ne sert QU'À DEUX CHOSES :
 * entendre l'utilisateur couper Ket pendant qu'elle parle, et juger de la
 * provenance d'un son (ket-live-tri). La reconnaissance du navigateur, elle, a sa
 * PROPRE captation et n'en a aucun besoin — le projet le savait déjà, puisqu'il
 * abandonne délibérément ce flux sur téléphone pour lui rendre le micro.
 *
 * Et pourtant, un refus de `getUserMedia` arrêtait la SESSION ENTIÈRE, avec un
 * `console.warn` que personne ne lit. Le panneau se refermait, emportant la seule
 * ligne capable d'expliquer pourquoi.
 *
 * POURQUOI LA PRODUCTION SEULE EN SOUFFRAIT. La permission du micro est accordée
 * PAR ORIGINE. Sur le poste de développement, elle l'est depuis longtemps pour
 * `127.0.0.1` ; sur un domaine de production, c'est une première demande — et il
 * suffit de la fermer d'un clic, ou que le micro soit pris par une autre
 * application, pour retomber ici. Même code, comportement opposé.
 *
 * ── LA RÈGLE ────────────────────────────────────────────────────────────────
 *
 * On renonce au flux, on DIT pourquoi, et la conversation continue si la
 * reconnaissance du navigateur peut entendre. On ne perd que la détection
 * d'interruption et le tri par provenance. On n'arrête que lorsque plus rien ne
 * peut entendre — et là encore, en le disant.
 */

/**
 * Pourquoi le flux d'analyse n'a pas pu s'ouvrir, en français.
 *
 * Les clés sont les noms des `DOMException` de `getUserMedia`. `TypeError` y
 * figure parce que `navigator.mediaDevices` n'existe tout simplement PAS hors
 * contexte sécurisé : l'appel échoue alors avant d'avoir commencé, et la cause
 * réelle est l'absence de HTTPS — c'est d'ailleurs le premier soupçon à lever
 * quand un poste marche et un serveur non.
 */
export const CAUSES_MICRO = {
    NotAllowedError: 'Le micro est refusé pour ce site. Autorisez-le depuis la barre d’adresse du navigateur, puis relancez le mode Live.',
    NotFoundError: 'Aucun micro n’a été détecté sur cet appareil.',
    NotReadableError: 'Le micro est déjà pris par une autre application.',
    OverconstrainedError: 'Aucun micro ne correspond aux réglages demandés.',
    SecurityError: 'Le micro n’est accessible que sur une connexion sécurisée (https).',
    TypeError: 'Le micro n’est accessible que sur une connexion sécurisée (https).',
};

/** Ce qu'on dit quand le navigateur ne nomme pas sa cause, ou en nomme une inconnue. */
export const CAUSE_INCONNUE = 'Le micro n’a pas pu être ouvert sur cet appareil.';

/**
 * Ce qu'il faut faire d'un refus de micro.
 *
 * @param {string|null|undefined} nomDErreur le `name` de l'exception levée par getUserMedia
 * @param {string} oreille 'navigateur' si la reconnaissance du navigateur existe ici, 'serveur' sinon
 *
 * @returns {{raison: string, continuer: boolean}} la phrase à afficher, et si la session survit
 */
export function renoncementAuMicro(nomDErreur, oreille) {
    return {
        raison: CAUSES_MICRO[nomDErreur] ?? CAUSE_INCONNUE,
        // La reconnaissance du navigateur entend sans nous. Les oreilles du serveur,
        // elles, transcrivent CE QUE NOUS LEUR ENVOYONS : sans flux, elles n'ont rien
        // à transcrire, et la session n'a plus aucun moyen d'entendre.
        continuer: oreille === 'navigateur',
    };
}
