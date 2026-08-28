import { conteneurVisible } from './visibilite-conditions.js';

/**
 * LES RÈGLES PURES DES CHIPS DE FILTRE RAPIDE, d'après les critères courants de la liste.
 *
 * Trois décisions vivent ici, et aucune ne touche au DOM :
 *   — `etatChipPreset`   : ce chip est-il ACTIF, et que doit-il annoncer ?
 *   — `chipVisible`      : ce chip a-t-il sa place à l'écran, vu les autres filtres ?
 *   — `resoudreClicChip` : que deviennent les critères après un clic ?
 *
 * Deux chips d'une même barre peuvent se contredire — « Type : Agent » et
 * « Bénéficiaire : SUNU Courtage (partenaire) » donnent une liste NÉCESSAIREMENT vide.
 * Les faire se parler est une décision, pas un rendu : elle s'éprouve donc ici, sous
 * `node --test`, sans navigateur.
 */

/**
 * L'ÉTAT D'UN CHIP DE FILTRE RAPIDE, d'après les critères courants de la liste.
 *
 * Deux familles de chips coexistent, et elles ne se lisent pas de la même façon :
 *
 *  — le chip à VALEUR (« Sans pièce », « Ce mois ») est actif quand le critère porte SA
 *    valeur ; l'option vide (« Tous ») l'est quand le critère est absent ;
 *  — le chip-SÉLECTEUR (« Choisir un agent… ») ne porte aucune valeur : il va les chercher
 *    au clic. Le comparer à une valeur absente le rendait actif quand RIEN n'était filtré —
 *    en même temps que « Tous » —, et il ne nommait jamais l'agent retenu. Un filtre qu'on
 *    ne peut pas lire est un filtre qu'on croit absent.
 *
 * La règle vit ici, hors du contrôleur, pour être éprouvée sans DOM : c'est une décision,
 * pas un rendu.
 */

/**
 * @param {{valeurAttendue: string|null, estSelecteur: boolean, libelleDefaut: string,
 *         prefixe?: string}} chip - `prefixe` n'est posé que lorsqu'un même critère
 *        accepte plusieurs FAMILLES de valeurs (bénéficiaire agent / partenaire).
 * @param {{value?: *, label?: string}|string|number|null|undefined} critere - le critère
 *        courant pour la CLÉ de ce chip (tel que stocké par le cerveau).
 * @returns {{actif: boolean, libelle: string|null}} `libelle` null = ne pas y toucher.
 */
export function etatChipPreset(chip, critere) {
    const valeur = (critere && typeof critere === 'object')
        ? String(critere.value ?? '')
        : String(critere ?? '');

    if (!chip.estSelecteur) {
        return { actif: valeur === String(chip.valeurAttendue ?? ''), libelle: null };
    }

    // Un sélecteur est actif dès qu'une valeur est posée, et il l'ANNONCE : le libellé du
    // critère (le nom de l'agent) s'il y en a un, la valeur brute à défaut — mieux vaut un
    // identifiant qu'un intitulé qui ment.
    if (valeur === '') {
        return { actif: false, libelle: chip.libelleDefaut };
    }

    // DEUX SÉLECTEURS PEUVENT PARTAGER UNE CLÉ. Le bénéficiaire d'un reversement est un
    // agent OU un partenaire : deux colonnes, un seul filtre, donc deux chips côte à côte.
    // Sans ce test, choisir un agent allumait AUSSI « Choisir un partenaire… » et le
    // faisait porter le nom de l'agent — un filtre qui ment sur ce qu'il filtre.
    if (chip.prefixe) {
        if (!valeur.startsWith(`${chip.prefixe}:`)) {
            return { actif: false, libelle: chip.libelleDefaut };
        }
    }

    const libelle = (critere && typeof critere === 'object' && critere.label) ? critere.label : valeur;

    return { actif: true, libelle: String(libelle) };
}

// ─────────────────────────────────────────────────────────────────────────────────────
// LA COHÉRENCE ENTRE CHIPS D'UNE MÊME BARRE
// ─────────────────────────────────────────────────────────────────────────────────────
//
// Trois énoncés, et aucun ne nomme une rubrique : ce sont les CANEVAS qui déclarent.
//
//   R1 — VISIBILITÉ  : une option paraît si ses conditions sont remplies par les critères
//                      courants (grammaire de `visibilite-conditions.js`, celle des ~39
//                      dialogues : rien de nouveau à apprendre).
//   R2 — RETRAIT     : un critère dont la valeur ne peut plus être affichée par aucune
//                      option AUTORISÉE de son groupe est retiré. C'est l'invariant maison
//                      « un filtre qu'on ne peut pas lire est un filtre qu'on croit
//                      absent », rendu exécutoire.
//   R3 — IMPLICATION : une option déclare les autres critères que son choix implique ; ils
//                      sont posés du MÊME geste, donc en une seule recherche.

/**
 * La valeur d'un critère sous forme de chaîne — vide quand il est absent.
 *
 * L'ABSENCE EST UNE RÉPONSE, pas un silence : c'est ce qui permet d'écrire une condition
 * `in ['', 'agent']` (« aucun filtre de type, ou agent ») sans ajouter d'opérateur `empty`
 * à un moteur que 39 dialogues partagent.
 */
function valeurDuCritere(criteres, cle) {
    const critere = (criteres || {})[cle];
    if (critere === undefined || critere === null) return '';

    return (typeof critere === 'object') ? String(critere.value ?? '') : String(critere);
}

/**
 * Ce chip peut-il AFFICHER cette valeur ?
 *
 * Un chip à valeur n'affiche que la sienne ; un sélecteur préfixé n'affiche que sa famille ;
 * un sélecteur sans préfixe affiche tout ce qui vient sous sa clé. C'est la règle qui
 * allume déjà le chip dans `etatChipPreset` — extraite pour que R2 s'en serve au lieu de la
 * recopier, deux copies ayant tôt fait de désigner deux sous-ensembles.
 */
export function chipPorteLaValeur(chip, valeur) {
    if (valeur === '') return false;

    if (!chip.estSelecteur) {
        return valeur === String(chip.valeurAttendue ?? '');
    }

    return chip.prefixe ? valeur.startsWith(chip.prefixe + ':') : true;
}

/**
 * R1 STRICTE : ce chip est-il AUTORISÉ par ses conditions ?
 *
 * Sans conditions déclarées, un chip est toujours autorisé — c'est ce qui laisse les
 * rubriques existantes (les quatre axes des Tranches, les statuts des Cotations) entièrement
 * hors de portée de ce mécanisme.
 *
 * @param {{conditions?: Array}} chip
 * @param {object} criteres
 */
export function chipAutorise(chip, criteres) {
    const conditions = chip.conditions;
    if (!Array.isArray(conditions) || conditions.length === 0) return true;

    return conteneurVisible(conditions, (cle) => valeurDuCritere(criteres, cle));
}

/**
 * CE CHIP A-T-IL SA PLACE À L'ÉCRAN ?
 *
 * R1, PLUS une échappatoire indispensable : un chip qui affiche un filtre ACTIF reste
 * visible, même si ses conditions ne sont plus remplies.
 *
 * Sans elle, un état restauré au F5 se retournerait contre l'utilisateur : un couple devenu
 * contradictoire entre deux sessions verrait son chip disparaître, et le filtre continuerait
 * d'agir sans que rien ne le dise — précisément le défaut que R2 combat. Après un CLIC,
 * l'échappatoire ne sert jamais : R2 a déjà retiré ce que plus aucun chip autorisé ne
 * pouvait lire. Les deux règles ne se contredisent donc pas, elles se relaient.
 */
export function chipVisible(chip, criteres) {
    return chipAutorise(chip, criteres)
        || chipPorteLaValeur(chip, valeurDuCritere(criteres, chip.cle));
}

/**
 * CE QUE DEVIENNENT LES CRITÈRES APRÈS UN CLIC SUR UN CHIP.
 *
 * Une seule fonction rend les deux choses dont le contrôleur a besoin — les critères
 * RÉSULTANTS (pour l'aperçu immédiat) et la liste des CHANGEMENTS (pour le cerveau). Les
 * calculer séparément, c'était accepter que l'écran et la recherche divergent le temps d'un
 * aller-retour ; et cette divergence-là ne se voit pas, elle se corrige d'elle-même une
 * seconde plus tard.
 *
 * ── POURQUOI R2 NE TOUCHE JAMAIS À CE QUI VIENT D'ÊTRE POSÉ ─────────────────────────
 * Le critère cliqué et ceux qu'il implique sont exclus du retrait. C'est une garde, pas une
 * optimisation : une déclaration mal écrite — une option dont la condition exclut sa propre
 * implication — ferait autrement disparaître le filtre à l'instant où on le pose. Un clic
 * sans effet, et rien à l'écran pour l'expliquer.
 *
 * ── UNE SEULE PASSE SUFFIT ──────────────────────────────────────────────────────────
 * R3 ne fait que POSER des critères. Poser peut rendre des options autorisées ; cela ne
 * peut pas en interdire au point de créer un nouvel orphelin. Aucun aller-retour n'est donc
 * possible, et l'on n'a pas à itérer jusqu'à stabilisation.
 *
 * @param {Array<{cle: string, valeurAttendue?: string, estSelecteur?: boolean,
 *                prefixe?: string, conditions?: Array}>} chips - les déclarations de TOUS
 *        les chips de la barre, telles que le contrôleur les lit dans le DOM.
 * @param {object} criteresCourants
 * @param {{cle: string, valeur: string, libelle?: string,
 *          implique?: Object<string, {value: string, label?: string}>}} clic
 * @returns {{criteres: object, changements: Array<{key: string, value: string, label: string}>}}
 */
export function resoudreClicChip(chips, criteresCourants, clic) {
    const criteres = { ...(criteresCourants || {}) };
    const poses = new Set();

    const poser = (cle, valeur, libelle) => {
        poses.add(cle);
        if (valeur === undefined || valeur === null || valeur === '') {
            delete criteres[cle];
            return;
        }
        criteres[cle] = { operator: '=', value: valeur, label: libelle || String(valeur) };
    };

    // 1. Le geste demandé.
    poser(clic.cle, clic.valeur, clic.libelle);

    // 2. R3 — ce que ce choix implique. Une valeur vide (« Tous ») n'implique rien : on ne
    //    déduit une famille que d'un choix, jamais d'un retrait.
    if (clic.valeur !== '' && clic.implique) {
        Object.entries(clic.implique).forEach(([cle, implique]) => {
            poser(cle, implique.value, implique.label);
        });
    }

    // 3. R2 — les orphelins collatéraux.
    const clesDeChips = new Set((chips || []).map((chip) => chip.cle));
    clesDeChips.forEach((cle) => {
        if (poses.has(cle)) return;

        const valeur = valeurDuCritere(criteres, cle);
        if (valeur === '') return;

        const lisible = (chips || []).some((chip) => chip.cle === cle
            && chipAutorise(chip, criteres)
            && chipPorteLaValeur(chip, valeur));
        if (!lisible) {
            delete criteres[cle];
        }
    });

    // 4. Les changements, DÉDUITS de la différence : un critère retiré voyage avec une
    //    valeur vide, ce que le cerveau lit déjà comme « retire ce critère ».
    const changements = [];
    const toutesLesCles = new Set([
        ...Object.keys(criteresCourants || {}),
        ...Object.keys(criteres),
    ]);
    toutesLesCles.forEach((cle) => {
        const avant = valeurDuCritere(criteresCourants, cle);
        const apres = valeurDuCritere(criteres, cle);
        if (avant === apres) return;

        changements.push({
            key: cle,
            value: apres,
            label: apres === '' ? '' : (criteres[cle].label ?? apres),
        });
    });

    return { criteres, changements };
}
