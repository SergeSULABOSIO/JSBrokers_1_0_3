/**
 * L'ÉTAT DES CASES D'UN ARBRE DE SUPPRESSION — règle pure, sans DOM ni réseau.
 *
 * ── CE QUI EST PROTÉGÉ ICI ──────────────────────────────────────────────────────
 * L'arbre promet un arbitrage : décocher une ligne pour la garder. Or la base refuse
 * qu'une échéance existe sans sa proposition, ou une proposition sans son affaire. Sur
 * quatre des cinq niveaux, « je garde cette ligne » ne peut vouloir dire qu'une chose :
 * « alors je renonce à supprimer ce qui la porte ».
 *
 * ⚠ LA FAUTE À CRAINDRE EST DE PROMETTRE CE QUE LE SERVEUR REFUSERA. Une case qui se
 * décoche sans rien remonter laisse croire qu'on épargne une échéance ; à l'exécution, ou
 * bien elle part quand même, ou bien tout échoue. Les deux sont des mensonges.
 *
 * La règle est donc ASYMÉTRIQUE, et c'est voulu :
 *  - décocher un maillon STRUCTUREL décoche ses ancêtres — la remontée est une
 *    CONSÉQUENCE, que l'utilisateur voit se produire ;
 *  - recocher ne remonte JAMAIS — ce serait une INTENTION qu'il n'a pas exprimée, et
 *    ressusciterait l'affaire qu'il venait d'épargner.
 *
 * Lancement : node --test tests/js/
 */

export const STRUCTUREL = 'structurel';
export const DETACHABLE = 'detachable';
export const VERROUILLE = 'verrouille';

/** Le nœud qui coiffe une sélection multiple. Il n'existe qu'à l'écran. */
export const CLE_SELECTION = 'Selection#0';

/**
 * Réunit les arbres de plusieurs éléments sélectionnés en une seule forêt.
 *
 * Le geste de suppression a toujours accepté une sélection multiple : il fallait donc que
 * la boîte sache montrer plusieurs dossiers. Un seul élément garde son propre arbre, tel
 * quel — c'est le cas courant, et lui coiffer une racine artificielle ajouterait un niveau
 * qui ne dit rien.
 *
 * ⚠ LA RACINE DE SÉLECTION N'EST PAS UN LOT, ET N'EN SERA JAMAIS UN. Elle ne correspond à
 * aucune ligne en base : elle sert à cocher tout d'un geste, rien de plus. `lots()` la
 * traverse sans jamais la soumettre.
 *
 * @param {object[]} projections
 */
export function fusionnerProjections(projections) {
    const valides = (projections ?? []).filter((p) => p?.racine?.cle);
    if (valides.length === 0) return { racine: null, noeuds: [], total: 0 };
    if (valides.length === 1) return valides[0];

    const noeuds = [];
    let total = 0;
    let detaches = 0;
    const conservations = [];
    const refus = [];

    for (const projection of valides) {
        total += Number(projection.total) || 0;
        detaches += Number(projection.detaches) || 0;
        conservations.push(...(projection.conservations ?? []));
        refus.push(...(projection.refus ?? []));

        noeuds.push({
            ...projection.racine,
            parent: CLE_SELECTION,
            nature: STRUCTUREL,
            geste: 'detruire',
            verrou: null,
        });
        for (const noeud of projection.noeuds ?? []) {
            noeuds.push(noeud);
        }
    }

    return {
        // Les dessins du pliage viennent du serveur : on garde ceux du premier arbre, ils
        // sont les mêmes pour tous.
        iconesPliage: valides[0].iconesPliage,
        racine: {
            cle: CLE_SELECTION,
            classe: 'Selection',
            libelle: 'Sélection',
            nom: `${valides.length} éléments sélectionnés`,
            total,
        },
        total,
        detaches,
        tronque: valides.some((p) => p.tronque === true),
        noeuds,
        conservations: [...new Set(conservations)],
        refus: [...new Set(refus)],
    };
}

/**
 * Indexe la projection du serveur. Les nœuds arrivent EN LARGEUR, parent avant enfants :
 * un seul passage suffit donc, sans nœud en attente.
 *
 * @param {object} projection  ce que rend `/admin/suppression/arbre/{rubrique}/{id}`
 * @returns {{parCle: Map<string, object>, enfants: Map<string, string[]>, racine: string, total: number}}
 */
export function construireArbre(projection) {
    const parCle = new Map();
    const enfants = new Map();
    const racineBrute = projection?.racine ?? null;
    const racine = racineBrute?.cle ?? null;

    if (racine) {
        parCle.set(racine, {
            cle: racine,
            parent: null,
            classe: racineBrute.classe,
            libelle: racineBrute.libelle,
            nom: racineBrute.nom,
            nature: STRUCTUREL,
            geste: 'detruire',
            total: Number(racineBrute.total) || 0,
        });
    }

    for (const noeud of projection?.noeuds ?? []) {
        parCle.set(noeud.cle, { ...noeud, total: Number(noeud.total) || 0 });
        const parent = parCle.has(noeud.parent) ? noeud.parent : racine;
        if (!enfants.has(parent)) enfants.set(parent, []);
        enfants.get(parent).push(noeud.cle);
    }

    return { parCle, enfants, racine, total: Number(projection?.total) || 0 };
}

/**
 * L'état de départ : TOUT est coché, sauf ce qui est verrouillé — et ce qui porte un
 * verrou est épargné avec ses ancêtres, puisqu'on ne pourra pas le supprimer.
 *
 * @returns {Map<string, boolean>}
 */
export function etatInitialDeLArbre(arbre) {
    const etat = new Map();
    for (const cle of arbre.parCle.keys()) {
        etat.set(cle, true);
    }
    for (const [cle, noeud] of arbre.parCle) {
        if (noeud.geste === VERROUILLE) {
            basculerLeNoeud(arbre, etat, cle, false);
        }
    }

    return etat;
}

/**
 * Coche ou décoche un nœud, et applique les conséquences.
 *
 * @param {boolean} coche
 * @returns {Map<string, boolean>} le même état, muté
 */
export function basculerLeNoeud(arbre, etat, cle, coche) {
    const noeud = arbre.parCle.get(cle);
    if (!noeud) return etat;

    // ⚠ UN VERROU NE SE COCHE PAS. Ce n'est pas une préférence : la base refusera.
    if (noeud.geste === VERROUILLE && coche) return etat;

    descendre(arbre, etat, cle, coche);

    // ⚠ LA REMONTÉE N'A LIEU QU'AU DÉCOCHAGE, ET QUE SUR UN MAILLON STRUCTUREL.
    // Une pièce jointe se garde seule : la décocher ne renonce à rien. Une échéance, non.
    if (!coche && noeud.nature !== DETACHABLE) {
        let parent = noeud.parent;
        let garde = 0;
        while (parent && arbre.parCle.has(parent) && garde++ < 50) {
            etat.set(parent, false);
            parent = arbre.parCle.get(parent).parent;
        }
    }

    return etat;
}

/** Applique un état à tout le sous-arbre : on ne supprime pas un enfant dont le parent reste. */
function descendre(arbre, etat, cle, coche) {
    const noeud = arbre.parCle.get(cle);
    if (!noeud) return;
    if (!(noeud.geste === VERROUILLE && coche)) {
        etat.set(cle, coche);
    }
    for (const enfant of arbre.enfants.get(cle) ?? []) {
        descendre(arbre, etat, enfant, coche);
    }
}

/**
 * L'état visuel d'une case : `'true'`, `'false'` ou `'mixed'`.
 *
 * ⚠ IL EST DÉRIVÉ À CHAQUE RENDU, JAMAIS STOCKÉ. Un tri-état mémorisé finit toujours par
 * diverger de ce que l'arbre contient réellement, et il affiche alors « partiel » sur une
 * branche entièrement cochée.
 */
export function etatDe(arbre, etat, cle) {
    const fils = arbre.enfants.get(cle) ?? [];
    const propre = etat.get(cle) === true;
    if (fils.length === 0) {
        return propre ? 'true' : 'false';
    }

    let tousVrais = propre;
    let tousFaux = !propre;
    for (const enfant of fils) {
        const sous = etatDe(arbre, etat, enfant);
        if (sous !== 'true') tousVrais = false;
        if (sous !== 'false') tousFaux = false;
    }
    if (tousVrais) return 'true';
    if (tousFaux) return 'false';

    return 'mixed';
}

/**
 * L'élément sélectionné dont ce nœud dépend — la racine RÉELLE, jamais celle de sélection.
 *
 * C'est elle qui nomme la route d'exécution : chaque dossier est planifié et exécuté chez
 * lui, dans sa propre transaction.
 */
export function racineDe(arbre, cle) {
    let courant = cle;
    let garde = 0;
    while (courant && arbre.parCle.has(courant) && garde++ < 50) {
        const parent = arbre.parCle.get(courant).parent;
        if (parent === null || parent === CLE_SELECTION) return courant;
        courant = parent;
    }

    return null;
}

/** Le poids PROPRE d'un nœud : 1 pour une ligne, son volume pour un paquet, 0 pour un verrou. */
function poidsPropre(arbre, cle) {
    const noeud = arbre.parCle.get(cle);
    if (!noeud || noeud.geste === VERROUILLE) return 0;
    if (cle === CLE_SELECTION) return 0; // elle ne correspond à aucune ligne en base

    let desEnfants = 0;
    for (const enfant of arbre.enfants.get(cle) ?? []) {
        desEnfants += arbre.parCle.get(enfant)?.total ?? 0;
    }

    return Math.max(0, noeud.total - desEnfants);
}

/**
 * Ce qui part, ce qui reste, et le total — EN OBJETS, jamais en lignes affichées. Un
 * paquet de 247 lignes de facture compte pour 247, pas pour 1.
 *
 * @returns {{retenus: number, conserves: number, total: number, verrous: number}}
 */
export function compterLesObjets(arbre, etat) {
    let retenus = 0;
    let total = 0;
    let verrous = 0;

    for (const [cle, noeud] of arbre.parCle) {
        if (noeud.geste === VERROUILLE) {
            verrous += 1;
            continue;
        }
        const poids = poidsPropre(arbre, cle);
        total += poids;
        if (etat.get(cle) === true) retenus += poids;
    }

    return { retenus, conserves: total - retenus, total, verrous };
}

/**
 * Les SOUS-RACINES à soumettre : les nœuds cochés les plus hauts.
 *
 * ⚠ ON NE SOUMET PAS UNE LIGNE, ON SOUMET UNE RACINE. Un article ne peut pas partir sans
 * son échéance — il n'existe pas de transaction qui n'emporte que lui. La seule maille que
 * le serveur sait honorer est celle dont il sait faire un plan.
 *
 * @returns {string[]} racine seule si tout est coché
 */
export function lots(arbre, etat) {
    const sortie = [];
    const file = arbre.racine ? [arbre.racine] : [];

    while (file.length > 0) {
        const cle = file.shift();
        const noeud = arbre.parCle.get(cle);
        if (!noeud || noeud.geste === VERROUILLE) continue;

        // La racine de sélection ne correspond à aucune ligne : on la traverse toujours.
        if (cle === CLE_SELECTION) {
            for (const enfant of arbre.enfants.get(cle) ?? []) {
                file.push(enfant);
            }
            continue;
        }

        if (etat.get(cle) === true) {
            // ⚠ UNE PIÈCE N'EST JAMAIS UN LOT À ELLE SEULE. Épargner une échéance épargne
            // l'affaire entière : le document qui pendait de sa proposition reste coché, mais
            // personne n'a jamais demandé de le supprimer LUI — il ne devait partir que comme
            // conséquence. Le soumettre seul effacerait une pièce d'un dossier qu'on vient
            // justement de renoncer à supprimer. Pour effacer une pièce seule, il y a la
            // corbeille de la liste.
            if (noeud.nature === DETACHABLE) continue;

            sortie.push(cle);
            continue; // maximal : inutile de descendre, tout le sous-arbre part avec lui
        }
        for (const enfant of arbre.enfants.get(cle) ?? []) {
            file.push(enfant);
        }
    }

    return sortie;
}

/**
 * Les pièces DÉTACHABLES décochées sous une branche qui part : elles survivent, leur lien
 * coupé. Une pièce décochée sous une branche elle-même épargnée n'a rien à signaler.
 *
 * @returns {Record<string, number[]>} classe => identifiants
 */
export function conserver(arbre, etat) {
    const aGarder = {};
    const partantes = new Set();
    for (const lot of lots(arbre, etat)) {
        marquerLeSousArbre(arbre, lot, partantes);
    }

    for (const [cle, noeud] of arbre.parCle) {
        if (noeud.nature !== DETACHABLE || etat.get(cle) === true) continue;
        // Le parent part-il ? Sinon, la pièce n'est menacée par rien.
        if (!partantes.has(noeud.parent)) continue;

        const id = Number(String(cle).split('#')[1]);
        if (!Number.isFinite(id)) continue;
        (aGarder[noeud.classe] ??= []).push(id);
    }

    return aGarder;
}

function marquerLeSousArbre(arbre, cle, vus) {
    if (vus.has(cle)) return;
    vus.add(cle);
    for (const enfant of arbre.enfants.get(cle) ?? []) {
        marquerLeSousArbre(arbre, enfant, vus);
    }
}

/**
 * Le chemin lisible d'un nœud, de la racine jusqu'à lui.
 *
 * Le séparateur est le MÊME que celui des collections différées (`collection-tampon.js`) :
 * deux écrans qui montrent une généalogie doivent parler la même langue.
 */
export function chemin(arbre, cle) {
    const morceaux = [];
    let courant = cle;
    let garde = 0;

    while (courant && arbre.parCle.has(courant) && garde++ < 50) {
        const noeud = arbre.parCle.get(courant);
        morceaux.unshift(noeud.nom || `${noeud.libelle} · ${noeud.elements ?? ''}`.trim());
        courant = noeud.parent;
    }

    return morceaux.join(' › ');
}
