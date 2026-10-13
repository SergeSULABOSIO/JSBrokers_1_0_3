/**
 * LES RÈGLES DE LA VUE CARTES, HORS DU CONTRÔLEUR.
 *
 * Ni DOM, ni cookie, ni Stimulus : des décisions. C'est ce qui permet à un test de les tenir,
 * et au contrôleur de n'être qu'un câblage.
 */

/** Les deux vues d'une liste qui déclare `vue_cartes`. */
export const VUES = ['cartes', 'liste'];

/**
 * La vue à appliquer, à partir de ce que le cookie contient — n'importe quoi, y compris rien.
 *
 * ⚠ LE DÉFAUT EST « CARTES », ET LE SERVEUR TIENT LA MÊME RÈGLE (ControllerUtilsTrait) : une
 * valeur inconnue — un cookie d'une ancienne version, une main qui l'a modifié — retombe sur
 * la vue par défaut plutôt que de laisser l'écran dans un état qu'aucun bouton ne montre.
 *
 * @param {unknown} valeur
 * @returns {'cartes'|'liste'}
 */
export function vueARestaurer(valeur) {
    return VUES.includes(valeur) ? valeur : 'cartes';
}

/**
 * Le nom du cookie, PAR CABINET : deux cabinets d'un même compte gardent chacun leur choix.
 *
 * @param {number|string} idEntreprise
 * @param {string} entite nom court de l'entité listée (« Piste »)
 */
export function cleDeLaVue(idEntreprise, entite) {
    return `listeVue_${idEntreprise}_${entite}`;
}

/**
 * La chaîne à écrire dans `document.cookie`. Un an, tout le site (le serveur la lit sur le
 * chargement de la rubrique ET sur chaque rafraîchissement), `SameSite=Lax`.
 *
 * ⚠ PAS LE `saveCookie` HISTORIQUE (base_controller) : il préfixe la valeur et écrit une date
 * d'expiration invalide — le serveur aurait lu « 9111986cartes ».
 */
export function cookieDeVue(cle, vue) {
    return `${cle}=${vueARestaurer(vue)}; path=/; max-age=31536000; SameSite=Lax`;
}

/** Lit la vue dans une chaîne `document.cookie`. */
export function vueDuCookie(cookies, cle) {
    const trouve = String(cookies ?? '').split(';').map((c) => c.trim()).find((c) => c.startsWith(`${cle}=`));

    return vueARestaurer(trouve ? decodeURIComponent(trouve.slice(cle.length + 1)) : null);
}

/**
 * Un clic sur une carte bascule sa sélection — SAUF le second clic d'un double-clic, qui
 * l'aurait aussitôt défaite : le double-clic OUVRE la piste, il ne doit pas la décocher.
 *
 * @param {number} detail `event.detail` : le rang du clic dans une rafale
 */
export function clicDeSelection(detail) {
    return !(Number(detail) >= 2);
}

/**
 * Faut-il (re)distribuer les cartes de cette source ?
 *
 * La liste rendue par le serveur s'annonce au démarrage ; le contrôleur, lui, distribue dès sa
 * connexion. Sans marque, la même source serait distribuée deux fois. Un rafraîchissement
 * apporte une NOUVELLE source, sans marque.
 *
 * @param {{dataset?: Record<string, string>}|null} source
 */
export function doitDistribuer(source) {
    return Boolean(source) && source.dataset?.distribue !== 'oui';
}

/**
 * Le message qui suit une action sur une carte, une fois sa fiche fermée.
 *
 * - l'étape a changé            → « Piste passée en … » ;
 * - rien n'a changé, MAIS un enregistrement a eu lieu → « La piste reste … : <raison> » ;
 * - fermée sans rien enregistrer → rien : l'utilisateur n'a rien fait, on ne commente pas.
 *
 * @param {{avant: string, apres: string|null, enregistre: boolean}} geste
 * @param {Record<string, {singulier: string, raison: string}>} etapes
 * @returns {{texte: string, type: string}|null}
 */
export function messageDEtape({ avant, apres, enregistre }, etapes) {
    if (!enregistre || !apres) {
        return null;
    }
    if (apres !== avant) {
        return { texte: `Piste passée en « ${etapes[apres]?.singulier ?? apres} ».`, type: 'success' };
    }
    const etape = etapes[avant];
    const raison = etape?.raison ? ` : ${etape.raison}` : '';

    return { texte: `La piste reste « ${etape?.singulier ?? avant} »${raison}.`, type: 'info' };
}

/**
 * Un dialogue appartient-il à la fiche ouverte depuis une carte ?
 *
 * Oui s'il EST la fiche (sa racine), ou s'il a été ouvert depuis un dialogue déjà suivi :
 * l'élément qui l'a demandé (une collection, `collection-…`) vit dans l'une de leurs modales.
 * C'est ce qui rattache à la carte la cotation ouverte depuis la piste, puis le chargement
 * ou l'avenant saisi depuis cette cotation.
 *
 * @param {{racine?: boolean, dansUnDialogueSuivi?: boolean}} faits
 */
export function dialogueSuivi({ racine = false, dansUnDialogueSuivi = false }) {
    return racine || dansUnDialogueSuivi;
}

/**
 * LA PART DE CHAQUE ÉTAPE DANS LE PIPE — en nombre de pistes, sur les quatre colonnes du
 * filtre actif (les clôturées, annoncées en pied, n'y entrent pas : 100 % = ce qu'on voit).
 *
 * ENTIERS, ET LA SOMME FAIT 100. Arrondir chaque part à part donne 99 ou 101 : on répartit
 * au PLUS FORT RESTE (chaque part reçoit sa partie entière, puis les points manquants vont
 * aux plus grands restes). Une étape NON VIDE qui tomberait à 0 s'annonce « < 1 % » : on ne
 * fait pas disparaître des pistes qui existent — et sa voisine à 100 s'annonce alors
 * « > 99 % », qui reste vrai. Sans aucune piste, aucune part : un « 0 % »
 * partout affirmerait une répartition qui n'existe pas.
 *
 * @param {Record<string, number>} nombres  compteur global de chaque étape
 * @returns {Record<string, {pourcent: number, libelle: string}>|null}
 */
export function partsDuPipe(nombres) {
    const codes = Object.keys(nombres || {});
    const total = codes.reduce((s, c) => s + Math.max(0, Number(nombres[c]) || 0), 0);
    if (total === 0) return null;

    const brutes = codes.map((code) => {
        const exacte = (Math.max(0, Number(nombres[code]) || 0) * 100) / total;
        return { code, entiere: Math.floor(exacte), reste: exacte - Math.floor(exacte) };
    });
    let manquants = 100 - brutes.reduce((s, b) => s + b.entiere, 0);
    [...brutes].sort((a, b) => b.reste - a.reste).forEach((b) => {
        if (manquants > 0 && b.reste > 0) {
            b.entiere += 1;
            manquants -= 1;
        }
    });

    // « 100 % » n'est vrai que si aucune autre étape n'a de piste ; sinon « > 99 % ».
    const etapesNonVides = codes.filter((c) => (Number(nombres[c]) || 0) > 0).length;
    const libelle = (entiere, nonVide) => {
        if (entiere === 0 && nonVide) return '< 1 %';
        if (entiere === 100 && etapesNonVides > 1) return '> 99 %';
        return `${entiere} %`;
    };

    return Object.fromEntries(brutes.map(({ code, entiere }) => [
        code,
        { pourcent: entiere, libelle: libelle(entiere, (Number(nombres[code]) || 0) > 0) },
    ]));
}
