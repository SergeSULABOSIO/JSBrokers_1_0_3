/**
 * LE TAMPON DES COLLECTIONS — un ARBRE de saisies en attente, gardé en mémoire de page.
 *
 * Cœur PUR : aucune dépendance, ni Stimulus, ni DOM, ni réseau. Il est donc exerçable par
 * `node --test` (même patron que confirmation-armement.js à côté de son contrôleur).
 *
 * ── LE PROBLÈME ─────────────────────────────────────────────────────────────────────
 * Une collection est bâtie sur l'id de sa fiche parente (`/admin/piste/api/{id}/cotations`).
 * Tant que le parent n'existe pas, elle était donc inerte : il fallait enregistrer, rouvrir,
 * puis saisir. Or les formulaires de ce projet s'emboîtent sans limite — une piste porte des
 * cotations, une cotation porte des avenants et des documents.
 *
 * ── LE CHOIX ────────────────────────────────────────────────────────────────────────
 * Faire vivre l'attente EN BASE (un parent « brouillon » à id temporaire) aurait contaminé
 * toute l'application : les agrégats financiers traversent le graphe objet, ce qu'aucun
 * filtre applicatif n'intercepte ; et chaque écriture est facturée, si bien qu'ouvrir un
 * dialogue serait devenu payant. On garde donc l'attente ICI, et on la rejoue APRÈS
 * l'enregistrement de l'ancêtre, chaque niveau recevant l'id que le niveau du dessus vient
 * d'obtenir. Abandonner ne laisse alors rien à nettoyer — par construction, pas par un
 * ramassage qui pourrait ne jamais tourner.
 *
 * ── DEUX NATURES, ET UNE SEULE RÉCURSE ──────────────────────────────────────────────
 *   « creation »     : une saisie complète (FormData, fichiers compris) qui portera ses
 *                      propres sous-collections. C'est elle qui fait la généalogie.
 *   « rattachement » : le choix d'une entité qui EXISTE DÉJÀ, au catalogue. Elle a sa
 *                      propre descendance en base : la rattacher n'ouvre aucun sous-arbre.
 */

/** Un groupe = une collection d'un dialogue, et le champ par lequel ses éléments visent leur parent. */
export function creerGroupe(parentFieldName) {
    return { parentFieldName, noeuds: [], prochaineCle: 1 };
}

/** Empile un nœud et lui donne sa clé locale (stable le temps de la saisie). */
export function ajouter(groupe, noeud) {
    const inscrit = { ...noeud, cle: groupe.prochaineCle++ };
    groupe.noeuds.push(inscrit);

    return inscrit;
}

/**
 * Retire un nœud — ET TOUT SON SOUS-ARBRE.
 *
 * Retirer une cotation en attente emporte ses avenants et ses documents : ils n'avaient
 * d'existence que par elle. Les garder en ferait des orphelins que le rejeu ne saurait
 * rattacher à rien.
 */
export function retirer(groupe, cle) {
    const avant = groupe.noeuds.length;
    groupe.noeuds = groupe.noeuds.filter((n) => n.cle !== cle);

    return groupe.noeuds.length !== avant;
}

/** Nombre total d'éléments en attente, descendants COMPRIS — le chiffre du garde-fou. */
export function compter(groupe) {
    if (!groupe || !Array.isArray(groupe.noeuds)) return 0;

    return groupe.noeuds.reduce(
        (total, noeud) => total + 1 + Object.values(noeud.enfants ?? {}).reduce((s, g) => s + compter(g), 0),
        0,
    );
}

/**
 * Les libellés en attente, chemin compris — ce que la confirmation d'abandon doit montrer.
 * « Cotation SUNU › offre.pdf » dit à l'utilisateur CE QU'IL PERD, là où un simple nombre
 * le laisserait deviner.
 */
export function libellesEnAttente(groupe, prefixe = []) {
    if (!groupe || !Array.isArray(groupe.noeuds)) return [];

    return groupe.noeuds.flatMap((noeud) => {
        const chemin = [...prefixe, noeud.libelle];
        const sous = Object.values(noeud.enfants ?? {}).flatMap((g) => libellesEnAttente(g, chemin));

        return [chemin.join(' › '), ...sous];
    });
}

/**
 * Le libellé d'une ligne en attente, déduit de la saisie elle-même.
 *
 * On ne peut pas demander au serveur de rendre la ligne enrichie du canevas : l'entité
 * n'existe pas encore. On prend donc le premier champ qui NOMME la chose, dans l'ordre où
 * un humain la nommerait — et à défaut le titre du formulaire, jamais une chaîne vide qui
 * produirait une ligne muette.
 */
export function libelleDepuisFormData(formData, repli = 'Élément') {
    for (const champ of ['nom', 'libelle', 'description', 'reference', 'code']) {
        const valeur = formData?.get?.(champ);
        if (typeof valeur === 'string' && valeur.trim() !== '') return valeur.trim();
    }

    return repli;
}

/**
 * Arme la saisie avec l'id que son parent vient d'obtenir.
 *
 * ⚠ `set`, JAMAIS `append` : après un rejeu partiellement échoué, l'utilisateur relance —
 * et `append` aurait alors empilé deux valeurs pour le même champ parent, dont le serveur
 * ne retiendrait qu'une, au hasard de l'ordre.
 */
export function preparerRejeu(noeud, parentFieldName, parentId) {
    noeud.formData.set(parentFieldName, String(parentId));

    return noeud.formData;
}

/**
 * LE REJEU, en profondeur d'abord et EN SÉRIE.
 *
 * En série parce que chaque niveau a besoin de l'id que le niveau du dessus vient de rendre :
 * paralléliser reviendrait à rattacher des enfants à un parent qui n'existe pas encore.
 * `executer` est injecté — c'est ce qui garde ce module pur, donc testable sans navigateur.
 *
 * Un échec n'interrompt pas ses frères : on le note avec son CHEMIN COMPLET et on continue.
 * L'utilisateur doit savoir exactement ce qui n'est pas passé, pas seulement que « ça a raté ».
 *
 * @param {{parentFieldName: string, noeuds: Array}} groupe
 * @param {number|string} parentId
 * @param {(noeud: object, charge: FormData|null, parentId: number|string) => Promise<{ok: boolean, id?: number, erreur?: any}>} executer
 * @returns {Promise<Array<{chemin: string[], erreur: any}>>} les échecs, vide si tout est passé
 */
export async function rejouerGroupe(groupe, parentId, executer) {
    const echecs = [];
    if (!groupe || !Array.isArray(groupe.noeuds)) return echecs;

    for (const noeud of groupe.noeuds) {
        const charge = noeud.nature === 'creation'
            ? preparerRejeu(noeud, groupe.parentFieldName, parentId)
            : null;

        const resultat = await executer(noeud, charge, parentId);
        if (!resultat || !resultat.ok) {
            echecs.push({ chemin: [noeud.libelle], erreur: resultat?.erreur });
            continue;
        }

        // Un rattachement désigne une entité déjà en base, avec sa propre descendance :
        // il n'ouvre aucun sous-arbre. C'est ce qui borne naturellement la récursion.
        if (noeud.nature !== 'creation') continue;

        for (const sousGroupe of Object.values(noeud.enfants ?? {})) {
            const sousEchecs = await rejouerGroupe(sousGroupe, resultat.id, executer);
            echecs.push(...sousEchecs.map((e) => ({ ...e, chemin: [noeud.libelle, ...e.chemin] })));
        }
    }

    return echecs;
}

/**
 * CE QUE COÛTERAIT UNE FERMETURE, tous groupes d'un même dialogue confondus.
 *
 * Un dialogue porte souvent plusieurs collections (documents, cotations, tâches…) :
 * la question ne se pose pas collection par collection, mais pour la boîte entière.
 * On rend le nombre ET les libellés, parce qu'annoncer « 3 éléments seront perdus »
 * sans dire lesquels oblige l'utilisateur à deviner ce qu'il abandonne.
 */
export function resumeDeFermeture(groupes) {
    const liste = (groupes ?? []).filter(Boolean);
    const nombre = liste.reduce((total, groupe) => total + compter(groupe), 0);

    return {
        doitConfirmer: nombre > 0,
        nombre,
        libelles: liste.flatMap((groupe) => libellesEnAttente(groupe)),
    };
}

/** Vide un groupe — l'abandon assumé, après confirmation. */
export function vider(groupe) {
    if (groupe) groupe.noeuds = [];
}
