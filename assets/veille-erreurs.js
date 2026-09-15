/**
 * Remonte au serveur les erreurs JavaScript non rattrapées.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Une erreur PHP laisse une trace dans var/log ; une erreur JavaScript ne laisse
 * RIEN. Elle s'affiche dans la console du navigateur de l'utilisateur, qui la
 * referme et contourne le problème sans rien dire. Les 148 `console.error` des
 * contrôleurs de ce projet n'ont jamais quitté le poste de personne.
 *
 * ── LE PIÈGE QUE RIEN NE SIGNALE : `window.onerror`, PAS `addEventListener` ──
 * Stimulus AVALE ses propres exceptions. Dans `Application.handleError`, toute
 * erreur d'un `connect()`, d'un `initialize()` ou d'une action est interceptée,
 * journalisée en console… puis rappelée explicitement sur `window.onerror` —
 * et sur lui SEUL. Un `window.addEventListener('error', …)` ne verrait donc
 * jamais passer le code des contrôleurs, c'est-à-dire l'essentiel de
 * l'application. On AFFECTE donc `window.onerror`, en chaînant le gestionnaire
 * précédent s'il en existait un.
 *
 * ── TROIS GARDE-FOUS, SANS LESQUELS CE FICHIER NUIT ────────────────────────
 * 1. Le FILTRE. Sur un site réel, l'écrasante majorité des erreurs remontées ne
 *    vient pas de notre code : extensions de navigateur, scripts tiers,
 *    « Script error. » d'origine croisée sans le moindre détail. Les laisser
 *    passer noierait les vraies dans un bruit qu'on cesserait vite de lire.
 * 2. Le PLAFOND par page. Une boucle de rendu fautive produirait des milliers
 *    d'erreurs identiques ; le serveur n'a pas à les recevoir toutes.
 * 3. L'ANTI-RÉCURSION. Si l'envoi du rapport échoue, son `catch` ne doit
 *    surtout pas produire un rapport — ce serait une boucle qui s'auto-alimente
 *    à la vitesse du réseau.
 */

/** Ce que le serveur ne doit jamais recevoir, et pourquoi. */
const BRUIT_CONNU = [
    // Erreur d'origine croisée : le navigateur masque tout par sécurité et ne
    // laisse que ces deux mots. Inexploitable — ni fichier, ni ligne, ni trace.
    'Script error.',
    'Script error',
    // Bruit de navigateur, pas un défaut : se produit sur des pages
    // parfaitement saines et n'a aucune conséquence visible.
    'ResizeObserver loop completed with undelivered notifications',
    'ResizeObserver loop limit exceeded',
];

/** Sources qui ne sont pas notre code — donc pas notre problème. */
const SOURCES_ETRANGERES = [
    'chrome-extension://',
    'moz-extension://',
    'safari-web-extension://',
    'safari-extension://',
    'webkit-masked-url:',
    'about:blank',
];

/**
 * Faut-il remonter cette erreur ?
 *
 * Fonction PURE, exportée pour être éprouvée sans navigateur — c'est la
 * convention des tests de `tests/js/`. Un filtre qu'on ne peut pas tester est
 * un filtre dont on ne sait pas ce qu'il laisse passer.
 *
 * @param {{message?: string, fichier?: string}} erreur
 * @param {string} origine  l'origine du site, ex. « https://www.joseara.com »
 * @returns {boolean}
 */
export function meriteUnRapport(erreur, origine) {
    const message = (erreur && erreur.message) || '';
    const fichier = (erreur && erreur.fichier) || '';

    if (message.trim() === '') {
        return false;
    }

    if (BRUIT_CONNU.some((bruit) => message.includes(bruit))) {
        return false;
    }

    if (SOURCES_ETRANGERES.some((prefixe) => fichier.startsWith(prefixe))) {
        return false;
    }

    // Un fichier absent arrive pour les erreurs levées depuis une chaîne
    // évaluée ou un gestionnaire en ligne : on garde, le message reste utile.
    if (fichier === '') {
        return true;
    }

    // Tout ce qui vient d'un autre domaine : CDN, régie, script injecté. Les
    // chemins relatifs, eux, sont forcément les nôtres.
    if (/^https?:\/\//i.test(fichier)) {
        return fichier.startsWith(origine);
    }

    return true;
}

/** Au-delà, on se tait : voir le garde-fou n°2. */
const PLAFOND_PAR_PAGE = 5;

let envoyes = 0;
let enCoursDEnvoi = false;

/**
 * Envoie un rapport, au plus une fois de trop.
 *
 * `keepalive` permet à la requête de survivre au déchargement de la page :
 * beaucoup d'erreurs surviennent pendant une navigation, et le rapport serait
 * sinon abandonné au moment précis où il compte.
 */
function rapporter(erreur) {
    if (envoyes >= PLAFOND_PAR_PAGE || enCoursDEnvoi) {
        return;
    }

    if (!meriteUnRapport(erreur, window.location.origin)) {
        return;
    }

    envoyes += 1;
    enCoursDEnvoi = true;

    try {
        fetch('/api/journal/navigateur', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                type: erreur.type,
                message: erreur.message,
                fichier: erreur.fichier,
                ligne: erreur.ligne,
                colonne: erreur.colonne,
                trace: erreur.trace,
                page: window.location.href,
                navigateur: navigator.userAgent,
            }),
            keepalive: true,
        })
            .catch(() => {
                // GARDE-FOU 3 : silence absolu. Signaler l'échec d'un
                // signalement nous ramènerait exactement ici.
            })
            .finally(() => {
                enCoursDEnvoi = false;
            });
    } catch (e) {
        enCoursDEnvoi = false;
    }
}

/** Détaille une valeur levée, qui n'est pas toujours une Error. */
function decrire(valeur, repli) {
    if (valeur instanceof Error) {
        return {
            type: valeur.name || 'Error',
            message: valeur.message || String(valeur),
            trace: valeur.stack || null,
        };
    }

    return {
        type: 'Erreur',
        message: typeof valeur === 'string' ? valeur : repli,
        trace: null,
    };
}

/**
 * Installe la veille. Appelé une seule fois, au chargement du module.
 */
export function installerLaVeille() {
    // On AFFECTE window.onerror (voir le commentaire d'en-tête), tout en
    // chaînant l'éventuel gestionnaire précédent : on observe, on ne confisque
    // pas.
    const precedent = window.onerror;

    window.onerror = function (message, source, ligne, colonne, erreur) {
        try {
            const detail = decrire(erreur, typeof message === 'string' ? message : 'Erreur inconnue');

            rapporter({
                type: detail.type,
                message: detail.message,
                fichier: source || '',
                ligne: ligne || null,
                colonne: colonne || null,
                trace: detail.trace,
            });
        } catch (e) {
            // Ne jamais laisser la veille casser la page qu'elle observe.
        }

        if (typeof precedent === 'function') {
            return precedent.apply(this, arguments);
        }

        return false; // false : le navigateur garde son comportement normal.
    };

    // Les promesses rejetées ne passent pas par window.onerror. Sans cette
    // seconde écoute, tout le code asynchrone — donc tous les appels au
    // serveur — resterait invisible.
    window.addEventListener('unhandledrejection', (evenement) => {
        try {
            const detail = decrire(evenement.reason, 'Promesse rejetée sans motif');

            rapporter({
                type: detail.type === 'Erreur' ? 'PromesseRejetee' : detail.type,
                message: detail.message,
                fichier: '',
                ligne: null,
                colonne: null,
                trace: detail.trace,
            });
        } catch (e) {
            // Idem : la veille ne doit jamais nuire.
        }
    });
}

// L'installation est conditionnée à l'existence d'un navigateur, pour que le
// FILTRE reste importable ailleurs — par « node --test », notamment. Un filtre
// qui exige un DOM pour être chargé est un filtre qu'on n'éprouve jamais, et on
// finit par ignorer ce qu'il laisse passer.
if (typeof window !== 'undefined') {
    installerLaVeille();
}
