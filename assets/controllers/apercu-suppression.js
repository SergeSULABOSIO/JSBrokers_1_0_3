/**
 * @file CE QUI VA PARTIR, DIT AVANT DE LE FAIRE.
 *
 * La boîte de confirmation annonçait « Vous êtes sur le point de supprimer définitivement
 * 1 élément(s) ». Or effacer une opportunité emporte ses propositions, ses polices, ses
 * échéances, ses commissions, sa facture et son règlement. On ne peut pas demander à
 * quelqu'un de valider ce qu'on lui cache.
 *
 * Le serveur sait exactement ce qu'il va détruire — c'est le MÊME plan qu'il exécutera
 * ensuite. Ce module ne fait que le résumer en lignes lisibles.
 *
 * Module PUR : ni DOM, ni réseau. Le geste réseau est injecté.
 */

/**
 * Au-delà, on renonce à l'aperçu plutôt que de faire attendre.
 *
 * ⚠ CE PLAFOND N'EST PAS UNE LIMITE DE SUPPRESSION : la boucle, elle, traite tous les
 * éléments. C'est l'ANNONCE qui s'efface, parce qu'une liste de cinquante portées ne se
 * lit pas et que cinquante requêtes avant d'ouvrir une boîte se voient.
 */
export const APERCUS_MAX = 20;

/** La rubrique d'une URL de suppression : « /admin/piste/api/delete » → « piste ». */
export function rubriqueDeSuppression(url) {
    const trouve = /\/admin\/([a-z_]+)\/api\/delete/i.exec(String(url || ''));

    return trouve ? trouve[1].toLowerCase() : null;
}

/**
 * Résume plusieurs aperçus en lignes lisibles.
 *
 * ⚠ LES PORTÉES SE CUMULENT PAR NATURE. Supprimer trois affaires qui portent chacune deux
 * propositions doit annoncer « 6 Propositions », pas trois fois « 2 Propositions » : c'est
 * le total qui dit l'ampleur du geste.
 *
 * @param {Array<{portee?: Array<{entite: string, libelle: string, count: number}>, conservations?: string[], refus?: string[]}>} apercus
 * @returns {{lignes: string[], bloquant: boolean}}
 */
export function resumerLaPortee(apercus) {
    const parNature = new Map();
    const conservations = [];
    const refus = [];

    (apercus || []).forEach((apercu) => {
        (apercu?.portee || []).forEach((nature) => {
            const nombre = Number(nature?.count) || 0;
            if (nombre <= 0) return;
            const libelle = nature?.libelle || nature?.entite || '';
            parNature.set(libelle, (parNature.get(libelle) || 0) + nombre);
        });
        (apercu?.conservations || []).forEach((c) => conservations.push(c));
        (apercu?.refus || []).forEach((r) => refus.push(r));
    });

    const lignes = [];
    parNature.forEach((nombre, libelle) => {
        lignes.push(`${nombre} ${libelle} seront supprimés avec.`);
    });

    return {
        lignes: [...lignes, ...dedoublonner(conservations), ...dedoublonner(refus)],
        bloquant: refus.length > 0,
    };
}

function dedoublonner(valeurs) {
    return [...new Set(valeurs.filter(Boolean))];
}

/**
 * Interroge le serveur pour chaque élément sélectionné et rend le résumé.
 *
 * Les échecs sont ignorés SANS BRUIT : un aperçu indisponible ne doit pas empêcher de
 * supprimer, ni faire croire à une panne. La suppression, elle, dira ce qui bloque.
 *
 * @param {object} options
 * @param {string} options.url URL de suppression du canevas (« /admin/piste/api/delete »)
 * @param {Array<number|string>} options.ids
 * @param {(url: string) => Promise<object|null>} options.lire
 * @returns {Promise<{lignes: string[], bloquant: boolean}>}
 */
export async function annoncerLaPortee({ url, ids, lire }) {
    const rubrique = rubriqueDeSuppression(url);
    const liste = Array.isArray(ids) ? ids : [];
    if (rubrique === null || liste.length === 0 || liste.length > APERCUS_MAX) {
        return { lignes: [], bloquant: false };
    }

    const apercus = await Promise.all(liste.map(async (id) => {
        try {
            return await lire(`/admin/suppression/apercu/${rubrique}/${id}`);
        } catch {
            return null;
        }
    }));

    return resumerLaPortee(apercus.filter(Boolean));
}
