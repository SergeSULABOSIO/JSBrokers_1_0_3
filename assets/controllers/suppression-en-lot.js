/**
 * @file Supprimer PLUSIEURS éléments d'affilée, en sachant ce qui est parti et ce qui a
 * été refusé — et en le disant pour chacun.
 *
 * ⚠ UNE BOUCLE SÉQUENTIELLE, ET NON `Promise.all`. Les suppressions partaient jusqu'ici
 * en parallèle, et l'attente s'arrêtait au PREMIER rejet : sur dix éléments dont trois
 * refusaient de partir, l'utilisateur voyait un seul motif, les sept autres suppressions
 * partaient quand même, et la liste ne se rafraîchissait pas. Il fallait choisir entre
 * tout savoir et ne rien savoir.
 *
 * ⚠ ET LE SÉQUENTIEL IMPORTE AUSSI À LA BASE. Deux dossiers supprimés en même temps
 * feraient travailler deux transactions sur une même facture partagée — celle dont les
 * lignes relèvent des deux.
 *
 * Module PUR : aucune dépendance au DOM ni à Stimulus. Le geste réseau et l'affichage
 * sont injectés, ce qui rend la règle éprouvable sans navigateur.
 */

/**
 * @typedef {object} IssueDuLot
 * @property {number}   reussites     nombre d'éléments réellement supprimés
 * @property {string[]} echecs        un motif NOMMÉ par élément refusé
 * @property {string[]} conservations ce que le serveur a délibérément gardé, et pourquoi
 */

/**
 * @param {object}   options
 * @param {Array<number|string>} options.ids
 * @param {string[]} [options.descriptions] libellés, dans l'ordre des ids
 * @param {(id: (number|string), surProgres: (pct: number, libelle: string) => void) => Promise<object|null>} options.supprimer
 *        effectue la suppression d'UN élément ; `surProgres` est appelé au fil de l'eau.
 * @param {(pct: number, libelle: string) => void} [options.publier] avancement du LOT.
 * @returns {Promise<IssueDuLot>}
 */
export async function supprimerEnLot({ ids, descriptions = [], supprimer, publier = () => {} }) {
    const total = Array.isArray(ids) ? ids.length : 0;
    const issue = { reussites: 0, echecs: [], conservations: [] };
    if (total === 0) return issue;

    const nomDe = (id, rang) => descriptions[rang] || `Élément #${id}`;

    for (let rang = 0; rang < total; rang += 1) {
        const id = ids[rang];
        const nom = nomDe(id, rang);

        publier(
            Math.round((rang / total) * 100),
            total > 1 ? `${rang + 1} sur ${total} — ${nom}` : `Suppression de ${nom}`,
        );

        try {
            const resultat = await supprimer(id, (pct, libelle) => {
                // L'avancement d'UN élément, ramené à la part qui lui revient dans le lot :
                // la barre ne recule jamais, même sur dix suppressions.
                const part = (rang + Math.max(0, Math.min(100, Number(pct) || 0)) / 100) / total;
                publier(Math.round(part * 100), [nom, libelle].filter(Boolean).join(' — '));
            });

            // ⚠ UNE RÉPONSE DIFFUSÉE A DÉJÀ ENVOYÉ SON 200 : le refus ne peut pas voyager
            // en code HTTP, il voyage dans la dernière ligne du flux. La lire est ce qui
            // distingue une suppression réussie d'une suppression qui n'a pas eu lieu.
            if (resultat && resultat.ok === false) {
                throw new Error(resultat.message || `La suppression de ${nom} a échoué.`);
            }

            issue.reussites += 1;
            const gardes = resultat?.rapport?.conservations;
            if (Array.isArray(gardes)) {
                gardes.forEach((garde) => issue.conservations.push(garde));
            }
        } catch (erreur) {
            // Le serveur sait POURQUOI, et on le jetait : « Erreur lors de la suppression
            // de l'élément 117 » remplaçait un message qui disait ce qui bloquait et quoi
            // faire. On garde le sien, préfixé du nom que l'utilisateur reconnaît.
            issue.echecs.push(`${nom} : ${erreur?.message || 'la suppression a échoué.'}`);
        }
    }

    publier(100, 'Terminé');

    return issue;
}

/**
 * Ce qu'il faut afficher au terme du lot : un en-tête, la liste des refus, et s'il faut
 * garder la boîte ouverte pour les lire.
 *
 * ⚠ LA BOÎTE RESTE OUVERTE DÈS QU'UN REFUS EXISTE, et c'est délibéré : elle est la seule
 * surface qui NOMME chaque refus. Un toast dure dix secondes et tient en 350 pixels — de
 * quoi annoncer un échec, jamais d'en expliquer trois.
 *
 * @param {IssueDuLot} issue
 * @returns {{succes: boolean, message: string, motifs: string[], fermer: boolean}}
 */
export function verdictDuLot(issue) {
    const { reussites, echecs, conservations } = issue;

    if (echecs.length === 0) {
        const base = reussites > 1 ? `${reussites} éléments supprimés avec succès.` : 'Élément supprimé avec succès.';

        return { succes: true, message: [base, ...conservations].join(' '), motifs: [], fermer: false };
    }

    if (reussites > 0) {
        return {
            succes: false,
            message: `${reussites} élément(s) supprimé(s), ${echecs.length} refusé(s) :`,
            motifs: echecs,
            fermer: true,
        };
    }

    // Un seul refus se lit mieux en une phrase qu'en une liste d'un élément.
    return echecs.length === 1
        ? { succes: false, message: echecs[0], motifs: [], fermer: true }
        : { succes: false, message: 'Aucune suppression n\'a abouti :', motifs: echecs, fermer: true };
}
