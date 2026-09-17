/**
 * Cœur PUR du mode Live : LES INTERMÈDES, ces petites phrases que Ket dit pendant
 * qu'il réfléchit (« Hum… laissez-moi vérifier. »).
 *
 * Elles ne disent rien du métier — le catalogue vit côté serveur
 * (App\Ai\Live\IntermedesDeKet) et le navigateur ne manipule que des CLÉS. Ici, on ne
 * décide que du RYTHME : quand parler, quoi choisir, et quand se taire.
 *
 * Deux règles tiennent tout :
 *  - une réponse rapide ne mérite aucun intermède (parler pour ne rien dire est pire
 *    qu'un court silence) ;
 *  - on ne répète pas la même phrase dans une même attente.
 */

/** Sous ce délai, Ket a répondu assez vite : on se tait. */
export const AVANT_PREMIER_MS = 600;

/** Espacement des relances tant que la réflexion dure. */
export const ENTRE_RELANCES_MS = 9000;

/** Au-delà, mieux vaut laisser le silence que meubler sans fin. */
export const RELANCES_MAX = 3;

/**
 * Le programme d'une attente : à quels instants parler, et avec quel moment du
 * catalogue. Rendu une fois pour toute l'attente, donc testable d'un coup d'œil.
 *
 * @returns {{delaiMs: number, moment: 'debut'|'relance'}[]}
 */
export function programmeDesIntermedes({ avantPremierMs = AVANT_PREMIER_MS, entreRelancesMs = ENTRE_RELANCES_MS, relancesMax = RELANCES_MAX } = {}) {
    const etapes = [{ delaiMs: avantPremierMs, moment: 'debut' }];
    for (let i = 1; i <= relancesMax; i++) {
        etapes.push({ delaiMs: avantPremierMs + i * entreRelancesMs, moment: 'relance' });
    }

    return etapes;
}

/**
 * Choisit une clé du moment demandé, sans reprendre celles déjà dites pendant cette
 * attente. Quand tout a été dit, on repart du catalogue complet, en évitant seulement
 * la toute dernière phrase.
 *
 * @param {Record<string, Record<string, string>>} catalogue moment => clé => phrase
 * @param {string[]} dejaDits clés déjà utilisées
 * @param {() => number} hasard injectable pour les tests
 * @returns {string|null}
 */
export function choisir(catalogue, moment, dejaDits = [], hasard = Math.random) {
    // Le serveur n'envoie que des CLÉS (liste) ; les tests décrivent parfois le catalogue
    // entier (clé => phrase). Les deux formes désignent le même choix.
    const entree = catalogue?.[moment] ?? {};
    const cles = Array.isArray(entree) ? entree.slice() : Object.keys(entree);
    if (cles.length === 0) return null;

    const dernier = dejaDits[dejaDits.length - 1] ?? null;
    let candidats = cles.filter((cle) => !dejaDits.includes(cle));
    if (candidats.length === 0) {
        candidats = cles.filter((cle) => cle !== dernier);
    }
    if (candidats.length === 0) candidats = cles;

    return candidats[Math.floor(hasard() * candidats.length) % candidats.length];
}
