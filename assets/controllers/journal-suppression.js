/**
 * LE JOURNAL D'UNE SUPPRESSION — ce qui est parti, ce qui a résisté, et en quels mots.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────
 * Un échec au milieu d'une file ne l'interrompt pas : les lots suivants partent, et
 * l'utilisateur doit pouvoir expliquer après coup ce qui s'est passé — y compris à
 * quelqu'un qui n'était pas devant l'écran. C'est le journal qui porte cette preuve.
 *
 * ⚠ LA FAUTE À CRAINDRE EST UN BILAN QUI MENT PAR ARRONDI : annoncer « tout a été
 * supprimé » quand deux lots ont échoué, ou noyer trois refus dans un compteur. Chaque
 * échec garde son motif, et le bilan les compte à part.
 *
 * ⚠ ET LE PLAFOND N'EST PAS DU CONFORT. Le journal est annoncé en `aria-live` : mille
 * insertions rendent un lecteur d'écran inutilisable. Au-delà de 200 lignes, on montre les
 * plus récentes et on DIT combien manquent.
 *
 * Lancement : node --test tests/js/
 */

export const LIGNES_VISIBLES_MAX = 200;

/** @returns {{entrees: object[], detruits: number, detaches: number}} */
export function creerJournal() {
    return { entrees: [], detruits: 0, detaches: 0 };
}

/** Un lot commence : la ligne existe AVANT son issue, pour que l'attente se voie. */
export function ouvrirLot(journal, cle, nom) {
    const existante = journal.entrees.find((e) => e.cle === cle);
    if (existante) {
        existante.etat = 'en-cours';
        existante.motif = null;

        return journal;
    }
    journal.entrees.push({ cle, nom: nom || cle, etat: 'en-cours', detruits: 0, detaches: 0, motif: null });

    return journal;
}

export function terminerLot(journal, cle, { detruits = 0, detaches = 0 } = {}) {
    const entree = journal.entrees.find((e) => e.cle === cle);
    if (!entree) return journal;

    entree.etat = 'fait';
    entree.detruits = detruits;
    entree.detaches = detaches;
    journal.detruits += detruits;
    journal.detaches += detaches;

    return journal;
}

export function echouerLot(journal, cle, nom, motif) {
    const entree = journal.entrees.find((e) => e.cle === cle);
    const texte = (motif || '').trim() || "Cette partie du dossier n'a pas pu être supprimée.";
    if (entree) {
        entree.etat = 'echec';
        entree.motif = texte;

        return journal;
    }
    journal.entrees.push({ cle, nom: nom || cle, etat: 'echec', detruits: 0, detaches: 0, motif: texte });

    return journal;
}

/**
 * Ce qu'on affiche, et ce qu'on avoue ne pas afficher.
 *
 * ⚠ LES ÉCHECS PASSENT DEVANT. Ce sont eux qu'on vient lire : les noyer à la fin d'une
 * liste tronquée reviendrait à les cacher.
 *
 * @returns {{lignes: object[], caches: number}}
 */
export function lignesVisibles(journal, { max = LIGNES_VISIBLES_MAX } = {}) {
    const echecs = journal.entrees.filter((e) => e.etat === 'echec');
    const autres = journal.entrees.filter((e) => e.etat !== 'echec');
    const lignes = [...echecs, ...autres.slice(-Math.max(0, max - echecs.length))];

    return { lignes, caches: Math.max(0, journal.entrees.length - lignes.length) };
}

/** @returns {{detruits: number, detaches: number, echecs: object[], succes: boolean, lots: number}} */
export function bilan(journal) {
    const echecs = journal.entrees.filter((e) => e.etat === 'echec');

    return {
        detruits: journal.detruits,
        detaches: journal.detaches,
        echecs,
        succes: echecs.length === 0,
        lots: journal.entrees.length,
    };
}

/**
 * Le bilan en une phrase — en français juste.
 *
 * ⚠ ON N'ÉCRIT JAMAIS « 1 éléments ». Un accord fautif dans la phrase qui conclut une
 * suppression définitive donne l'impression d'un message fabriqué, et fait douter du reste.
 */
export function phraseDuBilan(journal) {
    const { detruits, detaches, echecs, succes } = bilan(journal);
    const morceaux = [];

    morceaux.push(detruits <= 1 ? `${detruits} objet supprimé` : `${detruits} objets supprimés`);
    if (detaches > 0) {
        morceaux.push(detaches <= 1 ? '1 lien coupé' : `${detaches} liens coupés`);
    }
    if (!succes) {
        morceaux.push(echecs.length <= 1 ? '1 partie a résisté' : `${echecs.length} parties ont résisté`);
    }

    return `${morceaux.join(' · ')}.`;
}

/** Les clés des lots à rejouer : la reprise groupée, c'est la même route avec cette liste. */
export function lotsARejouer(journal) {
    return journal.entrees.filter((e) => e.etat === 'echec').map((e) => e.cle);
}
