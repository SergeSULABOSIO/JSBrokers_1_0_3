/**
 * LA PASTILLE DE COMPTE D'UN ONGLET (`.jsb-onglet-compte`) — un seul comportement partout.
 *
 * Deux gestes, et rien d'autre :
 *   - marquerEnChargement(pastille) : le nombre arrive — une pulsation douce le dit, et un
 *     lecteur d'écran l'apprend (`aria-busy`) ;
 *   - poserLeCompte(pastille, n)    : le nombre DÉFILE de sa valeur affichée jusqu'à `n`,
 *     puis la pastille marque le coup d'un léger rebond. Rien ne bouge si la valeur ne
 *     change pas ; tout est instantané pour qui a demandé moins de mouvement.
 *
 * Ce module ne décide JAMAIS d'afficher ou de cacher une pastille, ni de ce qu'elle annonce
 * aux lecteurs d'écran : chaque appelant garde sa règle (les onglets d'un dialogue n'en
 * montrent pas à zéro ; un onglet contextuel en montre une dès sa pose). Il n'écrit que le
 * CHIFFRE — le premier nœud texte —, si bien que le mot masqué « élément(s) » qu'un appelant
 * accroche à la suite reste intact.
 */

const DUREE_MS = 400;

/**
 * La valeur affichée à l'instant `t` (0 → 1) d'un défilement de `de` à `a` : entière,
 * décélérée en fin de course (ease-out cubique), exacte aux deux bouts.
 */
export function valeurAuTemps(de, a, t) {
    if (t <= 0) return de;
    if (t >= 1) return a;
    const progression = 1 - (1 - t) ** 3;

    return Math.round(de + (a - de) * progression);
}

/** Le nombre que la pastille affiche (son chiffre seul), 0 s'il n'y en a pas. */
export function nombreAffiche(pastille) {
    return parseInt(chiffreDe(pastille).textContent, 10) || 0;
}

/** Le nœud texte qui porte le chiffre — créé en tête s'il manque. */
function chiffreDe(pastille) {
    const premier = pastille.firstChild;
    if (premier && premier.nodeType === 3) return premier;
    const noeud = document.createTextNode('');
    pastille.insertBefore(noeud, premier);

    return noeud;
}

function mouvementReduit() {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

export function marquerEnChargement(pastille) {
    if (!pastille) return;
    pastille.classList.add('is-chargement');
    pastille.setAttribute('aria-busy', 'true');
}

export function poserLeCompte(pastille, n) {
    if (!pastille) return;
    pastille.classList.remove('is-chargement');
    pastille.removeAttribute('aria-busy');

    const chiffre = chiffreDe(pastille);
    const de = nombreAffiche(pastille);
    cancelAnimationFrame(pastille._compteAnimation);
    if (de === n || mouvementReduit()) {
        chiffre.textContent = String(n);
        return;
    }

    const debut = performance.now();
    const pas = (maintenant) => {
        const t = (maintenant - debut) / DUREE_MS;
        chiffre.textContent = String(valeurAuTemps(de, n, t));
        if (t < 1) {
            pastille._compteAnimation = requestAnimationFrame(pas);
            return;
        }
        // Le rebond, rejoué à chaque changement : on retire la classe avant de la reposer.
        pastille.classList.remove('vient-de-changer');
        void pastille.offsetWidth;
        pastille.classList.add('vient-de-changer');
    };
    pastille._compteAnimation = requestAnimationFrame(pas);
}
