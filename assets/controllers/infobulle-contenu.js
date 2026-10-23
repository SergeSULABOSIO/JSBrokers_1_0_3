/**
 * LE CONTENU DE L'INFOBULLE UNIQUE — règles pures (testées en node), chantier 3.
 *
 * Le serveur DÉCLARE le contenu, en JSON, dans `data-infobulle` (Twig : `|json_encode|e('html_attr')`) :
 *   [{ "titre": "Piste", "lignes": [["Nom", "…"], ["Prime", "1 200,00 $", "tip-montant"]], "texte": "…" }]
 * ou une simple chaîne (le libellé d'un bouton). Ce module le lit et le valide ; le contrôleur
 * `infobulle` pose les rangées par `textContent` : une donnée n'est JAMAIS du HTML.
 *
 * Défensif par construction : un JSON cassé n'affiche rien, une forme inattendue est écartée, une
 * classe hors de la famille `tip-*` (celles d'app.css) est ignorée — le serveur ne peut pas, par
 * mégarde, imposer une classe Bootstrap qui cacherait ou déformerait la bulle.
 */

/** Les seules classes qu'une cellule peut recevoir : la famille `.jsb-ctx-tip .tip-*` d'app.css. */
const CLASSE_PERMISE = /^tip-[a-z][a-z-]*$/;

const texte = (v) => (typeof v === 'string' ? v : typeof v === 'number' || typeof v === 'boolean' ? String(v) : null);
const classe = (v) => (typeof v === 'string' && CLASSE_PERMISE.test(v) ? v : null);

function section(brute) {
    if (brute === null || typeof brute !== 'object' || Array.isArray(brute)) {
        return null;
    }
    const lignes = (Array.isArray(brute.lignes) ? brute.lignes : [])
        .filter((l) => Array.isArray(l) && texte(l[0]) !== null && (l[1] === null || l[1] === undefined || texte(l[1]) !== null))
        .map((l) => [texte(l[0]), texte(l[1]) ?? '—', classe(l[2])]);
    const s = { titre: texte(brute.titre), lignes, texte: texte(brute.texte), classe: classe(brute.classe) };

    return s.titre || s.lignes.length || s.texte ? s : null;
}

/**
 * @param {string|undefined} brut la valeur de `data-infobulle`
 * @returns {Array<{titre: string|null, lignes: Array<[string, string, string|null]>, texte: string|null, classe: string|null}>}
 */
export function lireSections(brut) {
    let donnees;
    try {
        donnees = JSON.parse(brut ?? '');
    } catch {
        return [];
    }
    if (typeof donnees === 'string') {
        return donnees.trim() === '' ? [] : [{ titre: null, lignes: [], texte: donnees, classe: null }];
    }
    if (!Array.isArray(donnees)) {
        return [];
    }

    return donnees.map(section).filter(Boolean);
}

/**
 * Les rangées du tableau de la bulle : titre de section sur deux colonnes, lignes
 * libellé-valeur, texte sur deux colonnes (classe `tip-para` par défaut).
 *
 * @returns {Array<{cellules: Array<{texte: string, classe: string|null, colspan: number}>}>}
 */
export function rangees(sections) {
    const resultat = [];
    for (const s of sections) {
        if (s.titre) {
            resultat.push({ cellules: [{ texte: s.titre, classe: 'tip-section', colspan: 2 }] });
        }
        for (const [libelle, valeur, classeValeur] of s.lignes) {
            resultat.push({ cellules: [
                { texte: libelle, classe: null, colspan: 1 },
                { texte: valeur, classe: classeValeur, colspan: 1 },
            ] });
        }
        if (s.texte) {
            resultat.push({ cellules: [{ texte: s.texte, classe: s.classe ?? 'tip-para', colspan: 2 }] });
        }
    }

    return resultat;
}
