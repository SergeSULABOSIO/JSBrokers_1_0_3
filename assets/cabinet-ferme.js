/**
 * Ramène l'utilisateur au choix d'espace quand son cabinet s'est fermé sous ses pieds.
 *
 * ── POURQUOI CE FICHIER EXISTE ──────────────────────────────────────────────
 * Côté serveur, le gardien `CabinetActif` ferme le cabinet dès que l'`Invite` qui
 * l'autorisait disparaît — une révocation, puisqu'il n'existe aucun état de
 * révocation : on supprime la ligne. `CabinetFermeListener` répond alors **409 avec
 * `{ redirect }`** aux requêtes qui attendent du JSON, et une redirection 302 aux
 * autres.
 *
 * Le 409 existe précisément pour ne PAS rediriger une requête asynchrone : une
 * redirection suivie par `fetch` ferait injecter une page entière dans un panneau de
 * l'espace de travail — un symptôme illisible pour une cause simple. Mais il faut alors
 * que quelqu'un, côté navigateur, fasse le voyage. Sans ce fichier, le panneau
 * afficherait une erreur muette et l'utilisateur resterait devant un écran mort.
 *
 * ── POURQUOI GLOBAL, ET NON DANS CHAQUE CONTRÔLEUR ──────────────────────────
 * Quarante-quatre fichiers de `assets/controllers/` appellent `fetch`, trente et un
 * utilisent `XMLHttpRequest`. Y répéter le même test, c'est se donner autant
 * d'occasions de l'oublier — et l'oubli ne se verrait que le jour d'une révocation,
 * chez un utilisateur, sans trace. Le projet a déjà ce motif pour les mêmes raisons :
 * `veille-erreurs.js` et `autocomplete-dropdown-global.js`.
 *
 * ── LES DEUX TRANSPORTS ─────────────────────────────────────────────────────
 * `fetch` ET `XMLHttpRequest` sont couverts. Ne couvrir que `fetch` aurait laissé
 * trente et un fichiers sur le bord du chemin, dont les listes de collection.
 *
 * ── MÊME ORIGINE, DANS LES DEUX SENS ────────────────────────────────────────
 * Deux vérifications, et aucune n'est décorative :
 *
 *  1. La RÉPONSE doit venir de chez nous. Un service tiers peut répondre 409 pour ses
 *     propres raisons (un conflit d'enregistrement, une ressource verrouillée) et
 *     servir un corps JSON où figure un champ `redirect`. Sans ce contrôle, un tiers
 *     déciderait où va l'utilisateur.
 *
 *  2. La DESTINATION doit être interne. Même si la réponse vient de nous, on ne suit
 *     qu'un chemin relatif ou une URL de notre propre origine. Une redirection ouverte
 *     se prête à l'hameçonnage : l'adresse affichée reste la nôtre jusqu'au saut.
 *
 * ── CE QUE CE FICHIER NE FAIT PAS ───────────────────────────────────────────
 * Il ne modifie ni la requête, ni la réponse : il lit un statut, et laisse la réponse
 * poursuivre son chemin intacte. Le contrôleur appelant continue ce qu'il faisait ; la
 * navigation l'interrompra d'elle-même.
 *
 * C'est la présence de `redirect` qui tranche, jamais le statut seul.
 */

/** La destination est-elle chez nous ? Un chemin relatif, ou notre propre origine. */
function destinationInterne(adresse) {
    if (typeof adresse !== 'string' || adresse === '') {
        return false;
    }
    // `//evil.tld` est un chemin « relatif au protocole » : il part ailleurs.
    if (adresse.startsWith('//')) {
        return false;
    }
    if (adresse.startsWith('/')) {
        return true;
    }
    try {
        return new URL(adresse, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
}

/** La réponse vient-elle de chez nous ? Une URL vide signifie « même document ». */
function reponseInterne(url) {
    if (typeof url !== 'string' || url === '') {
        return true;
    }
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
}

function naviguerSiCestNous(url, corpsBrut) {
    if (!reponseInterne(url)) {
        return;
    }
    try {
        const charge = JSON.parse(corpsBrut);
        if (charge && destinationInterne(charge.redirect)) {
            window.location.assign(charge.redirect);
        }
    } catch {
        // Corps absent ou non-JSON : ce 409 n'est pas le nôtre, on ne s'en mêle pas.
    }
}

if (!window.__cabinetFermeInstalle) {
    window.__cabinetFermeInstalle = true;

    const fetchOriginal = window.fetch;
    if (typeof fetchOriginal === 'function') {
        window.fetch = async function (...arguments_) {
            const reponse = await fetchOriginal.apply(this, arguments_);

            if (reponse.status === 409) {
                // CLONER : lire le corps le consomme, et l'appelant en a besoin intact.
                try {
                    naviguerSiCestNous(reponse.url, await reponse.clone().text());
                } catch {
                    // Réponse opaque ou déjà consommée : rien à lire, rien à faire.
                }
            }

            return reponse;
        };
    }

    const envoiOriginal = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (...arguments_) {
        this.addEventListener('loadend', () => {
            try {
                if (this.status !== 409) {
                    return;
                }

                // LE CORPS SE LIT SELON LE TYPE DEMANDÉ, SOUS PEINE DE LEVER.
                //
                // `responseText` n'est lisible QUE pour `''` et `'text'` : sur un
                // `blob`, un `arraybuffer` ou un `document`, la seule lecture de la
                // propriété jette une `InvalidStateError` — et comme nous sommes dans
                // l'écouteur d'une requête qui ne nous appartient pas, cette exception
                // casserait le traitement de l'appelant pour une réponse qui ne nous
                // concernait même pas.
                //
                // `json` est servi déjà décodé par `response`, et jamais par
                // `responseText`. Les types binaires ne portent pas de redirection :
                // on les ignore.
                let charge = null;
                if (this.responseType === 'json') {
                    charge = this.response;
                } else if (this.responseType === '' || this.responseType === 'text') {
                    charge = this.responseText;
                }

                if (charge === null || charge === undefined) {
                    return;
                }

                // `responseURL` est l'adresse FINALE, redirections suivies : c'est elle
                // qui dit d'où vient réellement la réponse.
                naviguerSiCestNous(
                    this.responseURL,
                    typeof charge === 'string' ? charge : JSON.stringify(charge),
                );
            } catch {
                // Rien de ce que nous faisons ici ne doit retomber sur l'appelant.
            }
        });

        return envoiOriginal.apply(this, arguments_);
    };
}
