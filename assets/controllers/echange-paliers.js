/**
 * LE PILOTAGE D'UN IMPORT QUI AVANCE PAR PALIERS.
 *
 * ── POURQUOI C'EST UN MODULE À PART ─────────────────────────────────────────────────
 * ⚠ PARCE QU'IL DÉCIDE, ET QUE CE QUI DÉCIDE DOIT SE TESTER. Ce fichier porte les seules
 * règles de la boucle : quand pousser, quand se contenter de regarder, et à partir de
 * quand conclure que plus rien n'avance. Enfouies dans un contrôleur Stimulus, elles
 * n'étaient atteignables qu'avec un navigateur — c'est-à-dire jamais.
 *
 * Il ne connaît ni `fetch`, ni le DOM, ni Stimulus : on lui passe des fonctions, il rend
 * un état. C'est le même parti que `echange-perimetre-persiste.js`.
 *
 * ── L'ÉTAT VIENT DU SERVEUR, ET LUI SEUL ────────────────────────────────────────────
 * Chaque réponse porte `travaille`, `curseur`, `total`, `pct` et `async`. Rien n'est
 * deviné ici : ni le pourcentage, ni la fin, ni le mode. Le navigateur n'est qu'un
 * pousseur, et le serveur reste seul juge de ce qui a été fait.
 */

/**
 * Combien de temps laisser au worker entre deux « où en es-tu ? ».
 *
 * Assez court pour que la barre paraisse vivante, assez long pour qu'un palier ait le
 * temps d'avancer : sonder plus vite ne rendrait pas le travail plus rapide, seulement
 * plus bruyant.
 */
export const PAUSE_DE_SCRUTIN_MS = 1500;

/** Paliers d'affilée sans la moindre ligne traitée avant de conclure au blocage. */
export const PALIERS_IMMOBILES_TOLERES = 3;

/**
 * Sondages d'affilée sans progression avant de conclure qu'aucun worker n'écoute.
 *
 * Plus permissif que ci-dessus, et pour une bonne raison : en asynchrone, un sondage qui
 * ne voit rien bouger est NORMAL — le palier est peut-être en cours. Ce qui ne l'est pas,
 * c'est que rien ne bouge pendant une minute entière.
 */
export const SCRUTINS_IMMOBILES_TOLERES = 40;

export const MOTIF_ABANDON =
    "Le traitement s'est interrompu sans rendre la main. Redéposez le fichier : "
    + 'les lignes déjà écrites ne seront pas recréées.';

export const MOTIF_SANS_WORKER =
    'Aucun traitement de fond ne semble prendre cet import en charge. '
    + "Prévenez votre administrateur : le worker d'arrière-plan est peut-être arrêté.";

export const MOTIF_IMMOBILE =
    'Le traitement ne progresse plus. Redéposez le fichier : les lignes déjà '
    + 'écrites ne seront pas recréées.';

/**
 * MÈNE UN TRAVAIL D'IMPORT JUSQU'À SON TERME.
 *
 * ⚠ DEUX FAÇONS D'AVANCER, ET C'EST LE SERVEUR QUI DIT LAQUELLE.
 *   `async: false` — c'est nous qui poussons : une requête par palier.
 *   `async: true`  — un worker travaille ; on se contente de regarder.
 *
 * L'appelant fournit ce qui touche au monde extérieur :
 *   `avancer(etat)`  → demande un palier de plus, rend le nouvel état ;
 *   `lire(etat)`     → demande seulement où l'on en est ;
 *   `publier(etat)`  → affiche l'avancement (facultatif) ;
 *   `patienter(ms)`  → laisse le worker travailler (facultatif : une vraie attente).
 *
 * @param {object} etatInitial l'état rendu par la requête qui a lancé le travail
 * @param {{avancer: Function, lire: Function, publier?: Function, patienter?: Function}} rouages
 *
 * @returns {Promise<object>} l'état final
 * @throws {Error} quand le travail cesse de progresser — avec un motif qui dit quoi faire
 */
export async function menerAuBout(etatInitial, rouages) {
    const { avancer, lire, publier, patienter } = rouages;
    const attendre = patienter ?? ((ms) => new Promise((r) => { setTimeout(r, ms); }));

    let etat = etatInitial;
    publier?.(etat);

    let immobile = 0;

    while (etat?.travaille) {
        // ⚠ UN PALIER COMMENCÉ QUI N'A JAMAIS RENDU LA MAIN. Le serveur le sait — il a
        // posé une date en prenant son verrou, et personne ne l'a retirée. Continuer à
        // sonder ne le ressusciterait pas.
        if (etat.abandonne) {
            throw new Error(MOTIF_ABANDON);
        }

        const avant = etat.curseur ?? 0;

        if (etat.async) {
            await attendre(PAUSE_DE_SCRUTIN_MS);
            etat = await lire(etat);
        } else {
            etat = await avancer(etat);
        }

        publier?.(etat);

        // ⚠ UNE BOUCLE QUI N'AVANCE PAS DOIT S'ARRÊTER ET LE DIRE. Sans worker en marche,
        // un import basculé en asynchrone attendrait un réveil qui ne vient pas, et
        // l'écran tournerait indéfiniment sur une barre immobile.
        immobile = (etat?.curseur ?? 0) > avant ? 0 : immobile + 1;

        const tolere = etat?.async ? SCRUTINS_IMMOBILES_TOLERES : PALIERS_IMMOBILES_TOLERES;
        if (immobile >= tolere) {
            throw new Error(etat?.async ? MOTIF_SANS_WORKER : MOTIF_IMMOBILE);
        }
    }

    return etat;
}
